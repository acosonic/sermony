<?php
/**
 * Sermony API / URL Monitor Plugin
 *
 * Monitors HTTP endpoints via GET/POST with optional auth, headers,
 * expected status/text checks. Runs from cron via check.php.
 * Results stored in SQLite with configurable retention.
 */

// ── Database ─────────────────────────────────────────────────

function apiMonDb(): void {
    static $done = false;
    if ($done) return;
    $d = db();
    @$d->exec('CREATE TABLE IF NOT EXISTS api_monitors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        method TEXT NOT NULL DEFAULT "GET",
        request_headers TEXT DEFAULT "",
        request_body TEXT DEFAULT "",
        auth_type TEXT DEFAULT "none",
        auth_token TEXT DEFAULT "",
        auth_username TEXT DEFAULT "",
        auth_password TEXT DEFAULT "",
        api_key_header TEXT DEFAULT "",
        api_key_value TEXT DEFAULT "",
        expected_status INTEGER DEFAULT 200,
        expected_text TEXT DEFAULT "",
        timeout_seconds INTEGER DEFAULT 10,
        interval_minutes INTEGER DEFAULT 5,
        enabled INTEGER NOT NULL DEFAULT 1,
        last_run_at TEXT,
        next_run_at TEXT,
        last_status TEXT DEFAULT "pending",
        last_http_status INTEGER,
        last_response_time_ms INTEGER,
        fail_streak INTEGER DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
    @$d->exec('CREATE TABLE IF NOT EXISTS api_monitor_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        monitor_id INTEGER NOT NULL REFERENCES api_monitors(id) ON DELETE CASCADE,
        checked_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        success INTEGER NOT NULL DEFAULT 0,
        http_status INTEGER,
        response_time_ms INTEGER,
        error_message TEXT DEFAULT "",
        response_excerpt TEXT DEFAULT ""
    )');
    @$d->exec('CREATE INDEX IF NOT EXISTS idx_amr_monitor ON api_monitor_results(monitor_id, checked_at DESC)');
    $done = true;
}

function apiMonSetting(string $key, string $default = ''): string {
    return setting("apimon_$key") ?? $default;
}

// ── Check execution ──────────────────────────────────────────

function apiMonRunCheck(array $mon): array {
    $ch = curl_init();
    $url = $mon['url'];
    $headers = [];

    // Parse custom headers
    $rawHeaders = trim($mon['request_headers'] ?? '');
    if ($rawHeaders !== '') {
        foreach (explode("\n", $rawHeaders) as $h) {
            $h = trim($h);
            if ($h !== '') $headers[] = $h;
        }
    }

    // Auth
    switch ($mon['auth_type'] ?? 'none') {
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . ($mon['auth_token'] ?? '');
            break;
        case 'apikey':
            $hName = $mon['api_key_header'] ?: 'X-API-Key';
            $headers[] = "$hName: " . ($mon['api_key_value'] ?? '');
            break;
        case 'basic':
            curl_setopt($ch, CURLOPT_USERPWD, ($mon['auth_username'] ?? '') . ':' . ($mon['auth_password'] ?? ''));
            break;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => (int)($mon['timeout_seconds'] ?? 10),
        CURLOPT_CONNECTTIMEOUT => min(5, (int)($mon['timeout_seconds'] ?? 10)),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Sermony API Monitor/1.0',
    ]);

    if (strtoupper($mon['method'] ?? 'GET') === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $body = $mon['request_body'] ?? '';
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $startMs = hrtime(true);
    $body = curl_exec($ch);
    $elapsedMs = (int)((hrtime(true) - $startMs) / 1_000_000);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Determine success
    $success = true;
    $error = '';

    if ($curlErr) {
        $success = false;
        $error = $curlErr;
    } elseif ($httpCode !== (int)($mon['expected_status'] ?? 200)) {
        $success = false;
        $error = "Expected HTTP {$mon['expected_status']}, got $httpCode";
    }

    $expectedText = trim($mon['expected_text'] ?? '');
    if ($success && $expectedText !== '' && $body !== false) {
        if (stripos($body, $expectedText) === false) {
            $success = false;
            $error = "Expected text not found in response";
        }
    }

    // Truncate response excerpt (max 500 chars, no secrets)
    $excerpt = '';
    if ($body !== false) {
        $excerpt = mb_substr($body, 0, 500);
        if (mb_strlen($body) > 500) $excerpt .= '...';
    }

    return [
        'success' => $success,
        'http_status' => $httpCode,
        'response_time_ms' => $elapsedMs,
        'error_message' => mb_substr($error, 0, 500),
        'response_excerpt' => $excerpt,
    ];
}

/** SSRF protection: block private/local IPs unless allowed */
function apiMonIsUrlSafe(string $url): bool {
    $allow = apiMonSetting('allow_private_ips', '0');
    if ($allow === '1') return true;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return true; // unresolvable, let curl handle
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) return false;
    return true;
}

// ── Mask secrets for display ─────────────────────────────────

function apiMonMask(string $s): string {
    if (strlen($s) <= 6) return str_repeat('*', strlen($s));
    return substr($s, 0, 3) . str_repeat('*', max(3, strlen($s) - 6)) . substr($s, -3);
}

// ── Plugin definition ────────────────────────────────────────

return [
    'name'    => 'API Monitor',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/api-monitor',

    'hooks' => [

        'header_links' => function () {
            echo '<a href="?action=api-monitors">Monitors</a>';
        },

        'settings_panel' => function () {
            ?>
            <fieldset style="margin-top:1rem">
                <legend>API Monitor</legend>
                <div class="field-row">
                    <label>History Retention (days)
                        <input type="number" name="apimon_retention_days" value="<?=e(apiMonSetting('retention_days', '7'))?>" min="1" max="365" form="settings-form">
                    </label>
                    <label>Allow Private IPs (SSRF)
                        <select name="apimon_allow_private_ips" form="settings-form" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                            <option value="0" <?=apiMonSetting('allow_private_ips','0')==='0'?'selected':''?>>Block (recommended)</option>
                            <option value="1" <?=apiMonSetting('allow_private_ips','0')==='1'?'selected':''?>>Allow</option>
                        </select>
                    </label>
                </div>
            </fieldset>
            <?php
            // Save settings on POST
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                foreach (['apimon_retention_days', 'apimon_allow_private_ips'] as $k) {
                    if (isset($_POST[$k])) setSetting($k, (string)$_POST[$k]);
                }
            }
        },

        'dashboard_top' => function () {
            apiMonDb();
            $d = db();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $res = $d->query("SELECT * FROM api_monitors WHERE enabled=1");
            $total = 0; $ok = 0; $fail = 0; $overdue = 0; $late = 0;
            while ($m = $res->fetchArray(SQLITE3_ASSOC)) {
                $total++;
                if ($m['last_status'] === 'fail') { $fail++; continue; }
                if ($m['next_run_at'] && $m['next_run_at'] < $now) {
                    $overdueThreshold = (int)$m['interval_minutes'] * 120; // 2x interval in seconds
                    $elapsed = time() - strtotime($m['next_run_at']);
                    if ($elapsed > $overdueThreshold) { $overdue++; continue; }
                    $late++;
                }
                if ($m['last_status'] === 'ok') $ok++;
            }
            if ($total === 0) return;
            $issues = $fail + $overdue;
            if ($issues > 0) {
                $color = $fail > 0 ? 'var(--red)' : 'var(--amber)';
                echo '<div style="background:color-mix(in srgb,' . $color . ' 10%,var(--card));border:1px solid ' . $color . ';border-radius:8px;padding:.5rem .75rem;margin-bottom:.5rem;font-size:.82rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">';
                if ($fail) echo '<span class="badge badge-crit">' . $fail . ' FAILING</span>';
                if ($overdue) echo '<span class="badge" style="background:var(--red);color:#fff">' . $overdue . ' OFFLINE</span>';
                if ($late) echo '<span class="badge badge-warn">' . $late . ' LATE</span>';
                echo '<a href="?action=api-monitors" style="font-size:.8rem">API Monitors: ' . $ok . '/' . $total . ' passing</a>';
                echo '</div>';
            }
        },

        'custom_action' => function (string $action) {
            if (!str_starts_with($action, 'api-monitor')) return;
            apiMonDb();
            $d = db();

            // ── JSON API ─────────────────────────────────

            if ($action === 'api-monitors-list') {
                $res = $d->query('SELECT * FROM api_monitors ORDER BY name');
                $monitors = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                    // Mask secrets
                    $r['auth_token'] = $r['auth_token'] ? apiMonMask($r['auth_token']) : '';
                    $r['auth_password'] = $r['auth_password'] ? apiMonMask($r['auth_password']) : '';
                    $r['api_key_value'] = $r['api_key_value'] ? apiMonMask($r['api_key_value']) : '';
                    $monitors[] = $r;
                }
                jsonOut(['monitors' => $monitors, 'server_time' => gmdate('Y-m-d\TH:i:s\Z')]);
            }

            if ($action === 'api-monitors-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');
                $name = trim($in['name'] ?? '');
                $url = trim($in['url'] ?? '');
                if ($name === '' || $url === '') jsonErr('Name and URL required');
                if (!filter_var($url, FILTER_VALIDATE_URL)) jsonErr('Invalid URL');

                $id = (int)($in['id'] ?? 0);
                $fields = [
                    'name' => $name, 'url' => $url,
                    'method' => strtoupper(trim($in['method'] ?? 'GET')) === 'POST' ? 'POST' : 'GET',
                    'request_headers' => trim($in['request_headers'] ?? ''),
                    'request_body' => trim($in['request_body'] ?? ''),
                    'auth_type' => in_array($in['auth_type'] ?? '', ['none','bearer','apikey','basic']) ? $in['auth_type'] : 'none',
                    'expected_status' => max(100, min(599, (int)($in['expected_status'] ?? 200))),
                    'expected_text' => trim($in['expected_text'] ?? ''),
                    'timeout_seconds' => max(1, min(60, (int)($in['timeout_seconds'] ?? 10))),
                    'interval_minutes' => max(1, min(1440, (int)($in['interval_minutes'] ?? 5))),
                    'enabled' => empty($in['enabled']) ? 0 : 1,
                ];

                // Only update secret fields if non-empty (don't overwrite with mask)
                $secretFields = ['auth_token', 'auth_password', 'api_key_value', 'auth_username', 'api_key_header'];
                foreach ($secretFields as $sf) {
                    $val = trim($in[$sf] ?? '');
                    if ($val !== '' && strpos($val, '***') === false) {
                        $fields[$sf] = $val;
                    }
                }

                if ($id > 0) {
                    $sets = []; $i = 0;
                    foreach ($fields as $k => $v) $sets[] = "$k=:v$i";
                    $sets[] = "updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now')";
                    $sql = 'UPDATE api_monitors SET ' . implode(',', $sets) . ' WHERE id=:id';
                    $s = $d->prepare($sql);
                    $i = 0;
                    foreach ($fields as $k => $v) { $s->bindValue(":v$i", $v); $i++; }
                    $s->bindValue(':id', $id, SQLITE3_INTEGER);
                } else {
                    $cols = array_keys($fields);
                    $placeholders = array_map(fn($k) => ":$k", $cols);
                    $s = $d->prepare('INSERT INTO api_monitors (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')');
                    foreach ($fields as $k => $v) $s->bindValue(":$k", $v);
                }
                $s->execute();
                jsonOut(['ok' => true, 'id' => $id > 0 ? $id : (int)$d->lastInsertRowID()]);
            }

            if ($action === 'api-monitors-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) { $s = $d->prepare('DELETE FROM api_monitors WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute(); }
                jsonOut(['ok' => true]);
            }

            if ($action === 'api-monitors-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) {
                    $s = $d->prepare('UPDATE api_monitors SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=:id');
                    $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute();
                }
                jsonOut(['ok' => true]);
            }

            if ($action === 'api-monitors-run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id < 1) jsonErr('Invalid ID');
                $s = $d->prepare('SELECT * FROM api_monitors WHERE id=:id');
                $s->bindValue(':id', $id, SQLITE3_INTEGER);
                $mon = $s->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$mon) jsonErr('Monitor not found');

                if (!apiMonIsUrlSafe($mon['url'])) jsonErr('URL blocked: private/local IP');

                $result = apiMonRunCheck($mon);
                // Save result
                $s = $d->prepare('INSERT INTO api_monitor_results (monitor_id, success, http_status, response_time_ms, error_message, response_excerpt) VALUES (:mid,:ok,:http,:ms,:err,:exc)');
                $s->bindValue(':mid', $id, SQLITE3_INTEGER);
                $s->bindValue(':ok', $result['success'] ? 1 : 0, SQLITE3_INTEGER);
                $s->bindValue(':http', $result['http_status'], SQLITE3_INTEGER);
                $s->bindValue(':ms', $result['response_time_ms'], SQLITE3_INTEGER);
                $s->bindValue(':err', $result['error_message']);
                $s->bindValue(':exc', $result['response_excerpt']);
                $s->execute();
                // Update monitor
                $status = $result['success'] ? 'ok' : 'fail';
                $streak = $result['success'] ? 0 : ((int)($mon['fail_streak'] ?? 0) + 1);
                $s = $d->prepare("UPDATE api_monitors SET last_run_at=strftime('%Y-%m-%dT%H:%M:%SZ','now'), last_status=:st, last_http_status=:http, last_response_time_ms=:ms, fail_streak=:fs WHERE id=:id");
                $s->bindValue(':st', $status); $s->bindValue(':http', $result['http_status'], SQLITE3_INTEGER);
                $s->bindValue(':ms', $result['response_time_ms'], SQLITE3_INTEGER);
                $s->bindValue(':fs', $streak, SQLITE3_INTEGER);
                $s->bindValue(':id', $id, SQLITE3_INTEGER);
                $s->execute();
                $result['status'] = $status;
                $result['fail_streak'] = $streak;
                jsonOut($result);
            }

            if ($action === 'api-monitors-history') {
                $id = (int)($_GET['id'] ?? 0);
                if ($id < 1) jsonErr('Invalid ID');
                $s = $d->prepare('SELECT * FROM api_monitor_results WHERE monitor_id=:id ORDER BY checked_at DESC LIMIT 50');
                $s->bindValue(':id', $id, SQLITE3_INTEGER);
                $res = $s->execute(); $rows = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
                jsonOut(['results' => $rows]);
            }

            // ── Main page ────────────────────────────────

            if ($action === 'api-monitors') {
                pageTop('API Monitors');
                ?>
                <div class="detail-header">
                    <a href="?" class="back">&larr; Dashboard</a>
                    <h1 style="margin:.75rem 0">API Monitors</h1>
                    <div style="display:flex;gap:.5rem;margin-bottom:1rem;align-items:center">
                        <input type="text" id="amSearch" placeholder="Search monitors..." oninput="amFilter(this.value)" style="flex:1;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                        <button onclick="amOpenModal()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">+ Add Monitor</button>
                    </div>
                    <div id="amList">Loading...</div>
                </div>

                <!-- Modal -->
                <div id="amModal" class="am-modal-overlay" style="display:none" onclick="if(event.target===this)amCloseModal()">
                    <div class="am-modal" style="max-width:700px">
                        <div class="am-modal-header">
                            <strong id="amModalTitle">Add Monitor</strong>
                            <button onclick="amCloseModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted)">&times;</button>
                        </div>
                        <div class="am-modal-body">
                            <input type="hidden" id="amId" value="0">
                            <div class="field-row">
                                <label>Name <input type="text" id="amName" placeholder="Production API Health"></label>
                                <label>URL <input type="text" id="amUrl" placeholder="https://api.example.com/health"></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Method
                                    <select id="amMethod" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                                        <option value="GET">GET</option><option value="POST">POST</option>
                                    </select>
                                </label>
                                <label>Expected Status <input type="number" id="amExpStatus" value="200" min="100" max="599"></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Auth Type
                                    <select id="amAuthType" onchange="amToggleAuth()" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                                        <option value="none">None</option>
                                        <option value="bearer">Bearer Token</option>
                                        <option value="apikey">API Key Header</option>
                                        <option value="basic">Basic Auth</option>
                                    </select>
                                </label>
                                <label>Interval (min) <input type="number" id="amInterval" value="5" min="1" max="1440"></label>
                            </div>
                            <div id="amAuthBearer" style="display:none;margin-top:.5rem">
                                <label>Bearer Token <input type="password" id="amToken" placeholder="eyJhbG..."></label>
                            </div>
                            <div id="amAuthApikey" style="display:none;margin-top:.5rem">
                                <div class="field-row">
                                    <label>Header Name <input type="text" id="amApiHeader" placeholder="X-API-Key"></label>
                                    <label>API Key <input type="password" id="amApiValue" placeholder="key_..."></label>
                                </div>
                            </div>
                            <div id="amAuthBasic" style="display:none;margin-top:.5rem">
                                <div class="field-row">
                                    <label>Username <input type="text" id="amBasicUser"></label>
                                    <label>Password <input type="password" id="amBasicPass"></label>
                                </div>
                            </div>
                            <label style="margin-top:.5rem">Custom Headers (one per line)
                                <textarea id="amHeaders" rows="2" placeholder="Content-Type: application/json&#10;Accept: application/json" style="width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text);font-family:monospace"></textarea>
                            </label>
                            <label style="margin-top:.5rem" id="amBodyLabel">POST Body
                                <textarea id="amBody" rows="3" placeholder='{"key":"value"}' style="width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text);font-family:monospace"></textarea>
                            </label>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Expected Text in Response <input type="text" id="amExpText" placeholder="\"status\":\"ok\""></label>
                                <label>Timeout (sec) <input type="number" id="amTimeout" value="10" min="1" max="60"></label>
                            </div>
                            <label style="margin-top:.5rem"><input type="checkbox" id="amEnabled" checked> Enabled</label>
                        </div>
                        <div class="am-modal-footer">
                            <button onclick="amSave()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">Save</button>
                            <button onclick="amCloseModal()" class="btn-secondary" style="padding:.4rem 1rem;font-size:.85rem">Cancel</button>
                        </div>
                    </div>
                </div>

                <!-- History Modal -->
                <div id="amHistModal" class="am-modal-overlay" style="display:none" onclick="if(event.target===this)amCloseHist()">
                    <div class="am-modal" style="max-width:800px">
                        <div class="am-modal-header">
                            <strong id="amHistTitle">History</strong>
                            <button onclick="amCloseHist()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted)">&times;</button>
                        </div>
                        <div class="am-modal-body" id="amHistBody" style="max-height:70vh;overflow-y:auto">Loading...</div>
                    </div>
                </div>

                <style>
                .am-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem}
                .am-modal{background:var(--card);border:1px solid var(--card-border);border-radius:12px;width:100%;max-width:700px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)}
                .am-modal-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid var(--card-border)}
                .am-modal-body{padding:1rem 1.25rem;overflow-y:auto;flex:1}
                .am-modal-footer{display:flex;gap:.5rem;padding:.75rem 1.25rem;border-top:1px solid var(--card-border)}
                .am-card{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:.75rem 1rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
                .am-card:hover{box-shadow:0 2px 8px var(--card-hover)}
                .am-card.am-fail{border-left:3px solid var(--red)}
                .am-card.am-ok{border-left:3px solid var(--green)}
                .am-card.am-pending{border-left:3px solid var(--subtle)}
                .am-card.am-overdue{border-left:3px solid var(--red);background:color-mix(in srgb,var(--red) 5%,var(--card))}
                .am-card.am-late{border-left:3px solid var(--amber);background:color-mix(in srgb,var(--amber) 5%,var(--card))}
                .am-card.am-disabled{opacity:.5}
                .am-name{font-weight:600;font-size:.9rem;min-width:150px}
                .am-url{font-size:.75rem;color:var(--muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
                .am-stats{display:flex;gap:.75rem;font-size:.78rem;color:var(--muted);flex-wrap:wrap}
                .am-stats strong{color:var(--text)}
                .am-actions{display:flex;gap:.3rem;flex-shrink:0}
                </style>

                <script>
                (function(){
                    var allMonitors=[];
                    var serverTime=null;

                    async function amLoad(){
                        var resp=await fetch('?action=api-monitors-list');
                        var data=await resp.json();
                        allMonitors=data.monitors||[];
                        serverTime=new Date(data.server_time||new Date().toISOString());
                        amRender(allMonitors);
                    }

                    function amIsOverdue(m){
                        if(!m.enabled||!m.next_run_at)return false;
                        var next=new Date(m.next_run_at);
                        var overdueMs=m.interval_minutes*60*1000*2; // 2x interval = overdue
                        return serverTime-next>overdueMs;
                    }
                    function amIsLate(m){
                        if(!m.enabled||!m.next_run_at)return false;
                        var next=new Date(m.next_run_at);
                        return serverTime>next;
                    }

                    function amRender(list){
                        if(!list.length){document.getElementById('amList').innerHTML='<p class="empty">No monitors configured yet.</p>';return}
                        var html='';
                        for(var m of list){
                            var overdue=amIsOverdue(m);
                            var late=amIsLate(m);
                            var cls='am-card';
                            if(!m.enabled)cls+=' am-disabled';
                            else if(m.last_status==='fail')cls+=' am-fail';
                            else if(overdue)cls+=' am-overdue';
                            else if(m.last_status==='ok'&&!late)cls+=' am-ok';
                            else if(m.last_status==='ok'&&late)cls+=' am-late';
                            else cls+=' am-pending';

                            var badge='';
                            if(!m.enabled)badge='<span class="badge" style="background:var(--subtle);color:#fff">DISABLED</span>';
                            else if(m.last_status==='fail')badge='<span class="badge badge-crit">FAIL'+(m.fail_streak>1?' x'+m.fail_streak:'')+'</span>';
                            else if(overdue)badge='<span class="badge" style="background:var(--red);color:#fff">OFFLINE</span>';
                            else if(m.last_status==='ok'&&late)badge='<span class="badge badge-warn">LATE</span>';
                            else if(m.last_status==='ok')badge='<span class="badge" style="background:var(--green);color:#fff">OK</span>';
                            else badge='<span class="badge" style="background:var(--subtle);color:#fff">PENDING</span>';

                            html+='<div class="'+cls+'" data-search="'+esc(m.name+' '+m.url+' '+m.method).toLowerCase()+'">';
                            html+=badge;
                            html+='<span class="am-name">'+esc(m.name)+'</span>';
                            html+='<span class="am-url" title="'+esc(m.url)+'">'+esc(m.method)+' '+esc(m.url)+'</span>';
                            html+='<div class="am-stats">';
                            if(m.last_http_status)html+='<span>HTTP <strong>'+m.last_http_status+'</strong></span>';
                            if(m.last_response_time_ms)html+='<span><strong>'+m.last_response_time_ms+'</strong>ms</span>';
                            html+='<span>every <strong>'+m.interval_minutes+'</strong>m</span>';
                            if(m.last_run_at)html+='<span>'+esc(m.last_run_at.replace('T',' ').substr(0,16))+'</span>';
                            html+='</div>';
                            html+='<div class="am-actions">';
                            html+='<button onclick="amRunNow('+m.id+')" class="btn-sm" title="Run now">&#9654;</button>';
                            html+='<button onclick="amShowHist('+m.id+',\''+esc(m.name)+'\')" class="btn-sm" style="background:var(--subtle)" title="History">&#128196;</button>';
                            html+='<button onclick="amToggle('+m.id+')" class="btn-sm" style="background:var(--subtle)" title="'+(m.enabled?'Disable':'Enable')+'">'+(m.enabled?'&#10074;&#10074;':'&#9654;')+'</button>';
                            html+='<button onclick="amEdit('+m.id+')" class="btn-sm" title="Edit">&#9998;</button>';
                            html+='<button onclick="amDel('+m.id+')" class="btn-sm btn-sm-danger" title="Delete">&#10005;</button>';
                            html+='</div></div>';
                        }
                        document.getElementById('amList').innerHTML=html;
                    }
                    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

                    window.amFilter=function(q){q=q.toLowerCase();document.querySelectorAll('.am-card').forEach(function(c){c.style.display=(!q||c.dataset.search.indexOf(q)>=0)?'':'none'})};

                    window.amOpenModal=function(data){
                        data=data||{};
                        document.getElementById('amId').value=data.id||0;
                        document.getElementById('amModalTitle').textContent=data.id?'Edit Monitor':'Add Monitor';
                        document.getElementById('amName').value=data.name||'';
                        document.getElementById('amUrl').value=data.url||'';
                        document.getElementById('amMethod').value=data.method||'GET';
                        document.getElementById('amExpStatus').value=data.expected_status||200;
                        document.getElementById('amAuthType').value=data.auth_type||'none';
                        document.getElementById('amToken').value=data.auth_token||'';
                        document.getElementById('amApiHeader').value=data.api_key_header||'';
                        document.getElementById('amApiValue').value=data.api_key_value||'';
                        document.getElementById('amBasicUser').value=data.auth_username||'';
                        document.getElementById('amBasicPass').value=data.auth_password||'';
                        document.getElementById('amHeaders').value=data.request_headers||'';
                        document.getElementById('amBody').value=data.request_body||'';
                        document.getElementById('amExpText').value=data.expected_text||'';
                        document.getElementById('amTimeout').value=data.timeout_seconds||10;
                        document.getElementById('amInterval').value=data.interval_minutes||5;
                        document.getElementById('amEnabled').checked=data.id?!!data.enabled:true;
                        amToggleAuth();
                        document.getElementById('amModal').style.display='flex';document.body.style.overflow='hidden';
                    };
                    window.amCloseModal=function(){document.getElementById('amModal').style.display='none';document.body.style.overflow=''};
                    window.amToggleAuth=function(){
                        var t=document.getElementById('amAuthType').value;
                        document.getElementById('amAuthBearer').style.display=t==='bearer'?'':'none';
                        document.getElementById('amAuthApikey').style.display=t==='apikey'?'':'none';
                        document.getElementById('amAuthBasic').style.display=t==='basic'?'':'none';
                    };

                    window.amEdit=function(id){var m=allMonitors.find(function(x){return x.id==id});if(m)amOpenModal(m)};

                    window.amSave=async function(){
                        var payload={
                            id:parseInt(document.getElementById('amId').value)||0,
                            name:document.getElementById('amName').value.trim(),
                            url:document.getElementById('amUrl').value.trim(),
                            method:document.getElementById('amMethod').value,
                            expected_status:parseInt(document.getElementById('amExpStatus').value)||200,
                            auth_type:document.getElementById('amAuthType').value,
                            auth_token:document.getElementById('amToken').value,
                            api_key_header:document.getElementById('amApiHeader').value,
                            api_key_value:document.getElementById('amApiValue').value,
                            auth_username:document.getElementById('amBasicUser').value,
                            auth_password:document.getElementById('amBasicPass').value,
                            request_headers:document.getElementById('amHeaders').value,
                            request_body:document.getElementById('amBody').value,
                            expected_text:document.getElementById('amExpText').value,
                            timeout_seconds:parseInt(document.getElementById('amTimeout').value)||10,
                            interval_minutes:parseInt(document.getElementById('amInterval').value)||5,
                            enabled:document.getElementById('amEnabled').checked?1:0
                        };
                        if(!payload.name||!payload.url){alert('Name and URL required');return}
                        var resp=await fetch('?action=api-monitors-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(payload)});
                        var r=await resp.json();
                        if(r.ok){amCloseModal();amLoad()}else{alert('Error: '+(r.error||'unknown'))}
                    };

                    window.amDel=async function(id){if(!confirm('Delete this monitor and all its history?'))return;await fetch('?action=api-monitors-delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});amLoad()};

                    window.amToggle=async function(id){await fetch('?action=api-monitors-toggle',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});amLoad()};

                    window.amRunNow=async function(id){
                        var btn=event.target;btn.disabled=true;btn.textContent='...';
                        var resp=await fetch('?action=api-monitors-run',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});
                        var r=await resp.json();
                        btn.disabled=false;btn.textContent='\u25B6';
                        if(r.error){alert('Error: '+r.error)}
                        amLoad();
                    };

                    window.amShowHist=async function(id,name){
                        document.getElementById('amHistTitle').textContent='History: '+name;
                        document.getElementById('amHistBody').innerHTML='Loading...';
                        document.getElementById('amHistModal').style.display='flex';document.body.style.overflow='hidden';
                        var resp=await fetch('?action=api-monitors-history&id='+id);
                        var data=await resp.json();
                        var rows=data.results||[];
                        if(!rows.length){document.getElementById('amHistBody').innerHTML='<p class="empty">No results yet.</p>';return}
                        var html='<table style="width:100%;border-collapse:collapse;font-size:.8rem"><thead><tr><th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border)">Time</th><th>Status</th><th>HTTP</th><th>Time</th><th>Error</th></tr></thead><tbody>';
                        for(var r of rows){
                            var color=r.success?'var(--green)':'var(--red)';
                            html+='<tr><td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)">'+esc(r.checked_at.replace('T',' ').substr(0,19))+'</td>';
                            html+='<td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);color:'+color+';font-weight:600">'+(r.success?'OK':'FAIL')+'</td>';
                            html+='<td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)">'+r.http_status+'</td>';
                            html+='<td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)">'+r.response_time_ms+'ms</td>';
                            html+='<td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);font-size:.75rem;color:var(--muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(r.error_message)+'">'+esc(r.error_message)+'</td></tr>';
                        }
                        html+='</tbody></table>';
                        document.getElementById('amHistBody').innerHTML=html;
                    };
                    window.amCloseHist=function(){document.getElementById('amHistModal').style.display='none';document.body.style.overflow=''};

                    document.addEventListener('keydown',function(e){if(e.key==='Escape'){amCloseModal();amCloseHist()}});
                    amLoad();
                })();
                </script>
                <?php
                pageBottom();
                exit;
            }
        },
    ],
];

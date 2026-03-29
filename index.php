<?php
declare(strict_types=1);

/* ============================================================
 * Sermony — Simple Server Monitoring
 * Single-file PHP 8+ application with SQLite backend
 * ============================================================ */

const VERSION      = '1.0.0';
const DB_FILE      = __DIR__ . '/sermony.db';
const OFFLINE_MULT = 2.5;
const HISTORY      = 200;

// Default settings (used on first run, then configurable via Settings page)
const DEFAULTS = [
    'enrollment_key'  => '',   // auto-generated
    'interval_minutes'=> '15',
    'retention_days'  => '30',
    'alert_cpu_warn'  => '75',
    'alert_cpu_crit'  => '90',
    'alert_mem_warn'  => '75',
    'alert_mem_crit'  => '90',
    'alert_disk_warn' => '75',
    'alert_disk_crit' => '90',
    'alert_mail_warn' => '50',
    'alert_mail_crit' => '200',
];

// ─── Database ────────────────────────────────────────────────

function db(): SQLite3 {
    static $i;
    if ($i) return $i;
    $dir = dirname(DB_FILE);
    if (!is_writable($dir)) {
        http_response_code(500);
        $user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user();
        die('Database directory not writable by PHP user (' . $user . '): ' . htmlspecialchars($dir)
            . '<br><br>Fix: <code>sudo chown ' . $user . ':' . $user . ' ' . htmlspecialchars($dir) . '</code>'
            . ' or <code>sudo chmod 777 ' . htmlspecialchars($dir) . '</code>');
    }
    if (file_exists(DB_FILE) && !is_writable(DB_FILE)) {
        http_response_code(500);
        die('Database file not writable: ' . htmlspecialchars(DB_FILE));
    }
    $new = !file_exists(DB_FILE);
    $i = new SQLite3(DB_FILE);
    $i->busyTimeout(5000);
    $i->exec('PRAGMA journal_mode=WAL');
    $i->exec('PRAGMA foreign_keys=ON');
    if ($new) migrate($i);
    // Add columns for existing databases (safe to call multiple times)
    @$i->exec('ALTER TABLE servers ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
    @$i->exec('ALTER TABLE servers ADD COLUMN interval_minutes INTEGER');
    return $i;
}

function migrate(SQLite3 $db): void {
    $db->exec('CREATE TABLE settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE servers (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        hostname     TEXT NOT NULL,
        agent_key    TEXT NOT NULL UNIQUE,
        public_ip    TEXT,
        fqdn         TEXT,
        created_at   TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        last_seen_at TEXT,
        sort_order   INTEGER NOT NULL DEFAULT 0,
        interval_minutes INTEGER
    )');
    $db->exec('CREATE TABLE metrics (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id       INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
        cpu_usage       REAL,
        memory_usage    REAL,
        memory_total_mb INTEGER,
        disk_usage      REAL,
        disk_iops       REAL,
        network_rx_bps  INTEGER,
        network_tx_bps  INTEGER,
        mail_queue      INTEGER,
        load_1          REAL,
        load_5          REAL,
        load_15         REAL,
        collected_at    TEXT NOT NULL,
        received_at     TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
    $db->exec('CREATE INDEX idx_metrics_lookup ON metrics(server_id, received_at DESC)');

    // Seed default settings
    $s = $db->prepare('INSERT INTO settings (key, value) VALUES (:k, :v)');
    foreach (DEFAULTS as $k => $v) {
        if ($k === 'enrollment_key') $v = bin2hex(random_bytes(16));
        $s->bindValue(':k', $k, SQLITE3_TEXT);
        $s->bindValue(':v', $v, SQLITE3_TEXT);
        $s->execute();
        $s->reset();
    }
}

function setting(string $key): ?string {
    $s = db()->prepare('SELECT value FROM settings WHERE key=:k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r['value'] ?? null;
}

function setSetting(string $key, string $value): void {
    $s = db()->prepare('INSERT INTO settings (key,value) VALUES (:k,:v) ON CONFLICT(key) DO UPDATE SET value=:v');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $s->bindValue(':v', $value, SQLITE3_TEXT);
    $s->execute();
}

// ─── Helpers ─────────────────────────────────────────────────

function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jsonOut(array $d, int $c = 200): never {
    http_response_code($c);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

function jsonErr(string $m, int $c = 400): never {
    jsonOut(['error' => $m], $c);
}

function fmtBytes(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $b = max(0, $b);
    $p = $b > 0 ? (int)floor(log($b, 1024)) : 0;
    $p = min($p, 4);
    return round($b / (1024 ** $p), 1) . ' ' . $u[$p];
}

function timeAgo(?string $t): string {
    if (!$t) return 'Never';
    $d = time() - strtotime($t);
    if ($d < 0) return 'just now';
    if ($d < 60) return $d . 's ago';
    if ($d < 3600) return (int)floor($d / 60) . 'm ago';
    if ($d < 86400) return (int)floor($d / 3600) . 'h ago';
    return (int)floor($d / 86400) . 'd ago';
}

function isOnline(?string $t, ?int $serverInterval = null): bool {
    if (!$t) return false;
    $int = $serverInterval ?? (int)(setting('interval_minutes') ?? 15);
    return (time() - strtotime($t)) < ($int * OFFLINE_MULT * 60);
}

/** Server is "stale" if online but missed at least one expected check-in (>1.5x interval) */
function isStale(?string $t, ?int $serverInterval = null): bool {
    if (!$t) return false;
    $int = $serverInterval ?? (int)(setting('interval_minutes') ?? 15);
    $elapsed = time() - strtotime($t);
    $threshold = $int * 60;
    return $elapsed >= ($threshold * 1.5) && $elapsed < ($threshold * OFFLINE_MULT);
}

function mColor(float $v): string {
    $crit = (float)(setting('alert_cpu_crit') ?? 90);
    $warn = (float)(setting('alert_cpu_warn') ?? 75);
    if ($v >= $crit) return 'var(--red)';
    if ($v >= $warn) return 'var(--amber)';
    return 'var(--green)';
}

function mColorFor(string $type, float $v): string {
    $crit = (float)(setting("alert_{$type}_crit") ?? 90);
    $warn = (float)(setting("alert_{$type}_warn") ?? 75);
    if ($v >= $crit) return 'var(--red)';
    if ($v >= $warn) return 'var(--amber)';
    return 'var(--green)';
}

/** Returns 'crit', 'warn', or 'ok' for a server's latest metrics */
function serverHealth(array $srv): string {
    $dominated = 'ok';
    foreach (['cpu' => 'cpu_usage', 'mem' => 'memory_usage', 'disk' => 'disk_usage', 'mail' => 'mail_queue'] as $type => $col) {
        if ($srv[$col] === null) continue;
        $v = (float)$srv[$col];
        $crit = (float)(setting("alert_{$type}_crit") ?? 90);
        $warn = (float)(setting("alert_{$type}_warn") ?? 50);
        if ($v >= $crit) return 'crit';
        if ($v >= $warn) $dominated = 'warn';
    }
    return $dominated;
}

function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'your-server';
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $scheme . '://' . $host . $path;
}

// ─── Router ──────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$action = $_GET['action'] ?? 'dashboard';

match ($action) {
    'enroll'         => handleEnroll(),
    'ingest'         => handleIngest(),
    'delete'         => handleDelete(),
    'reorder'        => handleReorder(),
    'update-server'  => handleUpdateServer(),
    'settings'       => ($_SERVER['REQUEST_METHOD'] === 'POST' ? handleSettings() : showSettings()),
    'server'         => showServer(),
    'install-script' => serveScript('install.sh'),
    'agent-script'   => serveScript('sermony-agent.sh'),
    default          => showDashboard(),
};

// ─── API Handlers ────────────────────────────────────────────

function handleEnroll(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('POST required', 405);

    $in = json_decode(file_get_contents('php://input'), true);
    if (!$in) jsonErr('Invalid JSON');

    $ekey = trim((string)($in['enrollment_key'] ?? ''));
    $host = trim((string)($in['hostname'] ?? ''));
    if ($ekey === '' || $host === '') jsonErr('Missing enrollment_key or hostname');

    $stored = setting('enrollment_key');
    $valid = $stored && hash_equals($stored, $ekey);
    if (!$valid) {
        // Check previous (still-active) keys
        $prev = json_decode(setting('previous_keys') ?? '[]', true);
        foreach ($prev as $pk) {
            if (hash_equals($pk, $ekey)) { $valid = true; break; }
        }
    }
    if (!$valid) jsonErr('Invalid enrollment key', 403);

    $d = db();
    $interval = (int)(setting('interval_minutes') ?? 15);

    $s = $d->prepare('SELECT agent_key, interval_minutes AS srv_int FROM servers WHERE hostname=:h');
    $s->bindValue(':h', $host, SQLITE3_TEXT);
    $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        jsonOut(['agent_key' => $row['agent_key'], 'interval' => (int)($row['srv_int'] ?? $interval)]);
    }

    $ak = bin2hex(random_bytes(32));
    $s = $d->prepare('INSERT INTO servers (hostname, agent_key) VALUES (:h, :k)');
    $s->bindValue(':h', $host, SQLITE3_TEXT);
    $s->bindValue(':k', $ak, SQLITE3_TEXT);
    $s->execute();

    jsonOut(['agent_key' => $ak, 'interval' => $interval]);
}

function handleIngest(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('POST required', 405);

    $in = json_decode(file_get_contents('php://input'), true);
    if (!$in) jsonErr('Invalid JSON');

    $ak = (string)($in['agent_key'] ?? '');
    if ($ak === '') jsonErr('Missing agent_key', 401);

    $d = db();
    $s = $d->prepare('SELECT id FROM servers WHERE agent_key=:k');
    $s->bindValue(':k', $ak, SQLITE3_TEXT);
    $srv = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$srv) jsonErr('Unknown agent', 403);

    $id = (int)$srv['id'];

    $s = $d->prepare("UPDATE servers SET public_ip=:ip, fqdn=:f, hostname=:h,
        last_seen_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE id=:id");
    $s->bindValue(':ip', isset($in['public_ip']) ? (string)$in['public_ip'] : null);
    $s->bindValue(':f', isset($in['fqdn']) ? (string)$in['fqdn'] : null);
    $s->bindValue(':h', trim((string)($in['hostname'] ?? '')));
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->execute();

    $s = $d->prepare('INSERT INTO metrics
        (server_id,cpu_usage,memory_usage,memory_total_mb,disk_usage,disk_iops,
         network_rx_bps,network_tx_bps,mail_queue,load_1,load_5,load_15,collected_at)
        VALUES (:sid,:cpu,:mem,:memt,:disk,:iops,:nrx,:ntx,:mail,:l1,:l5,:l15,:ts)');
    $s->bindValue(':sid', $id, SQLITE3_INTEGER);
    $s->bindValue(':cpu',  isset($in['cpu_usage'])      ? (float)$in['cpu_usage']      : null);
    $s->bindValue(':mem',  isset($in['memory_usage'])    ? (float)$in['memory_usage']   : null);
    $s->bindValue(':memt', isset($in['memory_total_mb']) ? (int)$in['memory_total_mb']  : null);
    $s->bindValue(':disk', isset($in['disk_usage'])      ? (float)$in['disk_usage']     : null);
    $s->bindValue(':iops', isset($in['disk_iops'])       ? (float)$in['disk_iops']      : null);
    $s->bindValue(':nrx',  isset($in['network_rx_bps'])  ? (int)$in['network_rx_bps']   : null);
    $s->bindValue(':ntx',  isset($in['network_tx_bps'])  ? (int)$in['network_tx_bps']   : null);
    $s->bindValue(':mail', isset($in['mail_queue'])       ? (int)$in['mail_queue']       : null);
    $s->bindValue(':l1',   isset($in['load_1'])          ? (float)$in['load_1']         : null);
    $s->bindValue(':l5',   isset($in['load_5'])          ? (float)$in['load_5']         : null);
    $s->bindValue(':l15',  isset($in['load_15'])         ? (float)$in['load_15']        : null);
    $s->bindValue(':ts',   (string)($in['collected_at'] ?? gmdate('Y-m-d\TH:i:s\Z')));
    $s->execute();

    // Probabilistic cleanup (~2% of requests)
    if (random_int(1, 50) === 1) {
        $ret = (int)(setting('retention_days') ?? 30);
        $c = $d->prepare("DELETE FROM metrics WHERE received_at < strftime('%Y-%m-%dT%H:%M:%SZ','now',:days)");
        $c->bindValue(':days', '-' . $ret . ' days', SQLITE3_TEXT);
        $c->execute();
    }

    jsonOut(['ok' => true]);
}

function handleDelete(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?');
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $s = db()->prepare('DELETE FROM servers WHERE id=:id');
        $s->bindValue(':id', $id, SQLITE3_INTEGER);
        $s->execute();
    }
    header('Location: ?');
    exit;
}

function handleSettings(): never {
    $fields = [
        'interval_minutes' => 'int',
        'retention_days'   => 'int',
        'alert_cpu_warn'   => 'int',
        'alert_cpu_crit'   => 'int',
        'alert_mem_warn'   => 'int',
        'alert_mem_crit'   => 'int',
        'alert_disk_warn'  => 'int',
        'alert_disk_crit'  => 'int',
        'alert_mail_warn'  => 'int',
        'alert_mail_crit'  => 'int',
    ];
    foreach ($fields as $k => $type) {
        if (isset($_POST[$k])) {
            $v = max(1, (int)$_POST[$k]);
            setSetting($k, (string)$v);
        }
    }

    // Regenerate enrollment key — keep old one active by default
    if (!empty($_POST['regenerate_key'])) {
        $old = setting('enrollment_key');
        $prev = json_decode(setting('previous_keys') ?? '[]', true);
        $prev[] = $old;
        setSetting('previous_keys', json_encode($prev));
        setSetting('enrollment_key', bin2hex(random_bytes(16)));
        header('Location: ?action=settings&saved=1&regenerated=1');
        exit;
    }

    // Invalidate a previous key
    if (!empty($_POST['invalidate_key'])) {
        $keyToRemove = $_POST['invalidate_key'];
        $prev = json_decode(setting('previous_keys') ?? '[]', true);
        $prev = array_values(array_filter($prev, fn($k) => $k !== $keyToRemove));
        setSetting('previous_keys', json_encode($prev));
        header('Location: ?action=settings&saved=1');
        exit;
    }

    // Invalidate all previous keys
    if (!empty($_POST['invalidate_all_keys'])) {
        setSetting('previous_keys', '[]');
        header('Location: ?action=settings&saved=1');
        exit;
    }

    header('Location: ?action=settings&saved=1');
    exit;
}

function handleReorder(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('POST required', 405);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) jsonErr('Invalid JSON');
    $d = db();
    $s = $d->prepare('UPDATE servers SET sort_order=:pos WHERE id=:id');
    foreach ($in as $item) {
        $s->bindValue(':id', (int)($item['id'] ?? 0), SQLITE3_INTEGER);
        $s->bindValue(':pos', (int)($item['pos'] ?? 0), SQLITE3_INTEGER);
        $s->execute();
        $s->reset();
    }
    jsonOut(['ok' => true]);
}

function handleUpdateServer(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ?');
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { header('Location: ?'); exit; }
    $intVal = $_POST['interval_minutes'] ?? '';
    $d = db();
    $s = $d->prepare('UPDATE servers SET interval_minutes=:int WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->bindValue(':int', $intVal !== '' ? max(1, (int)$intVal) : null);
    $s->execute();
    header('Location: ?action=server&id=' . $id . '&saved=1');
    exit;
}

function serveScript(string $file): never {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    readfile($path);
    exit;
}

// ─── Dashboard ───────────────────────────────────────────────

function showDashboard(): never {
    $d = db();

    $servers = [];
    $res = $d->query('
        SELECT s.*,
            m.cpu_usage, m.memory_usage, m.memory_total_mb,
            m.disk_usage, m.disk_iops,
            m.network_rx_bps, m.network_tx_bps,
            m.mail_queue, m.load_1, m.load_5, m.load_15
        FROM servers s
        LEFT JOIN metrics m ON m.id = (
            SELECT id FROM metrics WHERE server_id = s.id ORDER BY received_at DESC LIMIT 1
        )
        ORDER BY s.sort_order, s.hostname
    ');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $servers[] = $row;
    }

    // Compute health + online + stale for each server, then sort: issues first
    $counts = ['total' => count($servers), 'online' => 0, 'offline' => 0, 'crit' => 0, 'warn' => 0, 'stale' => 0];
    $hasCustomOrder = false;
    foreach ($servers as &$srv) {
        $srvInt = $srv['interval_minutes'] !== null ? (int)$srv['interval_minutes'] : null;
        $srv['_online'] = isOnline($srv['last_seen_at'], $srvInt);
        $srv['_stale'] = $srv['_online'] && isStale($srv['last_seen_at'], $srvInt);
        $srv['_health'] = serverHealth($srv);
        if ($srv['_online']) $counts['online']++; else $counts['offline']++;
        if ($srv['_stale']) $counts['stale']++;
        if ($srv['_health'] === 'crit') $counts['crit']++;
        elseif ($srv['_health'] === 'warn') $counts['warn']++;
        if ((int)$srv['sort_order'] !== 0) $hasCustomOrder = true;
    }
    unset($srv);

    // Auto-sort by severity if no custom drag-drop order has been set
    // Order: crit > warn > stale > offline > ok
    if (!$hasCustomOrder) {
        usort($servers, function ($a, $b) {
            $pri = ['crit' => 0, 'warn' => 1, 'ok' => 5];
            if ($a['_online']) {
                $pa = $a['_stale'] ? 2 : ($pri[$a['_health']] ?? 5);
            } else {
                $pa = 3;
            }
            if ($b['_online']) {
                $pb = $b['_stale'] ? 2 : ($pri[$b['_health']] ?? 5);
            } else {
                $pb = 3;
            }
            return $pa !== $pb ? $pa - $pb : strcmp($a['hostname'], $b['hostname']);
        });
    }

    pageTop('Dashboard');
    ?>
    <?php if ($counts['total'] > 0): ?>
    <div class="status-summary">
        <span class="ss-item"><?=$counts['total']?> server<?=$counts['total']!==1?'s':''?></span>
        <span class="ss-item ss-online"><?=$counts['online']?> online</span>
        <?php if ($counts['offline']): ?><span class="ss-item ss-offline"><?=$counts['offline']?> offline</span><?php endif; ?>
        <?php if ($counts['crit']): ?><span class="ss-item ss-crit"><?=$counts['crit']?> critical</span><?php endif; ?>
        <?php if ($counts['warn']): ?><span class="ss-item ss-warn"><?=$counts['warn']?> warning</span><?php endif; ?>
        <?php if ($counts['stale']): ?><span class="ss-item ss-stale"><?=$counts['stale']?> stale</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($servers)): ?>
    <div class="empty">
        <h2>No servers yet</h2>
        <p>Go to <a href="?action=settings">Settings</a> for the install command to add your first server.</p>
    </div>
    <?php else: ?>
    <div class="grid" id="serverGrid">
        <?php foreach ($servers as $srv):
            $on = $srv['_online'];
            $health = $srv['_health'];
            $cpu  = $srv['cpu_usage'];
            $mem  = $srv['memory_usage'];
            $disk = $srv['disk_usage'];
            $stale = $srv['_stale'];
            $cardClass = 'card';
            if (!$on) $cardClass .= ' card-offline';
            elseif ($health === 'crit') $cardClass .= ' card-crit';
            elseif ($health === 'warn') $cardClass .= ' card-warn';
            elseif ($stale) $cardClass .= ' card-stale';
        ?>
        <div class="<?=$cardClass?>" data-id="<?=$srv['id']?>" draggable="true">
            <div class="card-head">
                <span class="dot <?=$on ? ($stale ? 'dot-stale' : 'dot-on') : 'dot-off'?>"></span>
                <a href="?action=server&id=<?=$srv['id']?>" class="card-hostname"><?=e($srv['hostname'])?></a>
                <?php if (!$on): ?><span class="badge badge-off">OFFLINE</span>
                <?php elseif ($health === 'crit'): ?><span class="badge badge-crit">CRITICAL</span>
                <?php elseif ($health === 'warn'): ?><span class="badge badge-warn">WARNING</span>
                <?php elseif ($stale): ?><span class="badge badge-stale">STALE</span>
                <?php endif; ?>
                <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?=e($srv['hostname'])?> and all its metrics?')">
                    <input type="hidden" name="id" value="<?=$srv['id']?>">
                    <button type="submit" class="btn-del" title="Delete server">&times;</button>
                </form>
            </div>
            <div class="card-meta">
                <?=e($srv['public_ip'] ?: "\xE2\x80\x94")?>
                <?php if ($srv['fqdn']): ?> &middot; <?=e($srv['fqdn'])?><?php endif; ?>
            </div>
            <div class="metrics">
                <div class="m">
                    <span class="ml">CPU</span>
                    <span class="mv"><?=$cpu !== null ? number_format((float)$cpu, 1) . '%' : "\xE2\x80\x94"?></span>
                    <?php if ($cpu !== null): ?><div class="bar"><div style="width:<?=min((float)$cpu,100)?>%;background:<?=mColorFor('cpu',(float)$cpu)?>"></div></div><?php endif; ?>
                </div>
                <div class="m">
                    <span class="ml">Memory</span>
                    <span class="mv"><?=$mem !== null ? number_format((float)$mem, 1) . '%' : "\xE2\x80\x94"?><?php if ($srv['memory_total_mb']): ?> <small>(<?=number_format((int)$srv['memory_total_mb'])?>MB)</small><?php endif; ?></span>
                    <?php if ($mem !== null): ?><div class="bar"><div style="width:<?=min((float)$mem,100)?>%;background:<?=mColorFor('mem',(float)$mem)?>"></div></div><?php endif; ?>
                </div>
                <div class="m">
                    <span class="ml">Disk</span>
                    <span class="mv"><?=$disk !== null ? number_format((float)$disk, 1) . '%' : "\xE2\x80\x94"?></span>
                    <?php if ($disk !== null): ?><div class="bar"><div style="width:<?=min((float)$disk,100)?>%;background:<?=mColorFor('disk',(float)$disk)?>"></div></div><?php endif; ?>
                </div>
                <div class="m">
                    <span class="ml">IOPS</span>
                    <span class="mv"><?=$srv['disk_iops'] !== null ? number_format((float)$srv['disk_iops'], 0) : "\xE2\x80\x94"?></span>
                </div>
                <div class="m">
                    <span class="ml">Net &#8595;</span>
                    <span class="mv"><?=$srv['network_rx_bps'] !== null ? fmtBytes((int)$srv['network_rx_bps']) . '/s' : "\xE2\x80\x94"?></span>
                </div>
                <div class="m">
                    <span class="ml">Net &#8593;</span>
                    <span class="mv"><?=$srv['network_tx_bps'] !== null ? fmtBytes((int)$srv['network_tx_bps']) . '/s' : "\xE2\x80\x94"?></span>
                </div>
                <div class="m">
                    <span class="ml">Mail Q</span>
                    <span class="mv" <?php if ($srv['mail_queue'] !== null): ?>style="color:<?=mColorFor('mail',(float)$srv['mail_queue'])?>"<?php endif; ?>><?=$srv['mail_queue'] !== null ? (int)$srv['mail_queue'] : "\xE2\x80\x94"?></span>
                </div>
                <div class="m">
                    <span class="ml">Load</span>
                    <span class="mv"><?=$srv['load_1'] !== null ? number_format((float)$srv['load_1'], 2) : "\xE2\x80\x94"?></span>
                </div>
            </div>
            <div class="card-foot">
                <?=$on ? ($stale ? 'Stale' : 'Online') : 'Offline'?> &middot; <?=timeAgo($srv['last_seen_at'])?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif;

    pageBottom();
    exit;
}

// ─── Server Details ──────────────────────────────────────────

function showServer(): never {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { header('Location: ?'); exit; }

    $d = db();
    $s = $d->prepare('SELECT * FROM servers WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $srv = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$srv) { header('Location: ?'); exit; }

    $srvInt = $srv['interval_minutes'] !== null ? (int)$srv['interval_minutes'] : null;
    $on = isOnline($srv['last_seen_at'], $srvInt);

    $s = $d->prepare('SELECT * FROM metrics WHERE server_id=:id ORDER BY received_at DESC LIMIT :lim');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->bindValue(':lim', HISTORY, SQLITE3_INTEGER);
    $res = $s->execute();
    $metrics = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $metrics[] = $row;

    pageTop(e($srv['hostname']));
    ?>
    <div class="detail-header">
        <a href="?" class="back">&larr; Dashboard</a>
        <div class="detail-title">
            <span class="dot <?=$on ? 'dot-on' : 'dot-off'?>"></span>
            <h1><?=e($srv['hostname'])?></h1>
        </div>
        <div class="detail-meta">
            <span><strong>IP:</strong> <?=e($srv['public_ip'] ?: "\xE2\x80\x94")?></span>
            <span><strong>FQDN:</strong> <?=e($srv['fqdn'] ?: "\xE2\x80\x94")?></span>
            <span><strong>Status:</strong> <?=$on ? 'Online' : 'Offline'?></span>
            <span><strong>Enrolled:</strong> <?=e(substr($srv['created_at'], 0, 16))?></span>
            <span><strong>Last seen:</strong> <?=timeAgo($srv['last_seen_at'])?></span>
            <span><strong>Interval:</strong> <?=$srv['interval_minutes'] !== null ? $srv['interval_minutes'] . 'm (custom)' : (setting('interval_minutes') ?? 15) . 'm (global)'?></span>
        </div>
        <?php if (isset($_GET['saved'])): ?><div class="alert-ok" style="margin-top:.5rem">Server settings saved.</div><?php endif; ?>
        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <form method="post" action="?action=update-server" style="display:flex;gap:.5rem;align-items:center">
                <input type="hidden" name="id" value="<?=$srv['id']?>">
                <label style="font-size:.82rem;color:var(--muted);margin:0">Interval (min):</label>
                <input type="number" name="interval_minutes" value="<?=e($srv['interval_minutes'] ?? '')?>" placeholder="<?=e(setting('interval_minutes') ?? '15')?>" min="1" max="1440" style="width:5rem">
                <button type="submit" class="btn-sm">Save</button>
                <span style="font-size:.75rem;color:var(--subtle)">Leave empty for global default</span>
            </form>
            <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?=e($srv['hostname'])?> and all its metrics?')">
                <input type="hidden" name="id" value="<?=$srv['id']?>">
                <button type="submit" class="btn-danger">Delete Server</button>
            </form>
        </div>
    </div>

    <?php if (empty($metrics)): ?>
    <p class="empty">No metrics recorded yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Time</th><th>CPU %</th><th>Mem %</th><th>Mem MB</th>
                <th>Disk %</th><th>IOPS</th><th>Net RX</th><th>Net TX</th>
                <th>Mail Q</th><th>Load (1/5/15)</th>
            </tr></thead>
            <tbody>
            <?php foreach ($metrics as $m): ?>
            <tr>
                <td><?=e(substr($m['collected_at'], 0, 16))?></td>
                <td style="color:<?=$m['cpu_usage'] !== null ? mColorFor('cpu',(float)$m['cpu_usage']) : 'var(--muted)'?>"><?=$m['cpu_usage'] !== null ? number_format((float)$m['cpu_usage'], 1) : "\xE2\x80\x94"?></td>
                <td style="color:<?=$m['memory_usage'] !== null ? mColorFor('mem',(float)$m['memory_usage']) : 'var(--muted)'?>"><?=$m['memory_usage'] !== null ? number_format((float)$m['memory_usage'], 1) : "\xE2\x80\x94"?></td>
                <td><?=$m['memory_total_mb'] !== null ? number_format((int)$m['memory_total_mb']) : "\xE2\x80\x94"?></td>
                <td style="color:<?=$m['disk_usage'] !== null ? mColorFor('disk',(float)$m['disk_usage']) : 'var(--muted)'?>"><?=$m['disk_usage'] !== null ? number_format((float)$m['disk_usage'], 1) : "\xE2\x80\x94"?></td>
                <td><?=$m['disk_iops'] !== null ? number_format((float)$m['disk_iops'], 0) : "\xE2\x80\x94"?></td>
                <td><?=$m['network_rx_bps'] !== null ? fmtBytes((int)$m['network_rx_bps']) . '/s' : "\xE2\x80\x94"?></td>
                <td><?=$m['network_tx_bps'] !== null ? fmtBytes((int)$m['network_tx_bps']) . '/s' : "\xE2\x80\x94"?></td>
                <td style="color:<?=$m['mail_queue'] !== null ? mColorFor('mail',(float)$m['mail_queue']) : 'var(--muted)'?>"><?=$m['mail_queue'] !== null ? (int)$m['mail_queue'] : "\xE2\x80\x94"?></td>
                <td><?=$m['load_1'] !== null
                    ? number_format((float)$m['load_1'], 2) . ' / '
                      . number_format((float)$m['load_5'], 2) . ' / '
                      . number_format((float)$m['load_15'], 2)
                    : "\xE2\x80\x94"?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;

    pageBottom();
    exit;
}

// ─── Settings Page ───────────────────────────────────────────

function showSettings(): never {
    $saved = isset($_GET['saved']);

    pageTop('Settings');
    ?>
    <div class="detail-header">
        <a href="?" class="back">&larr; Dashboard</a>
        <h1 style="margin:.75rem 0">Settings</h1>
        <?php if ($saved): ?><div class="alert-ok">Settings saved.</div><?php endif; ?>
        <?php if (isset($_GET['regenerated'])): ?><div class="alert-warn">Enrollment key regenerated. The previous key is still active below &mdash; invalidate it when you no longer need it.</div><?php endif; ?>

        <form method="post" action="?action=settings">

            <?php
            $ekey = setting('enrollment_key');
            $url = baseUrl();
            $prevKeys = json_decode(setting('previous_keys') ?? '[]', true);
            ?>
            <fieldset>
                <legend>Enrollment</legend>
                <label>Current Enrollment Key</label>
                <div class="ekey-row">
                    <code class="ekey-code" id="ekey"><?=e($ekey)?></code>
                    <button type="button" onclick="copyEl('ekey',this)" class="btn-sm">Copy</button>
                    <button type="submit" name="regenerate_key" value="1" class="btn-sm" onclick="return confirm('Generate a new enrollment key?\n\nThe current key will be kept active so existing install scripts still work. You can invalidate old keys below.')">Regenerate</button>
                </div>
                <label>Install Command</label>
                <div class="ekey-row">
                    <code class="ekey-code install-cmd" id="icmd">curl -sSL '<?=e($url)?>?action=install-script' \
  | sudo bash -s -- \
  '<?=e($url)?>' \
  '<?=e($ekey)?>'</code>
                    <button type="button" onclick="copyEl('icmd',this)" class="btn-sm">Copy</button>
                </div>
                <?php if (!empty($prevKeys)): ?>
                <div class="prev-keys">
                    <label>Previous Keys (still active)</label>
                    <?php foreach ($prevKeys as $i => $pk): ?>
                    <div class="prev-key-row">
                        <code class="ekey-code"><?=e($pk)?></code>
                        <button type="submit" name="invalidate_key" value="<?=e($pk)?>" class="btn-sm btn-sm-danger" onclick="return confirm('Invalidate this key?\n\nAny install script using this key will stop working for new enrollments. Already-enrolled agents are NOT affected.')">Invalidate</button>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:.5rem">
                        <button type="submit" name="invalidate_all_keys" value="1" class="btn-sm btn-sm-danger" onclick="return confirm('Invalidate ALL previous keys?\n\nOnly the current key will remain active. Already-enrolled agents are NOT affected.')">Invalidate All Previous Keys</button>
                    </div>
                </div>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>General</legend>
                <div class="field-row">
                    <label>Check Interval (minutes)
                        <input type="number" name="interval_minutes" value="<?=e(setting('interval_minutes'))?>" min="1" max="1440">
                    </label>
                    <label>Data Retention (days)
                        <input type="number" name="retention_days" value="<?=e(setting('retention_days'))?>" min="1" max="3650">
                    </label>
                </div>
            </fieldset>

            <div class="settings-grid">
                <fieldset>
                    <legend>CPU Alerts (%)</legend>
                    <div class="field-row">
                        <label>Warning
                            <input type="number" name="alert_cpu_warn" value="<?=e(setting('alert_cpu_warn'))?>" min="1" max="100">
                        </label>
                        <label>Critical
                            <input type="number" name="alert_cpu_crit" value="<?=e(setting('alert_cpu_crit'))?>" min="1" max="100">
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Memory Alerts (%)</legend>
                    <div class="field-row">
                        <label>Warning
                            <input type="number" name="alert_mem_warn" value="<?=e(setting('alert_mem_warn'))?>" min="1" max="100">
                        </label>
                        <label>Critical
                            <input type="number" name="alert_mem_crit" value="<?=e(setting('alert_mem_crit'))?>" min="1" max="100">
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Disk Alerts (%)</legend>
                    <div class="field-row">
                        <label>Warning
                            <input type="number" name="alert_disk_warn" value="<?=e(setting('alert_disk_warn'))?>" min="1" max="100">
                        </label>
                        <label>Critical
                            <input type="number" name="alert_disk_crit" value="<?=e(setting('alert_disk_crit'))?>" min="1" max="100">
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Mail Queue Alerts (count)</legend>
                    <div class="field-row">
                        <label>Warning
                            <input type="number" name="alert_mail_warn" value="<?=e(setting('alert_mail_warn'))?>" min="1" max="100000">
                        </label>
                        <label>Critical
                            <input type="number" name="alert_mail_crit" value="<?=e(setting('alert_mail_crit'))?>" min="1" max="100000">
                        </label>
                    </div>
                </fieldset>
            </div>

            <button type="submit" class="btn-primary" style="margin-top:1.25rem">Save Settings</button>
        </form>
    </div>
    <?php
    pageBottom();
    exit;
}

// ─── HTML Template ───────────────────────────────────────────

function pageTop(string $title): void {
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="refresh" content="<?=max(30, (int)(setting('interval_minutes') ?? 15) * 60)?>">
<title><?=e($title)?> — Sermony</title>
<style>
/* ── Theme Variables ── */
:root, [data-theme="light"] {
    --bg:#f0f2f5; --card:#fff; --card-border:#e5e7eb; --card-hover:rgba(0,0,0,.08);
    --text:#111827; --muted:#6b7280; --subtle:#9ca3af;
    --header-bg:#1e293b; --header-text:#f8fafc;
    --code-bg:#f1f5f9;
    --input-bg:#fff; --input-border:#d1d5db;
    --table-head:#f8fafc; --table-border:#e5e7eb; --table-row-border:#f3f4f6; --table-hover:#f8fafc;
    --green:#22c55e; --amber:#f59e0b; --red:#ef4444; --slate:#6b7280;
    --blue:#3b82f6; --blue-hover:#2563eb;
    --foot-border:#f3f4f6;
}
[data-theme="dark"] {
    --bg:#0f172a; --card:#1e293b; --card-border:#334155; --card-hover:rgba(255,255,255,.05);
    --text:#e2e8f0; --muted:#94a3b8; --subtle:#64748b;
    --header-bg:#020617; --header-text:#f8fafc;
    --code-bg:#334155;
    --input-bg:#1e293b; --input-border:#475569;
    --table-head:#1e293b; --table-border:#334155; --table-row-border:#1e293b; --table-hover:#334155;
    --green:#4ade80; --amber:#fbbf24; --red:#f87171; --slate:#94a3b8;
    --blue:#60a5fa; --blue-hover:#3b82f6;
    --foot-border:#1e293b;
}

*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
a{color:var(--blue)}
.wrap{max-width:1280px;margin:0 auto;padding:0 1rem}

header{background:var(--header-bg);color:var(--header-text);padding:1rem 0}
header .wrap{display:flex;align-items:center;gap:1rem}
header h1{font-size:1.25rem;font-weight:600;flex:1}
header h1 span{color:#60a5fa}
.hdr-links{display:flex;align-items:center;gap:.75rem}
.hdr-links a{color:#94a3b8;text-decoration:none;font-size:.85rem}
.hdr-links a:hover{color:#f8fafc}
.theme-toggle{background:none;border:1px solid #475569;color:#94a3b8;padding:.3rem .6rem;border-radius:6px;cursor:pointer;font-size:.8rem}
.theme-toggle:hover{color:#f8fafc;border-color:#94a3b8}

/* ── Status Summary ── */
.status-summary{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin:1rem 0 .5rem;padding:.5rem .75rem;background:var(--card);border:1px solid var(--card-border);border-radius:8px}
.ss-item{font-size:.78rem;padding:.2rem .55rem;border-radius:10px;font-weight:500;white-space:nowrap}
.ss-item:first-child{color:var(--text);font-weight:600;padding-right:.5rem;border-right:1px solid var(--card-border);margin-right:.15rem}
.ss-online{color:var(--green)}
.ss-offline{color:var(--red)}
.ss-crit{color:#fff;background:var(--red);border-radius:10px}
.ss-warn{color:#000;background:var(--amber);border-radius:10px}
.ss-stale{color:var(--slate);border:1px solid var(--slate)}

/* ── Setup Bar ── */
.setup-bar{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;margin:1.25rem 0;font-size:.85rem;display:flex;flex-direction:column;gap:.5rem}
.setup-bar code{background:var(--code-bg);padding:.15rem .4rem;border-radius:4px;font-size:.8rem}
.install-cmd{display:inline-block;max-width:100%;overflow-x:auto;white-space:nowrap}
.btn-sm{background:var(--blue);color:#fff;border:none;padding:.2rem .5rem;border-radius:4px;cursor:pointer;font-size:.75rem}
.btn-sm:hover{background:var(--blue-hover)}

/* ── Grid & Cards ── */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem;margin:1.25rem 0}

.card{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;transition:box-shadow .15s,opacity .15s}
.card:hover{box-shadow:0 4px 12px var(--card-hover)}
.card[draggable]{cursor:grab}
.card[draggable]:active{cursor:grabbing}
.card.dragging{opacity:.4}
.card-offline{border-left:3px solid var(--red)}
.card-crit{border-left:3px solid var(--red);background:color-mix(in srgb, var(--red) 5%, var(--card))}
.card-warn{border-left:3px solid var(--amber);background:color-mix(in srgb, var(--amber) 5%, var(--card))}
.card-stale{border-left:3px solid var(--slate);background:color-mix(in srgb, var(--slate) 5%, var(--card))}
.card-head{display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem}
.card-hostname{font-weight:600;font-size:1rem;color:var(--text);text-decoration:none;flex:1}
.card-hostname:hover{color:var(--blue)}
.card-meta{font-size:.8rem;color:var(--muted);margin-bottom:.75rem}
.card-foot{font-size:.75rem;color:var(--muted);margin-top:.75rem;padding-top:.5rem;border-top:1px solid var(--foot-border)}

/* ── Badges ── */
.badge{font-size:.6rem;font-weight:700;letter-spacing:.05em;padding:.15rem .4rem;border-radius:4px;text-transform:uppercase}
.badge-off{background:var(--red);color:#fff}
.badge-crit{background:var(--red);color:#fff;animation:pulse-badge 2s ease-in-out infinite}
.badge-warn{background:var(--amber);color:#000}
.badge-stale{background:var(--slate);color:#fff}
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.6}}

/* ── Dots ── */
.dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0}
.dot-on{background:var(--green);box-shadow:0 0 0 3px color-mix(in srgb, var(--green) 20%, transparent)}
.dot-stale{background:var(--slate);box-shadow:0 0 0 3px color-mix(in srgb, var(--slate) 20%, transparent);animation:pulse-dot 2s ease-in-out infinite}
.dot-off{background:var(--red);box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 20%, transparent)}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}

.btn-del{background:none;border:none;color:var(--subtle);font-size:1.2rem;cursor:pointer;padding:0 .3rem;line-height:1}
.btn-del:hover{color:var(--red)}

/* ── Metrics ── */
.metrics{display:grid;grid-template-columns:1fr 1fr;gap:.4rem 1rem}
.m{font-size:.82rem}
.ml{color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.03em}
.mv{font-weight:500;display:block}
.mv small{font-weight:400;color:var(--subtle)}
.bar{height:3px;background:var(--card-border);border-radius:2px;margin-top:2px}
.bar>div{height:100%;border-radius:2px;transition:width .3s}

/* ── Detail Page ── */
.detail-header{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1.25rem;margin:1.25rem 0;overflow:hidden}
.detail-title{display:flex;align-items:center;gap:.5rem;margin:.75rem 0}
.detail-title h1{font-size:1.5rem}
.detail-meta{display:flex;flex-wrap:wrap;gap:1rem;font-size:.85rem;color:var(--muted);margin-bottom:1rem}
.back{font-size:.85rem;color:var(--blue);text-decoration:none}
.back:hover{text-decoration:underline}
.btn-danger{background:var(--red);color:#fff;border:none;padding:.4rem 1rem;border-radius:6px;cursor:pointer;font-size:.85rem}
.btn-danger:hover{opacity:.9}
.btn-primary{background:var(--blue);color:#fff;border:none;padding:.5rem 1.5rem;border-radius:6px;cursor:pointer;font-size:.9rem}
.btn-primary:hover{background:var(--blue-hover)}

/* ── Table ── */
.table-wrap{overflow-x:auto;margin:1.25rem 0;background:var(--card);border:1px solid var(--card-border);border-radius:8px}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{background:var(--table-head);font-weight:600;text-align:left;padding:.6rem .75rem;border-bottom:2px solid var(--table-border);white-space:nowrap}
td{padding:.5rem .75rem;border-bottom:1px solid var(--table-row-border);white-space:nowrap}
tr:hover td{background:var(--table-hover)}

/* ── Settings ── */
.settings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;margin-top:1rem}
fieldset{border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;margin-top:1rem;min-width:0;overflow:hidden}
.settings-grid fieldset{margin-top:0}
legend{font-weight:600;font-size:.9rem;padding:0 .5rem}
label{display:block;font-size:.82rem;color:var(--muted);margin-top:.5rem}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.ekey-row{display:flex;gap:.5rem;align-items:center;margin-top:.35rem;min-width:0}
.ekey-code{font-size:.78rem;min-width:0;flex:1;background:var(--code-bg);padding:.35rem .5rem;border-radius:4px;line-height:1.4;word-break:break-all}
.install-cmd{white-space:pre-wrap;word-break:normal}
.ekey-row .btn-sm{flex-shrink:0}
input[type="number"]{width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem}
.alert-ok{background:color-mix(in srgb, var(--green) 15%, var(--card));border:1px solid var(--green);color:var(--green);padding:.5rem 1rem;border-radius:6px;font-size:.85rem;margin-bottom:1rem}
.alert-warn{background:color-mix(in srgb, var(--amber) 15%, var(--card));border:1px solid var(--amber);color:var(--amber);padding:.5rem 1rem;border-radius:6px;font-size:.85rem;margin-bottom:1rem}
.prev-keys{margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--card-border)}
.prev-key-row{display:flex;gap:.5rem;align-items:center;margin-top:.35rem;min-width:0}
.prev-key-row .ekey-code{opacity:.7}
.btn-sm-danger{background:var(--red)!important}
.btn-sm-danger:hover{opacity:.85}

/* ── Empty ── */
.empty{text-align:center;padding:3rem 1rem;color:var(--muted)}
.empty h2{color:var(--text);margin-bottom:.5rem}

@media(max-width:640px){
    .grid{grid-template-columns:1fr}
    .detail-meta{flex-direction:column;gap:.25rem}
    .settings-grid{grid-template-columns:1fr}
}
</style>
<script>
(function(){
    var t=localStorage.getItem('sermony-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
    document.documentElement.setAttribute('data-theme',t);
})();
</script>
</head>
<body>
<header><div class="wrap">
    <h1><span>&#9632;</span> Sermony</h1>
    <div class="hdr-links">
        <a href="?action=settings">Settings</a>
        <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">&#9790;</button>
    </div>
</div></header>
<main class="wrap">
<?php
}

function pageBottom(): void {
?>
</main>
<footer style="text-align:center;padding:2rem;color:var(--subtle);font-size:.75rem">Sermony v<?=VERSION?></footer>
<script>
function toggleTheme(){
    var h=document.documentElement,c=h.getAttribute('data-theme')==='dark'?'light':'dark';
    h.setAttribute('data-theme',c);localStorage.setItem('sermony-theme',c);
    document.getElementById('themeBtn').textContent=c==='dark'?'\u2600':'\u263E';
}
(function(){
    var c=document.documentElement.getAttribute('data-theme');
    document.getElementById('themeBtn').textContent=c==='dark'?'\u2600':'\u263E';
})();
function copyEl(id,btn){
    navigator.clipboard.writeText(document.getElementById(id).textContent).then(function(){
        btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy'},1500);
    });
}
/* Drag & drop */
(function(){
    var grid=document.getElementById('serverGrid');
    if(!grid)return;
    var dragged=null;
    grid.addEventListener('dragstart',function(e){
        var c=e.target.closest('.card[data-id]');if(!c)return;
        dragged=c;c.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/plain','');
    });
    grid.addEventListener('dragover',function(e){
        e.preventDefault();
        var c=e.target.closest('.card[data-id]');
        if(!c||c===dragged)return;
        var r=c.getBoundingClientRect();
        var after=e.clientY>r.top+r.height/2;
        grid.insertBefore(dragged,after?c.nextSibling:c);
    });
    grid.addEventListener('dragend',function(){
        if(dragged)dragged.classList.remove('dragging');
        dragged=null;
        var cards=grid.querySelectorAll('.card[data-id]');
        var order=[];
        cards.forEach(function(c,i){order.push({id:+c.dataset.id,pos:i+1})});
        fetch('?action=reorder',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(order)});
    });
})();
</script>
</body>
</html>
<?php
}

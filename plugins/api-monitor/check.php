#!/usr/bin/env php
<?php
/**
 * Sermony API Monitor — Cron Runner
 *
 * Run every minute: * * * * * php /path/to/plugins/api-monitor/check.php
 *
 * Safe to run every minute — only checks monitors when due.
 */

if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }

$dbFile = dirname(__DIR__, 2) . '/sermony.db';
if (!file_exists($dbFile)) { fwrite(STDERR, "Database not found: $dbFile\n"); exit(1); }

// Minimal DB access — no need to load the full app
$db = new SQLite3($dbFile);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA foreign_keys=ON');

function cronSetting(string $key): ?string {
    global $db;
    $s = $db->prepare('SELECT value FROM settings WHERE key=:k');
    $s->bindValue(':k', $key, SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r['value'] ?? null;
}

function cronRunCheck(array $mon): array {
    $ch = curl_init();
    $headers = [];
    $rawHeaders = trim($mon['request_headers'] ?? '');
    if ($rawHeaders !== '') foreach (explode("\n", $rawHeaders) as $h) { $h = trim($h); if ($h !== '') $headers[] = $h; }

    switch ($mon['auth_type'] ?? 'none') {
        case 'bearer': $headers[] = 'Authorization: Bearer ' . ($mon['auth_token'] ?? ''); break;
        case 'apikey': $headers[] = ($mon['api_key_header'] ?: 'X-API-Key') . ': ' . ($mon['api_key_value'] ?? ''); break;
        case 'basic': curl_setopt($ch, CURLOPT_USERPWD, ($mon['auth_username'] ?? '') . ':' . ($mon['auth_password'] ?? '')); break;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $mon['url'],
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

    $success = true; $error = '';
    if ($curlErr) { $success = false; $error = $curlErr; }
    elseif ($httpCode !== (int)($mon['expected_status'] ?? 200)) { $success = false; $error = "Expected HTTP {$mon['expected_status']}, got $httpCode"; }

    $expectedText = trim($mon['expected_text'] ?? '');
    if ($success && $expectedText !== '' && $body !== false && stripos($body, $expectedText) === false) {
        $success = false; $error = "Expected text not found in response";
    }

    $excerpt = $body !== false ? mb_substr($body, 0, 500) : '';
    return ['success' => $success, 'http_status' => $httpCode, 'response_time_ms' => $elapsedMs, 'error_message' => mb_substr($error, 0, 500), 'response_excerpt' => $excerpt];
}

function cronIsUrlSafe(string $url): bool {
    if (cronSetting('apimon_allow_private_ips') === '1') return true;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $ip = gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return true;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// ── Run checks ───────────────────────────────────────────────

$now = gmdate('Y-m-d\TH:i:s\Z');
$res = $db->query("SELECT * FROM api_monitors WHERE enabled=1 AND (next_run_at IS NULL OR next_run_at <= '$now')");
$checked = 0;

while ($mon = $res->fetchArray(SQLITE3_ASSOC)) {
    $id = (int)$mon['id'];
    if (!cronIsUrlSafe($mon['url'])) { fwrite(STDERR, "[{$mon['name']}] Skipped: private IP\n"); continue; }

    $result = cronRunCheck($mon);
    $checked++;

    // Save result
    $s = $db->prepare('INSERT INTO api_monitor_results (monitor_id, success, http_status, response_time_ms, error_message, response_excerpt) VALUES (:mid,:ok,:http,:ms,:err,:exc)');
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
    $nextRun = gmdate('Y-m-d\TH:i:s\Z', time() + ((int)$mon['interval_minutes']) * 60);

    $s = $db->prepare("UPDATE api_monitors SET last_run_at=:now, next_run_at=:next, last_status=:st, last_http_status=:http, last_response_time_ms=:ms, fail_streak=:fs, updated_at=:now WHERE id=:id");
    $s->bindValue(':now', $now); $s->bindValue(':next', $nextRun);
    $s->bindValue(':st', $status); $s->bindValue(':http', $result['http_status'], SQLITE3_INTEGER);
    $s->bindValue(':ms', $result['response_time_ms'], SQLITE3_INTEGER);
    $s->bindValue(':fs', $streak, SQLITE3_INTEGER);
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->execute();

    echo '[' . ($result['success'] ? 'OK' : 'FAIL') . "] {$mon['name']} — HTTP {$result['http_status']} {$result['response_time_ms']}ms";
    if (!$result['success']) echo " — {$result['error_message']}";
    echo "\n";
}

// ── Cleanup ──────────────────────────────────────────────────

$retDays = (int)(cronSetting('apimon_retention_days') ?? 7);
$cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $retDays * 86400);
$db->exec("DELETE FROM api_monitor_results WHERE checked_at < '$cutoff'");
$pruned = $db->changes();

if ($checked > 0 || $pruned > 0) echo "Done: $checked checks, $pruned pruned (>{$retDays}d)\n";

<?php
declare(strict_types=1);

/* ============================================================
 * Sermony — Server Monitoring Harmony
 * Single-file PHP 8+ application with SQLite backend
 * ============================================================ */

const VERSION      = '1.0.0';
const DB_FILE      = __DIR__ . '/sermony.db';
const OFFLINE_MULT = 2.5;
const HISTORY      = 200;
const SPARK_POINTS = 10;

const MAX_LOGIN_ATTEMPTS = 5;
const LOCKOUT_MINUTES    = 15;
const SESSION_LIFETIME   = 28800; // 8 hours

const DEFAULTS = [
    'enrollment_key'       => '',
    'interval_minutes'     => '15',
    'retention_days'       => '30',
    'api_ip_allowlist'     => '',  // comma-separated, empty = allow all
    'alert_cpu_warn'       => '75',
    'alert_cpu_crit'       => '90',
    'alert_mem_warn'       => '75',
    'alert_mem_crit'       => '90',
    'alert_disk_warn'      => '75',
    'alert_disk_crit'      => '90',
    'alert_mail_warn'      => '50',
    'alert_mail_crit'      => '200',
    'alert_email'          => '',
    'alert_webhook_url'    => '',
    'alert_cooldown_minutes'=> '30',
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
    // Add columns/tables for existing databases
    @$i->exec('ALTER TABLE servers ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
    @$i->exec('ALTER TABLE servers ADD COLUMN interval_minutes INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN notes TEXT DEFAULT ""');
    @$i->exec('ALTER TABLE servers ADD COLUMN display_name TEXT');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_cpu_warn INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_cpu_crit INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_mem_warn INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_mem_crit INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_disk_warn INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_disk_crit INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_mail_warn INTEGER');
    @$i->exec('ALTER TABLE servers ADD COLUMN alert_mail_crit INTEGER');
    @$i->exec('CREATE TABLE IF NOT EXISTS alert_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
        alert_type TEXT NOT NULL,
        sent_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        channel TEXT NOT NULL
    )');
    @$i->exec('CREATE TABLE IF NOT EXISTS login_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        success INTEGER NOT NULL DEFAULT 0,
        attempted_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
    // Block direct access to .db files
    $ht = dirname(DB_FILE) . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(db|db-wal|db-shm)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    return $i;
}

function migrate(SQLite3 $db): void {
    $db->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hostname TEXT NOT NULL, agent_key TEXT NOT NULL UNIQUE,
        public_ip TEXT, fqdn TEXT,
        created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        last_seen_at TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
        interval_minutes INTEGER, notes TEXT DEFAULT "", display_name TEXT,
        alert_cpu_warn INTEGER, alert_cpu_crit INTEGER,
        alert_mem_warn INTEGER, alert_mem_crit INTEGER,
        alert_disk_warn INTEGER, alert_disk_crit INTEGER,
        alert_mail_warn INTEGER, alert_mail_crit INTEGER
    )');
    $db->exec('CREATE TABLE metrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
        cpu_usage REAL, memory_usage REAL, memory_total_mb INTEGER,
        disk_usage REAL, disk_iops REAL, network_rx_bps INTEGER, network_tx_bps INTEGER,
        mail_queue INTEGER, load_1 REAL, load_5 REAL, load_15 REAL,
        collected_at TEXT NOT NULL,
        received_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
    $db->exec('CREATE INDEX idx_metrics_lookup ON metrics(server_id, received_at DESC)');
    $db->exec('CREATE TABLE alert_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        server_id INTEGER NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
        alert_type TEXT NOT NULL,
        sent_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        channel TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE login_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL, success INTEGER NOT NULL DEFAULT 0,
        attempted_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
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

function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function jsonOut(array $d, int $c = 200): never { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
function jsonErr(string $m, int $c = 400): never { jsonOut(['error' => $m], $c); }

function fmtBytes(int $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $b = max(0, $b);
    $p = $b > 0 ? (int)floor(log($b, 1024)) : 0; $p = min($p, 4);
    return round($b / (1024 ** $p), 1) . ' ' . $u[$p];
}

function timeAgo(?string $t): string {
    if (!$t) return 'Never'; $d = time() - strtotime($t);
    if ($d < 0) return 'just now'; if ($d < 60) return $d . 's ago';
    if ($d < 3600) return (int)floor($d / 60) . 'm ago';
    if ($d < 86400) return (int)floor($d / 3600) . 'h ago';
    return (int)floor($d / 86400) . 'd ago';
}

function isOnline(?string $t, ?int $si = null): bool {
    if (!$t) return false;
    return (time() - strtotime($t)) < (($si ?? (int)(setting('interval_minutes') ?? 15)) * OFFLINE_MULT * 60);
}

function isStale(?string $t, ?int $si = null): bool {
    if (!$t) return false;
    $int = $si ?? (int)(setting('interval_minutes') ?? 15);
    $elapsed = time() - strtotime($t); $th = $int * 60;
    return $elapsed >= ($th * 1.5) && $elapsed < ($th * OFFLINE_MULT);
}

/** Get threshold for a metric type, with optional per-server override */
function threshold(string $type, string $level, ?array $srv = null): float {
    if ($srv && isset($srv["alert_{$type}_{$level}"]) && $srv["alert_{$type}_{$level}"] !== null) {
        return (float)$srv["alert_{$type}_{$level}"];
    }
    return (float)(setting("alert_{$type}_{$level}") ?? ($level === 'crit' ? 90 : 75));
}

function mColorFor(string $type, float $v, ?array $srv = null): string {
    if ($v >= threshold($type, 'crit', $srv)) return 'var(--red)';
    if ($v >= threshold($type, 'warn', $srv)) return 'var(--amber)';
    return 'var(--green)';
}

function serverHealth(array $srv): string {
    $dom = 'ok';
    foreach (['cpu'=>'cpu_usage','mem'=>'memory_usage','disk'=>'disk_usage','mail'=>'mail_queue'] as $type=>$col) {
        if ($srv[$col] === null) continue; $v = (float)$srv[$col];
        if ($v >= threshold($type, 'crit', $srv)) return 'crit';
        if ($v >= threshold($type, 'warn', $srv)) $dom = 'warn';
    }
    return $dom;
}

function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-server') . strtok($_SERVER['REQUEST_URI'], '?');
}

/** Get last N metric values per server for sparklines */
function getSparkData(array $serverIds): array {
    if (empty($serverIds)) return [];
    $d = db(); $result = [];
    $s = $d->prepare('SELECT cpu_usage, memory_usage, disk_usage FROM metrics WHERE server_id=:id ORDER BY received_at DESC LIMIT :n');
    foreach ($serverIds as $id) {
        $s->bindValue(':id', $id, SQLITE3_INTEGER);
        $s->bindValue(':n', SPARK_POINTS, SQLITE3_INTEGER);
        $res = $s->execute(); $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        $result[$id] = array_reverse($rows);
        $s->reset();
    }
    return $result;
}

/** Render an inline SVG sparkline bar chart */
function sparkSvg(array $values, string $type, ?array $srv = null): string {
    $n = count($values); if ($n === 0) return '';
    $w = 80; $h = 24; $gap = 1;
    $bw = max(1, ($w - ($n - 1) * $gap) / $n);
    $bars = '';
    foreach ($values as $i => $v) {
        $val = $v === null ? 0 : min((float)$v, 100);
        $bh = max(1, $val / 100 * $h);
        $x = round($i * ($bw + $gap), 1);
        $y = round($h - $bh, 1);
        $col = $val >= threshold($type, 'crit', $srv) ? 'var(--red)' :
              ($val >= threshold($type, 'warn', $srv) ? 'var(--amber)' : 'var(--green)');
        $bars .= "<rect x=\"$x\" y=\"$y\" width=\"" . round($bw, 1) . "\" height=\"$bh\" fill=\"$col\" rx=\"1\"/>";
    }
    return "<svg class=\"spark\" viewBox=\"0 0 $w $h\" preserveAspectRatio=\"none\">$bars</svg>";
}

// ─── Alerts ──────────────────────────────────────────────────

function shouldAlert(int $serverId, string $alertType): bool {
    $cooldown = (int)(setting('alert_cooldown_minutes') ?? 30);
    $s = db()->prepare("SELECT COUNT(*) as c FROM alert_log WHERE server_id=:id AND alert_type=:t AND sent_at > strftime('%Y-%m-%dT%H:%M:%SZ','now',:mins)");
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':t', $alertType, SQLITE3_TEXT);
    $s->bindValue(':mins', '-' . $cooldown . ' minutes', SQLITE3_TEXT);
    return (int)$s->execute()->fetchArray()['c'] === 0;
}

function logAlert(int $serverId, string $alertType, string $channel): void {
    $s = db()->prepare('INSERT INTO alert_log (server_id, alert_type, channel) VALUES (:id,:t,:c)');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':t', $alertType, SQLITE3_TEXT);
    $s->bindValue(':c', $channel, SQLITE3_TEXT);
    $s->execute();
}

function sendAlerts(int $serverId, string $hostname, string $alertType, string $message): void {
    $email = trim(setting('alert_email') ?? '');
    $webhook = trim(setting('alert_webhook_url') ?? '');
    if ($email === '' && $webhook === '') return;
    if (!shouldAlert($serverId, $alertType)) return;

    if ($email !== '') {
        $subject = "Sermony Alert: $hostname - $alertType";
        @mail($email, $subject, $message, "From: sermony@" . gethostname());
        logAlert($serverId, $alertType, 'email');
    }
    if ($webhook !== '') {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => 'Content-Type: application/json',
            'content' => json_encode(['hostname' => $hostname, 'alert' => $alertType, 'message' => $message, 'timestamp' => gmdate('c')]),
            'timeout' => 5,
        ]]);
        @file_get_contents($webhook, false, $ctx);
        logAlert($serverId, $alertType, 'webhook');
    }
}

function checkAlerts(int $serverId, string $hostname, array $metrics, ?array $srv = null): void {
    foreach (['cpu'=>'cpu_usage','mem'=>'memory_usage','disk'=>'disk_usage','mail'=>'mail_queue'] as $type=>$col) {
        if (!isset($metrics[$col])) continue;
        $v = (float)$metrics[$col];
        $crit = threshold($type, 'crit', $srv);
        if ($v >= $crit) {
            sendAlerts($serverId, $hostname, "{$type}_crit", "$hostname: $col at $v% (threshold: $crit%)");
        }
    }
}

// ─── Auth ────────────────────────────────────────────────────

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly'  => true,
    'samesite'  => 'Strict',
]);
session_start();

function isLoggedIn(): bool {
    if (empty($_SESSION['sermony_auth'])) return false;
    // Session timeout
    if (isset($_SESSION['sermony_last_active']) && (time() - $_SESSION['sermony_last_active']) > SESSION_LIFETIME) {
        $_SESSION = [];
        return false;
    }
    $_SESSION['sermony_last_active'] = time();
    return true;
}

function requireAuth(): void {
    $pw = setting('admin_password_hash');
    if (!$pw || $pw === '') return;
    if (isLoggedIn()) return;
    showLogin(); exit;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . csrfToken() . '">';
}

function verifyCsrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Invalid or missing CSRF token. <a href="?">Go back</a>');
    }
}

function clientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isLoginLocked(): bool {
    $ip = clientIp();
    $s = db()->prepare("SELECT COUNT(*) as c FROM login_log WHERE ip=:ip AND success=0 AND attempted_at > strftime('%Y-%m-%dT%H:%M:%SZ','now',:mins)");
    $s->bindValue(':ip', $ip, SQLITE3_TEXT);
    $s->bindValue(':mins', '-' . LOCKOUT_MINUTES . ' minutes', SQLITE3_TEXT);
    return (int)$s->execute()->fetchArray()['c'] >= MAX_LOGIN_ATTEMPTS;
}

function logLoginAttempt(bool $success): void {
    $s = db()->prepare('INSERT INTO login_log (ip, success) VALUES (:ip, :ok)');
    $s->bindValue(':ip', clientIp(), SQLITE3_TEXT);
    $s->bindValue(':ok', $success ? 1 : 0, SQLITE3_INTEGER);
    $s->execute();
}

function checkApiIpAllowlist(): void {
    $list = trim(setting('api_ip_allowlist') ?? '');
    if ($list === '') return;
    $allowed = array_map('trim', explode(',', $list));
    $ip = clientIp();
    foreach ($allowed as $a) { if ($a === $ip) return; }
    jsonErr('IP not allowed', 403);
}

// ─── Router ──────────────────────────────────────────────────

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");

$action = $_GET['action'] ?? 'dashboard';

// Public routes (API + scripts) — no auth required
match ($action) {
    'enroll'         => (function(){ checkApiIpAllowlist(); handleEnroll(); })(),
    'ingest'         => (function(){ checkApiIpAllowlist(); handleIngest(); })(),
    'agent-config'   => (function(){ checkApiIpAllowlist(); handleAgentConfig(); })(),
    'install-script' => serveScript('install.sh'),
    'agent-script'   => serveScript('sermony-agent.sh'),
    default          => null,
};

// Auth routes
if ($action === 'login') { handleLogin(); }
if ($action === 'logout') { handleLogout(); }
if ($action === 'setup-password') { handleSetupPassword(); }

// First-run: if no password set, force setup
$pwHash = setting('admin_password_hash');
if (!$pwHash || $pwHash === '') {
    if ($action !== 'setup-password') { showSetupPassword(); exit; }
}

// All remaining routes require login
requireAuth();

match ($action) {
    'delete'         => handleDelete(),
    'reorder'        => handleReorder(),
    'update-server'  => handleUpdateServer(),
    'rotate-agent-key' => handleRotateAgentKey(),
    'dashboard-json' => handleDashboardJson(),
    'export-csv'     => handleExportCsv(),
    'settings'       => ($_SERVER['REQUEST_METHOD'] === 'POST' ? handleSettings() : showSettings()),
    'server'         => showServer(),
    default          => showDashboard(),
};

// ─── Auth Handlers ───────────────────────────────────────────

function handleLogin(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { showLogin(); exit; }
    verifyCsrf();
    if (isLoginLocked()) {
        showLogin('Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minutes.'); exit;
    }
    $pw = $_POST['password'] ?? '';
    $hash = setting('admin_password_hash');
    if ($hash && password_verify($pw, $hash)) {
        logLoginAttempt(true);
        session_regenerate_id(true);
        $_SESSION['sermony_auth'] = true;
        $_SESSION['sermony_last_active'] = time();
        header('Location: ?'); exit;
    }
    logLoginAttempt(false);
    showLogin('Invalid password.'); exit;
}

function handleLogout(): never {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ?action=login'); exit;
}

function handleSetupPassword(): never {
    $existing = setting('admin_password_hash');
    if ($existing && $existing !== '' && !isLoggedIn()) { header('Location: ?action=login'); exit; }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { showSetupPassword(); exit; }
    verifyCsrf();
    $pw = $_POST['password'] ?? '';
    $pw2 = $_POST['password_confirm'] ?? '';
    if (strlen($pw) < 6) { showSetupPassword('Password must be at least 6 characters.'); exit; }
    if ($pw !== $pw2) { showSetupPassword('Passwords do not match.'); exit; }

    setSetting('admin_password_hash', password_hash($pw, PASSWORD_BCRYPT));
    $_SESSION['sermony_auth'] = true;
    header('Location: ?'); exit;
}

function showLogin(string $error = ''): never {
    pageTop('Login');
    ?>
    <div class="auth-box">
        <h2>Sign in to Sermony</h2>
        <?php if ($error): ?><div class="alert-warn"><?=e($error)?></div><?php endif; ?>
        <form method="post" action="?action=login">
            <?=csrfField()?>
            <label>Password
                <input type="password" name="password" autofocus required>
            </label>
            <button type="submit" class="btn-primary" style="width:100%;margin-top:1rem">Sign In</button>
        </form>
    </div>
    <?php
    pageBottom(); exit;
}

function showSetupPassword(string $error = ''): never {
    pageTop('Setup');
    ?>
    <div class="auth-box">
        <h2>Set Admin Password</h2>
        <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">This password protects the Sermony dashboard. Set it now to get started.</p>
        <?php if ($error): ?><div class="alert-warn"><?=e($error)?></div><?php endif; ?>
        <form method="post" action="?action=setup-password">
            <?=csrfField()?>
            <label>Password
                <input type="password" name="password" minlength="6" autofocus required>
            </label>
            <label>Confirm Password
                <input type="password" name="password_confirm" minlength="6" required>
            </label>
            <button type="submit" class="btn-primary" style="width:100%;margin-top:1rem">Set Password</button>
        </form>
    </div>
    <?php
    pageBottom(); exit;
}

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
        foreach (json_decode(setting('previous_keys') ?? '[]', true) as $pk)
            if (hash_equals($pk, $ekey)) { $valid = true; break; }
    }
    if (!$valid) jsonErr('Invalid enrollment key', 403);

    $d = db(); $interval = (int)(setting('interval_minutes') ?? 15);
    $s = $d->prepare('SELECT agent_key, interval_minutes AS si FROM servers WHERE hostname=:h');
    $s->bindValue(':h', $host, SQLITE3_TEXT);
    $row = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) jsonOut(['agent_key' => $row['agent_key'], 'interval' => (int)($row['si'] ?? $interval)]);

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
    $s = $d->prepare('SELECT * FROM servers WHERE agent_key=:k');
    $s->bindValue(':k', $ak, SQLITE3_TEXT);
    $srv = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$srv) jsonErr('Unknown agent', 403);
    $id = (int)$srv['id'];

    $s = $d->prepare("UPDATE servers SET public_ip=:ip, fqdn=:f, hostname=:h, last_seen_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE id=:id");
    $s->bindValue(':ip', isset($in['public_ip']) ? (string)$in['public_ip'] : null);
    $s->bindValue(':f', isset($in['fqdn']) ? (string)$in['fqdn'] : null);
    $s->bindValue(':h', trim((string)($in['hostname'] ?? '')));
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->execute();

    $s = $d->prepare('INSERT INTO metrics (server_id,cpu_usage,memory_usage,memory_total_mb,disk_usage,disk_iops,network_rx_bps,network_tx_bps,mail_queue,load_1,load_5,load_15,collected_at) VALUES (:sid,:cpu,:mem,:memt,:disk,:iops,:nrx,:ntx,:mail,:l1,:l5,:l15,:ts)');
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

    // Check alert thresholds
    checkAlerts($id, $srv['hostname'], $in, $srv);

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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?'); exit; }
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) { $s = db()->prepare('DELETE FROM servers WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute(); }
    header('Location: ?'); exit;
}

function handleSettings(): never {
    verifyCsrf();
    $intFields = ['interval_minutes','retention_days','alert_cpu_warn','alert_cpu_crit','alert_mem_warn','alert_mem_crit','alert_disk_warn','alert_disk_crit','alert_mail_warn','alert_mail_crit','alert_cooldown_minutes'];
    foreach ($intFields as $k) { if (isset($_POST[$k])) setSetting($k, (string)max(1, (int)$_POST[$k])); }
    // String settings
    foreach (['alert_email', 'alert_webhook_url', 'api_ip_allowlist'] as $k) { if (isset($_POST[$k])) setSetting($k, trim((string)$_POST[$k])); }

    if (!empty($_POST['regenerate_key'])) {
        $old = setting('enrollment_key');
        $prev = json_decode(setting('previous_keys') ?? '[]', true);
        $prev[] = $old;
        setSetting('previous_keys', json_encode($prev));
        setSetting('enrollment_key', bin2hex(random_bytes(16)));
        header('Location: ?action=settings&saved=1&regenerated=1'); exit;
    }
    if (!empty($_POST['invalidate_key'])) {
        $prev = json_decode(setting('previous_keys') ?? '[]', true);
        $prev = array_values(array_filter($prev, fn($k) => $k !== $_POST['invalidate_key']));
        setSetting('previous_keys', json_encode($prev));
        header('Location: ?action=settings&saved=1'); exit;
    }
    if (!empty($_POST['invalidate_all_keys'])) {
        setSetting('previous_keys', '[]');
        header('Location: ?action=settings&saved=1'); exit;
    }
    header('Location: ?action=settings&saved=1'); exit;
}

function handleReorder(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('POST required', 405);
    // CSRF via header for JSON requests
    $hdrToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdrToken || !hash_equals(csrfToken(), $hdrToken)) jsonErr('Invalid CSRF token', 403);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) jsonErr('Invalid JSON');
    $d = db(); $s = $d->prepare('UPDATE servers SET sort_order=:pos WHERE id=:id');
    foreach ($in as $item) { $s->bindValue(':id',(int)($item['id']??0),SQLITE3_INTEGER); $s->bindValue(':pos',(int)($item['pos']??0),SQLITE3_INTEGER); $s->execute(); $s->reset(); }
    jsonOut(['ok' => true]);
}

function handleUpdateServer(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?'); exit; }
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { header('Location: ?'); exit; }
    $d = db();
    $s = $d->prepare('UPDATE servers SET display_name=:dn, interval_minutes=:int, notes=:notes,
        alert_cpu_warn=:cw, alert_cpu_crit=:cc, alert_mem_warn=:mw, alert_mem_crit=:mc,
        alert_disk_warn=:dw, alert_disk_crit=:dc, alert_mail_warn=:qw, alert_mail_crit=:qc WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $dn = trim((string)($_POST['display_name'] ?? ''));
    $s->bindValue(':dn', $dn !== '' ? $dn : null);
    $intVal = $_POST['interval_minutes'] ?? '';
    $s->bindValue(':int', $intVal !== '' ? max(1, (int)$intVal) : null);
    $s->bindValue(':notes', (string)($_POST['notes'] ?? ''), SQLITE3_TEXT);
    foreach ([':cw'=>'alert_cpu_warn',':cc'=>'alert_cpu_crit',':mw'=>'alert_mem_warn',':mc'=>'alert_mem_crit',
              ':dw'=>'alert_disk_warn',':dc'=>'alert_disk_crit',':qw'=>'alert_mail_warn',':qc'=>'alert_mail_crit'] as $bind=>$field) {
        $val = $_POST[$field] ?? '';
        $s->bindValue($bind, $val !== '' ? max(1, (int)$val) : null);
    }
    $s->execute();
    header('Location: ?action=server&id=' . $id . '&saved=1'); exit;
}

function handleAgentConfig(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('POST required', 405);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!$in) jsonErr('Invalid JSON');
    $ak = (string)($in['agent_key'] ?? '');
    if ($ak === '') jsonErr('Missing agent_key', 401);
    $d = db();
    $s = $d->prepare('SELECT id, interval_minutes FROM servers WHERE agent_key=:k');
    $s->bindValue(':k', $ak, SQLITE3_TEXT);
    $srv = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$srv) jsonErr('Unknown agent', 403);
    $interval = (int)($srv['interval_minutes'] ?? setting('interval_minutes') ?? 15);
    jsonOut(['interval' => $interval]);
}

function handleRotateAgentKey(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?'); exit; }
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id < 1) { header('Location: ?'); exit; }
    $newKey = bin2hex(random_bytes(32));
    $s = db()->prepare('UPDATE servers SET agent_key=:k WHERE id=:id');
    $s->bindValue(':k', $newKey, SQLITE3_TEXT);
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $s->execute();
    header('Location: ?action=server&id=' . $id . '&key_rotated=1'); exit;
}

function handleExportCsv(): never {
    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) { header('Location: ?'); exit; }
    $d = db();
    $s = $d->prepare('SELECT hostname FROM servers WHERE id=:id');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $srv = $s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$srv) { header('Location: ?'); exit; }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $srv['hostname']) . '-metrics.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Time','CPU %','Mem %','Mem MB','Disk %','IOPS','Net RX B/s','Net TX B/s','Mail Queue','Load 1','Load 5','Load 15']);

    $s = $d->prepare('SELECT * FROM metrics WHERE server_id=:id ORDER BY received_at DESC');
    $s->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $s->execute();
    while ($m = $res->fetchArray(SQLITE3_ASSOC)) {
        fputcsv($out, [$m['collected_at'],$m['cpu_usage'],$m['memory_usage'],$m['memory_total_mb'],$m['disk_usage'],$m['disk_iops'],$m['network_rx_bps'],$m['network_tx_bps'],$m['mail_queue'],$m['load_1'],$m['load_5'],$m['load_15']]);
    }
    fclose($out); exit;
}

/** JSON endpoint for auto-refresh */
function handleDashboardJson(): never {
    $d = db(); $servers = [];
    $res = $d->query('SELECT s.*, m.cpu_usage, m.memory_usage, m.memory_total_mb, m.disk_usage, m.disk_iops, m.network_rx_bps, m.network_tx_bps, m.mail_queue, m.load_1, m.load_5, m.load_15
        FROM servers s LEFT JOIN metrics m ON m.id = (SELECT id FROM metrics WHERE server_id = s.id ORDER BY received_at DESC LIMIT 1) ORDER BY s.sort_order, s.hostname');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $servers[] = $row;

    $spark = getSparkData(array_column($servers, 'id'));
    $out = [];
    foreach ($servers as $srv) {
        $si = $srv['interval_minutes'] !== null ? (int)$srv['interval_minutes'] : null;
        $on = isOnline($srv['last_seen_at'], $si);
        $stale = $on && isStale($srv['last_seen_at'], $si);
        $health = serverHealth($srv);

        // Check for offline alerts
        if (!$on && $srv['last_seen_at']) {
            sendAlerts((int)$srv['id'], $srv['hostname'], 'offline', $srv['hostname'] . ' is OFFLINE (last seen: ' . timeAgo($srv['last_seen_at']) . ')');
        }

        $sp = $spark[$srv['id']] ?? [];
        $out[] = [
            'id' => (int)$srv['id'], 'hostname' => $srv['hostname'],
            'public_ip' => $srv['public_ip'], 'fqdn' => $srv['fqdn'],
            'online' => $on, 'stale' => $stale, 'health' => $health,
            'last_seen' => timeAgo($srv['last_seen_at']),
            'cpu' => $srv['cpu_usage'], 'mem' => $srv['memory_usage'],
            'mem_mb' => $srv['memory_total_mb'], 'disk' => $srv['disk_usage'],
            'iops' => $srv['disk_iops'],
            'net_rx' => $srv['network_rx_bps'] !== null ? fmtBytes((int)$srv['network_rx_bps']) . '/s' : null,
            'net_tx' => $srv['network_tx_bps'] !== null ? fmtBytes((int)$srv['network_tx_bps']) . '/s' : null,
            'mail' => $srv['mail_queue'], 'load' => $srv['load_1'],
            'spark_cpu' => array_column($sp, 'cpu_usage'),
            'spark_mem' => array_column($sp, 'memory_usage'),
            'spark_disk' => array_column($sp, 'disk_usage'),
        ];
    }
    jsonOut(['servers' => $out, 'ts' => time()]);
}

function serveScript(string $file): never {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) { http_response_code(404); echo 'File not found.'; exit; }
    header('Content-Type: text/plain; charset=utf-8'); readfile($path); exit;
}

// ─── Dashboard ───────────────────────────────────────────────

function showDashboard(): never {
    $d = db(); $servers = [];
    $res = $d->query('SELECT s.*, m.cpu_usage, m.memory_usage, m.memory_total_mb, m.disk_usage, m.disk_iops, m.network_rx_bps, m.network_tx_bps, m.mail_queue, m.load_1, m.load_5, m.load_15
        FROM servers s LEFT JOIN metrics m ON m.id = (SELECT id FROM metrics WHERE server_id = s.id ORDER BY received_at DESC LIMIT 1) ORDER BY s.sort_order, s.hostname');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $servers[] = $row;

    $counts = ['total' => count($servers), 'online' => 0, 'offline' => 0, 'crit' => 0, 'warn' => 0, 'stale' => 0];
    $hasCustomOrder = false;
    foreach ($servers as &$srv) {
        $si = $srv['interval_minutes'] !== null ? (int)$srv['interval_minutes'] : null;
        $srv['_online'] = isOnline($srv['last_seen_at'], $si);
        $srv['_stale'] = $srv['_online'] && isStale($srv['last_seen_at'], $si);
        $srv['_health'] = serverHealth($srv);
        if ($srv['_online']) $counts['online']++; else $counts['offline']++;
        if ($srv['_stale']) $counts['stale']++;
        if ($srv['_health'] === 'crit') $counts['crit']++;
        elseif ($srv['_health'] === 'warn') $counts['warn']++;
        if ((int)$srv['sort_order'] !== 0) $hasCustomOrder = true;
    }
    unset($srv);

    if (!$hasCustomOrder) {
        usort($servers, function ($a, $b) {
            $pa = $a['_online'] ? ($a['_stale'] ? 2 : (['crit'=>0,'warn'=>1][$a['_health']] ?? 5)) : 3;
            $pb = $b['_online'] ? ($b['_stale'] ? 2 : (['crit'=>0,'warn'=>1][$b['_health']] ?? 5)) : 3;
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
    <div class="empty"><h2>No servers yet</h2><p>Go to <a href="?action=settings">Settings</a> for the install command to add your first server.</p></div>
    <?php else: ?>
    <div class="grid" id="serverGrid">
        <?php foreach ($servers as $srv):
            $on = $srv['_online']; $stale = $srv['_stale']; $health = $srv['_health'];
            $cpu=$srv['cpu_usage']; $mem=$srv['memory_usage']; $disk=$srv['disk_usage'];
            $cls = 'card';
            if (!$on) $cls .= ' card-offline';
            elseif ($health==='crit') $cls .= ' card-crit';
            elseif ($health==='warn') $cls .= ' card-warn';
            elseif ($stale) $cls .= ' card-stale';
        ?>
        <div class="<?=$cls?>" data-id="<?=$srv['id']?>" draggable="true">
            <div class="card-head">
                <span class="dot <?=$on ? ($stale ? 'dot-stale' : 'dot-on') : 'dot-off'?>"></span>
                <a href="?action=server&id=<?=$srv['id']?>" class="card-hostname" <?php if(!empty($srv['notes'])):?>title="<?=e($srv['notes'])?>"<?php endif;?>><?=e($srv['display_name'] ?: $srv['hostname'])?></a>
                <?php if (!$on): ?><span class="badge badge-off">OFFLINE</span>
                <?php elseif ($health==='crit'): ?><span class="badge badge-crit">CRITICAL</span>
                <?php elseif ($health==='warn'): ?><span class="badge badge-warn">WARNING</span>
                <?php elseif ($stale): ?><span class="badge badge-stale">STALE</span>
                <?php endif; ?>
                <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?=e($srv['display_name'] ?: $srv['hostname'])?> and all its metrics?')">
                    <?=csrfField()?><input type="hidden" name="id" value="<?=$srv['id']?>">
                    <button type="submit" class="btn-del" title="Delete server">&times;</button>
                </form>
            </div>
            <div class="card-meta"><?=e($srv['public_ip'] ?: "\xE2\x80\x94")?><?php if ($srv['fqdn']): ?> &middot; <?=e($srv['fqdn'])?><?php endif; ?></div>
            <div class="metrics">
                <div class="m"><span class="ml">CPU</span><span class="mv"><?=$cpu!==null ? number_format((float)$cpu,1).'%' : "\xE2\x80\x94"?></span><?php if ($cpu!==null):?><div class="bar"><div style="width:<?=min((float)$cpu,100)?>%;background:<?=mColorFor('cpu',(float)$cpu,$srv)?>"></div></div><?php endif;?></div>
                <div class="m"><span class="ml">Memory</span><span class="mv"><?=$mem!==null ? number_format((float)$mem,1).'%' : "\xE2\x80\x94"?><?php if($srv['memory_total_mb']):?> <small>(<?=number_format((int)$srv['memory_total_mb'])?>MB)</small><?php endif;?></span><?php if($mem!==null):?><div class="bar"><div style="width:<?=min((float)$mem,100)?>%;background:<?=mColorFor('mem',(float)$mem,$srv)?>"></div></div><?php endif;?></div>
                <div class="m"><span class="ml">Disk</span><span class="mv"><?=$disk!==null ? number_format((float)$disk,1).'%' : "\xE2\x80\x94"?></span><?php if($disk!==null):?><div class="bar"><div style="width:<?=min((float)$disk,100)?>%;background:<?=mColorFor('disk',(float)$disk,$srv)?>"></div></div><?php endif;?></div>
                <div class="m"><span class="ml">IOPS</span><span class="mv"><?=$srv['disk_iops']!==null ? number_format((float)$srv['disk_iops'],0) : "\xE2\x80\x94"?></span></div>
                <div class="m"><span class="ml">Net &#8595;</span><span class="mv"><?=$srv['network_rx_bps']!==null ? fmtBytes((int)$srv['network_rx_bps']).'/s' : "\xE2\x80\x94"?></span></div>
                <div class="m"><span class="ml">Net &#8593;</span><span class="mv"><?=$srv['network_tx_bps']!==null ? fmtBytes((int)$srv['network_tx_bps']).'/s' : "\xE2\x80\x94"?></span></div>
                <div class="m"><span class="ml">Mail Q</span><span class="mv" <?php if($srv['mail_queue']!==null):?>style="color:<?=mColorFor('mail',(float)$srv['mail_queue'],$srv)?>"<?php endif;?>><?=$srv['mail_queue']!==null ? (int)$srv['mail_queue'] : "\xE2\x80\x94"?></span></div>
                <div class="m"><span class="ml">Load</span><span class="mv"><?=$srv['load_1']!==null ? number_format((float)$srv['load_1'],2) : "\xE2\x80\x94"?></span></div>
            </div>
            <div class="card-foot"><?=$on ? ($stale ? 'Stale' : 'Online') : 'Offline'?> &middot; <?=timeAgo($srv['last_seen_at'])?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif;
    pageBottom(); exit;
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

    $si = $srv['interval_minutes'] !== null ? (int)$srv['interval_minutes'] : null;
    $on = isOnline($srv['last_seen_at'], $si);
    $s = $d->prepare('SELECT * FROM metrics WHERE server_id=:id ORDER BY received_at DESC LIMIT :lim');
    $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->bindValue(':lim', HISTORY, SQLITE3_INTEGER);
    $res = $s->execute(); $metrics = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $metrics[] = $row;

    pageTop(e($srv['display_name'] ?: $srv['hostname']));
    ?>
    <div class="detail-header">
        <a href="?" class="back">&larr; Dashboard</a>
        <div class="detail-title">
            <span class="dot <?=$on ? 'dot-on' : 'dot-off'?>"></span>
            <h1><?=e($srv['display_name'] ?: $srv['hostname'])?></h1>
        </div>
        <div class="detail-meta">
            <span><strong>Hostname:</strong> <?=e($srv['hostname'])?></span>
            <span><strong>IP:</strong> <?=e($srv['public_ip'] ?: "\xE2\x80\x94")?></span>
            <span><strong>FQDN:</strong> <?=e($srv['fqdn'] ?: "\xE2\x80\x94")?></span>
            <span><strong>Status:</strong> <?=$on ? 'Online' : 'Offline'?></span>
            <span><strong>Enrolled:</strong> <?=e(substr($srv['created_at'],0,16))?></span>
            <span><strong>Last seen:</strong> <?=timeAgo($srv['last_seen_at'])?></span>
            <span><strong>Interval:</strong> <?=$srv['interval_minutes']!==null ? $srv['interval_minutes'].'m (custom)' : (setting('interval_minutes')??15).'m (global)'?></span>
        </div>
        <?php if (isset($_GET['saved'])): ?><div class="alert-ok" style="margin-top:.5rem">Server settings saved.</div><?php endif; ?>
        <form method="post" action="?action=update-server">
            <?=csrfField()?><input type="hidden" name="id" value="<?=$srv['id']?>">
            <div class="server-settings">
                <div class="field-row">
                    <label>Display Name
                        <input type="text" name="display_name" value="<?=e($srv['display_name'] ?? '')?>" placeholder="<?=e($srv['hostname'])?>">
                    </label>
                    <label>Interval (min)
                        <input type="number" name="interval_minutes" value="<?=e($srv['interval_minutes'] ?? '')?>" placeholder="<?=e(setting('interval_minutes')??'15')?>" min="1" max="1440">
                    </label>
                </div>
                <label>Notes
                    <textarea name="notes" rows="3" placeholder="Add notes about this server..."><?=e($srv['notes'] ?? '')?></textarea>
                </label>
                <div class="srv-thresholds">
                    <p class="thresh-hint">Alert thresholds (leave empty to use global defaults)</p>
                    <div class="settings-grid" style="margin-top:.5rem">
                        <?php foreach ([
                            'cpu'  => ['CPU %', 100],
                            'mem'  => ['Memory %', 100],
                            'disk' => ['Disk %', 100],
                            'mail' => ['Mail Queue', 100000],
                        ] as $type => [$label, $max]): ?>
                        <fieldset style="margin-top:0">
                            <legend><?=$label?></legend>
                            <div class="field-row">
                                <label>Warn<input type="number" name="alert_<?=$type?>_warn" value="<?=e($srv["alert_{$type}_warn"] ?? '')?>" placeholder="<?=e(setting("alert_{$type}_warn"))?>" min="1" max="<?=$max?>"></label>
                                <label>Crit<input type="number" name="alert_<?=$type?>_crit" value="<?=e($srv["alert_{$type}_crit"] ?? '')?>" placeholder="<?=e(setting("alert_{$type}_crit"))?>" min="1" max="<?=$max?>"></label>
                            </div>
                        </fieldset>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary" style="margin-top:.75rem">Save Settings</button>
            </div>
        </form>
        <?php if (isset($_GET['key_rotated'])): ?><div class="alert-warn" style="margin-top:.5rem">Agent key rotated. The agent on this server must be re-enrolled with the new key.</div><?php endif; ?>
        <div style="display:flex;gap:.5rem;margin-top:.75rem;flex-wrap:wrap">
            <a href="?action=export-csv&id=<?=$srv['id']?>" class="btn-secondary">Export CSV</a>
            <form method="post" action="?action=rotate-agent-key" onsubmit="return confirm('Rotate agent key for <?=e($srv['hostname'])?>?\n\nThe current agent will stop authenticating until re-enrolled.')">
                <?=csrfField()?><input type="hidden" name="id" value="<?=$srv['id']?>">
                <button type="submit" class="btn-secondary" style="color:var(--amber)">Rotate Agent Key</button>
            </form>
            <form method="post" action="?action=delete" onsubmit="return confirm('Delete <?=e($srv['display_name'] ?: $srv['hostname'])?> and all its metrics?')">
                <?=csrfField()?><input type="hidden" name="id" value="<?=$srv['id']?>">
                <button type="submit" class="btn-danger">Delete Server</button>
            </form>
        </div>
    </div>

    <?php if (empty($metrics)): ?>
    <p class="empty">No metrics recorded yet.</p>
    <?php else: ?>
    <?php
        // Sparkline charts from metrics history (last 30 points for more detail)
        $sparkSlice = array_reverse(array_slice($metrics, 0, 30));
    ?>
    <div class="detail-sparks">
        <div class="detail-spark">
            <span class="spark-label">CPU %</span>
            <?=sparkSvg(array_column($sparkSlice, 'cpu_usage'), 'cpu', $srv)?>
        </div>
        <div class="detail-spark">
            <span class="spark-label">Memory %</span>
            <?=sparkSvg(array_column($sparkSlice, 'memory_usage'), 'mem', $srv)?>
        </div>
        <div class="detail-spark">
            <span class="spark-label">Disk %</span>
            <?=sparkSvg(array_column($sparkSlice, 'disk_usage'), 'disk', $srv)?>
        </div>
    </div>
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
                <td data-label="Time"><?=e(substr($m['collected_at'],0,16))?></td>
                <td data-label="CPU %" style="color:<?=$m['cpu_usage']!==null ? mColorFor('cpu',(float)$m['cpu_usage'],$srv) : 'var(--muted)'?>"><?=$m['cpu_usage']!==null ? number_format((float)$m['cpu_usage'],1) : "\xE2\x80\x94"?></td>
                <td data-label="Mem %" style="color:<?=$m['memory_usage']!==null ? mColorFor('mem',(float)$m['memory_usage'],$srv) : 'var(--muted)'?>"><?=$m['memory_usage']!==null ? number_format((float)$m['memory_usage'],1) : "\xE2\x80\x94"?></td>
                <td data-label="Mem MB"><?=$m['memory_total_mb']!==null ? number_format((int)$m['memory_total_mb']) : "\xE2\x80\x94"?></td>
                <td data-label="Disk %" style="color:<?=$m['disk_usage']!==null ? mColorFor('disk',(float)$m['disk_usage'],$srv) : 'var(--muted)'?>"><?=$m['disk_usage']!==null ? number_format((float)$m['disk_usage'],1) : "\xE2\x80\x94"?></td>
                <td data-label="IOPS"><?=$m['disk_iops']!==null ? number_format((float)$m['disk_iops'],0) : "\xE2\x80\x94"?></td>
                <td data-label="Net RX"><?=$m['network_rx_bps']!==null ? fmtBytes((int)$m['network_rx_bps']).'/s' : "\xE2\x80\x94"?></td>
                <td data-label="Net TX"><?=$m['network_tx_bps']!==null ? fmtBytes((int)$m['network_tx_bps']).'/s' : "\xE2\x80\x94"?></td>
                <td data-label="Mail Q" style="color:<?=$m['mail_queue']!==null ? mColorFor('mail',(float)$m['mail_queue'],$srv) : 'var(--muted)'?>"><?=$m['mail_queue']!==null ? (int)$m['mail_queue'] : "\xE2\x80\x94"?></td>
                <td data-label="Load"><?=$m['load_1']!==null ? number_format((float)$m['load_1'],2).' / '.number_format((float)$m['load_5'],2).' / '.number_format((float)$m['load_15'],2) : "\xE2\x80\x94"?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif;
    pageBottom(); exit;
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

        <form method="post" action="?action=settings" id="settings-form">
            <?=csrfField()?>
            <?php $ekey = setting('enrollment_key'); $url = baseUrl(); $prevKeys = json_decode(setting('previous_keys') ?? '[]', true); ?>
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
                    <?php foreach ($prevKeys as $pk): ?>
                    <div class="prev-key-row">
                        <code class="ekey-code"><?=e($pk)?></code>
                        <button type="submit" name="invalidate_key" value="<?=e($pk)?>" class="btn-sm btn-sm-danger" onclick="return confirm('Invalidate this key?')">Invalidate</button>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:.5rem">
                        <button type="submit" name="invalidate_all_keys" value="1" class="btn-sm btn-sm-danger" onclick="return confirm('Invalidate ALL previous keys?')">Invalidate All</button>
                    </div>
                </div>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>General</legend>
                <div class="field-row">
                    <label>Check Interval (minutes)<input type="number" name="interval_minutes" value="<?=e(setting('interval_minutes'))?>" min="1" max="1440"></label>
                    <label>Data Retention (days)<input type="number" name="retention_days" value="<?=e(setting('retention_days'))?>" min="1" max="3650"></label>
                </div>
            </fieldset>

            <div class="settings-grid">
                <fieldset><legend>CPU Alerts (%)</legend><div class="field-row">
                    <label>Warning<input type="number" name="alert_cpu_warn" value="<?=e(setting('alert_cpu_warn'))?>" min="1" max="100"></label>
                    <label>Critical<input type="number" name="alert_cpu_crit" value="<?=e(setting('alert_cpu_crit'))?>" min="1" max="100"></label>
                </div></fieldset>
                <fieldset><legend>Memory Alerts (%)</legend><div class="field-row">
                    <label>Warning<input type="number" name="alert_mem_warn" value="<?=e(setting('alert_mem_warn'))?>" min="1" max="100"></label>
                    <label>Critical<input type="number" name="alert_mem_crit" value="<?=e(setting('alert_mem_crit'))?>" min="1" max="100"></label>
                </div></fieldset>
                <fieldset><legend>Disk Alerts (%)</legend><div class="field-row">
                    <label>Warning<input type="number" name="alert_disk_warn" value="<?=e(setting('alert_disk_warn'))?>" min="1" max="100"></label>
                    <label>Critical<input type="number" name="alert_disk_crit" value="<?=e(setting('alert_disk_crit'))?>" min="1" max="100"></label>
                </div></fieldset>
                <fieldset><legend>Mail Queue Alerts</legend><div class="field-row">
                    <label>Warning<input type="number" name="alert_mail_warn" value="<?=e(setting('alert_mail_warn'))?>" min="1" max="100000"></label>
                    <label>Critical<input type="number" name="alert_mail_crit" value="<?=e(setting('alert_mail_crit'))?>" min="1" max="100000"></label>
                </div></fieldset>
            </div>

            <fieldset>
                <legend>Notifications</legend>
                <label>Email (comma-separated)
                    <input type="text" name="alert_email" value="<?=e(setting('alert_email'))?>" placeholder="admin@example.com">
                </label>
                <label>Webhook URL (POST with JSON payload)
                    <input type="text" name="alert_webhook_url" value="<?=e(setting('alert_webhook_url'))?>" placeholder="https://hooks.slack.com/...">
                </label>
                <div class="field-row" style="margin-top:.5rem">
                    <label>Alert Cooldown (minutes)
                        <input type="number" name="alert_cooldown_minutes" value="<?=e(setting('alert_cooldown_minutes'))?>" min="1" max="1440">
                    </label>
                    <label>&nbsp;</label>
                </div>
            </fieldset>

            <button type="submit" class="btn-primary" style="margin-top:1.25rem">Save Settings</button>
        </form>

        <fieldset style="margin-top:1.5rem">
            <legend>Security</legend>
            <a href="?action=setup-password" class="btn-secondary">Change Password</a>
            <label style="margin-top:1rem">API IP Allowlist (comma-separated, empty = allow all)
                <input type="text" name="api_ip_allowlist" value="<?=e(setting('api_ip_allowlist'))?>" placeholder="203.0.113.10, 10.0.1.0/24" form="settings-form">
            </label>
            <p style="font-size:.75rem;color:var(--subtle);margin-top:.25rem">Restricts which IPs can call the enroll and ingest endpoints. Leave empty to allow all.</p>
        </fieldset>
    </div>
    <?php
    pageBottom(); exit;
}

// ─── HTML Template ───────────────────────────────────────────

function pageTop(string $title): void {
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($title)?> — Sermony</title>
<style>
:root,[data-theme="light"]{
    --bg:#f0f2f5;--card:#fff;--card-border:#e5e7eb;--card-hover:rgba(0,0,0,.08);
    --text:#111827;--muted:#6b7280;--subtle:#9ca3af;
    --header-bg:#1e293b;--header-text:#f8fafc;--code-bg:#f1f5f9;
    --input-bg:#fff;--input-border:#d1d5db;
    --table-head:#f8fafc;--table-border:#e5e7eb;--table-row-border:#f3f4f6;--table-hover:#f8fafc;
    --green:#22c55e;--amber:#f59e0b;--red:#ef4444;--slate:#6b7280;
    --blue:#3b82f6;--blue-hover:#2563eb;--foot-border:#f3f4f6;
}
[data-theme="dark"]{
    --bg:#0f172a;--card:#1e293b;--card-border:#334155;--card-hover:rgba(255,255,255,.05);
    --text:#e2e8f0;--muted:#94a3b8;--subtle:#64748b;
    --header-bg:#020617;--header-text:#f8fafc;--code-bg:#334155;
    --input-bg:#1e293b;--input-border:#475569;
    --table-head:#1e293b;--table-border:#334155;--table-row-border:#1e293b;--table-hover:#334155;
    --green:#4ade80;--amber:#fbbf24;--red:#f87171;--slate:#94a3b8;
    --blue:#60a5fa;--blue-hover:#3b82f6;--foot-border:#1e293b;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.5}
a{color:var(--blue)} .wrap{max-width:1280px;margin:0 auto;padding:0 1rem}
header{background:var(--header-bg);color:var(--header-text);padding:1rem 0}
header .wrap{display:flex;align-items:center;gap:1rem}
header h1{font-size:1.25rem;font-weight:600;flex:1} header h1 span{color:#60a5fa}
.hdr-links{display:flex;align-items:center;gap:.75rem}
.hdr-links a,.hdr-links button{color:#94a3b8;text-decoration:none;font-size:.85rem;background:none;border:none;cursor:pointer}
.hdr-links a:hover,.hdr-links button:hover{color:#f8fafc}
.theme-toggle{border:1px solid #475569!important;padding:.3rem .6rem;border-radius:6px;font-size:.8rem}

.status-summary{display:flex;flex-wrap:wrap;align-items:center;gap:.4rem;margin:1rem 0 .5rem;padding:.5rem .75rem;background:var(--card);border:1px solid var(--card-border);border-radius:8px}
.ss-item{font-size:.78rem;padding:.2rem .55rem;border-radius:10px;font-weight:500;white-space:nowrap}
.ss-item:first-child{color:var(--text);font-weight:600;padding-right:.5rem;border-right:1px solid var(--card-border);margin-right:.15rem}
.ss-online{color:var(--green)} .ss-offline{color:var(--red)}
.ss-crit{color:#fff;background:var(--red)} .ss-warn{color:#000;background:var(--amber)} .ss-stale{color:var(--slate);border:1px solid var(--slate)}

.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem;margin:1.25rem 0}
.card{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;transition:box-shadow .15s,opacity .15s}
.card:hover{box-shadow:0 4px 12px var(--card-hover)}
.card[draggable]{cursor:grab} .card[draggable]:active{cursor:grabbing} .card.dragging{opacity:.4}
.card-offline{border-left:3px solid var(--red)}
.card-crit{border-left:3px solid var(--red);background:color-mix(in srgb,var(--red) 5%,var(--card))}
.card-warn{border-left:3px solid var(--amber);background:color-mix(in srgb,var(--amber) 5%,var(--card))}
.card-stale{border-left:3px solid var(--slate);background:color-mix(in srgb,var(--slate) 5%,var(--card))}
.card-head{display:flex;align-items:center;gap:.5rem;margin-bottom:.25rem}
.card-hostname{font-weight:600;font-size:1rem;color:var(--text);text-decoration:none;flex:1}
.card-hostname:hover{color:var(--blue)}
.card-meta{font-size:.8rem;color:var(--muted);margin-bottom:.75rem}
.card-foot{font-size:.75rem;color:var(--muted);margin-top:.75rem;padding-top:.5rem;border-top:1px solid var(--foot-border)}

.badge{font-size:.6rem;font-weight:700;letter-spacing:.05em;padding:.15rem .4rem;border-radius:4px;text-transform:uppercase}
.badge-off{background:var(--red);color:#fff} .badge-crit{background:var(--red);color:#fff;animation:pulse-badge 2s ease-in-out infinite}
.badge-warn{background:var(--amber);color:#000} .badge-stale{background:var(--slate);color:#fff}
@keyframes pulse-badge{0%,100%{opacity:1}50%{opacity:.6}}

.dot{width:10px;height:10px;border-radius:50%;display:inline-block;flex-shrink:0}
.dot-on{background:var(--green);box-shadow:0 0 0 3px color-mix(in srgb,var(--green) 20%,transparent)}
.dot-stale{background:var(--slate);box-shadow:0 0 0 3px color-mix(in srgb,var(--slate) 20%,transparent);animation:pulse-dot 2s ease-in-out infinite}
.dot-off{background:var(--red);box-shadow:0 0 0 3px color-mix(in srgb,var(--red) 20%,transparent)}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
.btn-del{background:none;border:none;color:var(--subtle);font-size:1.2rem;cursor:pointer;padding:0 .3rem;line-height:1}
.btn-del:hover{color:var(--red)}

.metrics{display:grid;grid-template-columns:1fr 1fr;gap:.4rem 1rem}
.m{font-size:.82rem} .ml{color:var(--muted);font-size:.7rem;text-transform:uppercase;letter-spacing:.03em}
.mv{font-weight:500;display:block} .mv small{font-weight:400;color:var(--subtle)}
.bar{height:3px;background:var(--card-border);border-radius:2px;margin-top:2px}
.bar>div{height:100%;border-radius:2px;transition:width .3s}

.spark-label{font-size:.65rem;color:var(--subtle);text-transform:uppercase;letter-spacing:.03em;display:block;margin-bottom:2px}
.spark{width:100%;height:20px;display:block}
.detail-sparks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:1.25rem 0;padding:1rem 1.25rem;background:var(--card);border:1px solid var(--card-border);border-radius:8px}
.detail-spark .spark{height:48px}
.detail-spark .spark-label{font-size:.75rem;font-weight:600;margin-bottom:4px}

.detail-header{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1.25rem;margin:1.25rem 0;overflow:hidden}
.detail-title{display:flex;align-items:center;gap:.5rem;margin:.75rem 0}
.detail-title h1{font-size:1.5rem}
.detail-meta{display:flex;flex-wrap:wrap;gap:1rem;font-size:.85rem;color:var(--muted);margin-bottom:1rem}
.back{font-size:.85rem;color:var(--blue);text-decoration:none} .back:hover{text-decoration:underline}
.btn-danger{background:var(--red);color:#fff;border:none;padding:.4rem 1rem;border-radius:6px;cursor:pointer;font-size:.85rem} .btn-danger:hover{opacity:.9}
.btn-primary{background:var(--blue);color:#fff;border:none;padding:.5rem 1.5rem;border-radius:6px;cursor:pointer;font-size:.9rem} .btn-primary:hover{background:var(--blue-hover)}
.btn-secondary{background:var(--card);color:var(--text);border:1px solid var(--card-border);padding:.4rem 1rem;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none} .btn-secondary:hover{background:var(--bg)}
.btn-sm{background:var(--blue);color:#fff;border:none;padding:.2rem .5rem;border-radius:4px;cursor:pointer;font-size:.75rem;flex-shrink:0} .btn-sm:hover{background:var(--blue-hover)}
.btn-sm-danger{background:var(--red)!important} .btn-sm-danger:hover{opacity:.85}

.table-wrap{overflow-x:auto;margin:1.25rem 0;background:var(--card);border:1px solid var(--card-border);border-radius:8px}
table{width:100%;border-collapse:collapse;font-size:.82rem}
th{background:var(--table-head);font-weight:600;text-align:left;padding:.6rem .75rem;border-bottom:2px solid var(--table-border);white-space:nowrap}
td{padding:.5rem .75rem;border-bottom:1px solid var(--table-row-border);white-space:nowrap}
tr:hover td{background:var(--table-hover)}

.server-settings{margin-top:.5rem}
.srv-thresholds{margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--card-border)}
.thresh-hint{font-size:.78rem;color:var(--subtle);font-style:italic}
textarea{width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem;font-family:inherit;resize:vertical}

.settings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;margin-top:1rem}
fieldset{border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;margin-top:1rem;min-width:0;overflow:hidden}
.settings-grid fieldset{margin-top:0}
legend{font-weight:600;font-size:.9rem;padding:0 .5rem}
label{display:block;font-size:.82rem;color:var(--muted);margin-top:.5rem}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.ekey-row{display:flex;gap:.5rem;align-items:center;margin-top:.35rem;min-width:0}
.ekey-code{font-size:.78rem;min-width:0;flex:1;background:var(--code-bg);padding:.35rem .5rem;border-radius:4px;line-height:1.4;word-break:break-all}
.install-cmd{white-space:pre-wrap;word-break:normal}
input[type="number"],input[type="text"]{width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem}
.alert-ok{background:color-mix(in srgb,var(--green) 15%,var(--card));border:1px solid var(--green);color:var(--green);padding:.5rem 1rem;border-radius:6px;font-size:.85rem;margin-bottom:1rem}
.alert-warn{background:color-mix(in srgb,var(--amber) 15%,var(--card));border:1px solid var(--amber);color:var(--amber);padding:.5rem 1rem;border-radius:6px;font-size:.85rem;margin-bottom:1rem}
.prev-keys{margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--card-border)}
.prev-key-row{display:flex;gap:.5rem;align-items:center;margin-top:.35rem;min-width:0}
.prev-key-row .ekey-code{opacity:.7}
.empty{text-align:center;padding:3rem 1rem;color:var(--muted)} .empty h2{color:var(--text);margin-bottom:.5rem}
.auth-box{max-width:380px;margin:3rem auto;background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:2rem}
.auth-box h2{margin-bottom:1rem}
.auth-box input[type="password"]{width:100%;padding:.5rem .75rem;border:1px solid var(--input-border);border-radius:6px;font-size:.9rem;background:var(--input-bg);color:var(--text);margin-top:.25rem}

@media(max-width:640px){
    .grid{grid-template-columns:1fr}
    .detail-meta{flex-direction:column;gap:.25rem}
    .settings-grid{grid-template-columns:1fr}
    .table-wrap table thead{display:none}
    .table-wrap table,.table-wrap tbody,.table-wrap tr{display:block}
    .table-wrap tr{border-bottom:2px solid var(--table-border);padding:.5rem 0;margin-bottom:.25rem}
    .table-wrap td{display:flex;justify-content:space-between;align-items:center;padding:.3rem .75rem;white-space:normal;border-bottom:1px solid var(--table-row-border)}
    .table-wrap td::before{content:attr(data-label);font-weight:600;color:var(--muted);font-size:.72rem;text-transform:uppercase;margin-right:.5rem}
    .table-wrap td:last-child{border-bottom:none}
}
</style>
<script>window.CSRF='<?=csrfToken()?>';(function(){var t=localStorage.getItem('sermony-theme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-theme',t)})()</script>
</head>
<body>
<header><div class="wrap">
    <h1><span>&#9632;</span> Sermony</h1>
    <div class="hdr-links">
        <?php if (isLoggedIn()): ?>
        <a href="?action=settings">Settings</a>
        <a href="?action=logout">Logout</a>
        <?php endif; ?>
        <button class="theme-toggle" onclick="toggleTheme()" id="themeBtn">&#9790;</button>
        <?php if (isLoggedIn()): ?>
        <button onclick="enableNotif(this)" id="notifBtn" title="Enable browser notifications" style="display:none">&#128276;</button>
        <?php endif; ?>
    </div>
</div></header>
<main class="wrap">
<?php
}

function pageBottom(): void {
    $refreshMs = max(30, (int)(setting('interval_minutes') ?? 15) * 60) * 1000;
?>
</main>
<footer style="text-align:center;padding:2rem;color:var(--subtle);font-size:.75rem">Sermony v<?=VERSION?></footer>
<script>
function toggleTheme(){var h=document.documentElement,c=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',c);localStorage.setItem('sermony-theme',c);updThemeBtn()}
function updThemeBtn(){document.getElementById('themeBtn').textContent=document.documentElement.getAttribute('data-theme')==='dark'?'\u2600':'\u263E'}
updThemeBtn();
function copyEl(id,btn){navigator.clipboard.writeText(document.getElementById(id).textContent).then(function(){btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy'},1500)})}

/* Drag & drop */
(function(){
    var grid=document.getElementById('serverGrid');if(!grid)return;
    var dragged=null;
    grid.addEventListener('dragstart',function(e){var c=e.target.closest('.card[data-id]');if(!c)return;dragged=c;c.classList.add('dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','')});
    grid.addEventListener('dragover',function(e){e.preventDefault();var c=e.target.closest('.card[data-id]');if(!c||c===dragged)return;var r=c.getBoundingClientRect();grid.insertBefore(dragged,e.clientY>r.top+r.height/2?c.nextSibling:c)});
    grid.addEventListener('dragend',function(){if(dragged)dragged.classList.remove('dragging');dragged=null;var cards=grid.querySelectorAll('.card[data-id]'),order=[];cards.forEach(function(c,i){order.push({id:+c.dataset.id,pos:i+1})});fetch('?action=reorder',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(order)})});
})();

/* Browser notifications */
var notifBtn=document.getElementById('notifBtn');
if('Notification' in window){
    if(Notification.permission==='default')notifBtn.style.display='';
    if(Notification.permission==='granted')notifBtn.style.display='none';
}
function enableNotif(btn){Notification.requestPermission().then(function(p){if(p==='granted'){btn.style.display='none'}})}
function notify(title,body){if('Notification' in window&&Notification.permission==='granted')new Notification(title,{body:body,icon:'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect fill="%233b82f6" width="10" height="10" rx="2"/></svg>'})}

/* Auto-refresh dashboard */
(function(){
    var grid=document.getElementById('serverGrid');if(!grid)return;
    var prevState={};
    // Seed initial state
    grid.querySelectorAll('.card[data-id]').forEach(function(c){
        var id=c.dataset.id;
        prevState[id]={online:!c.classList.contains('card-offline'),health:c.classList.contains('card-crit')?'crit':c.classList.contains('card-warn')?'warn':'ok'};
    });

    setInterval(function(){
        fetch('?action=dashboard-json').then(function(r){return r.json()}).then(function(data){
            if(!data.servers)return;
            var currentIds=new Set();
            data.servers.forEach(function(s){currentIds.add(''+s.id)});
            var gridIds=new Set();
            grid.querySelectorAll('.card[data-id]').forEach(function(c){gridIds.add(c.dataset.id)});
            // Reload if server count changed
            if(currentIds.size!==gridIds.size){location.reload();return}

            data.servers.forEach(function(s){
                var card=grid.querySelector('.card[data-id="'+s.id+'"]');if(!card)return;
                var prev=prevState[s.id]||{};
                // Notifications
                if(prev.online&&!s.online)notify('Sermony: '+s.hostname+' OFFLINE','Server is no longer responding');
                if(prev.health!=='crit'&&s.health==='crit')notify('Sermony: '+s.hostname+' CRITICAL','Server metrics exceeded critical threshold');
                if(!prev.online&&s.online&&prev.online!==undefined)notify('Sermony: '+s.hostname+' recovered','Server is back online');
                prevState[s.id]={online:s.online,health:s.health};
            });
        }).catch(function(){});
    },<?=$refreshMs?>);
})();
</script>
</body>
</html>
<?php
}

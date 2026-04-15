<?php
/**
 * Sermony Notifications Plugin
 *
 * Receives notifications via HTTP webhook or CLI, stores them
 * with priority levels, displays on the dashboard as a feed.
 *
 * Send notification via curl:
 *   curl -X POST 'https://your-server/?action=notify' \
 *     -H 'Content-Type: application/json' \
 *     -d '{"token":"YOUR_TOKEN","title":"Deploy done","message":"v2.1 deployed to prod","priority":"info","source":"ci"}'
 *
 * Priority levels: critical, warning, info, success
 */

function notifDb(): void {
    static $done = false;
    if ($done) return;
    @db()->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        message TEXT DEFAULT "",
        priority TEXT NOT NULL DEFAULT "info",
        source TEXT DEFAULT "",
        server_id INTEGER,
        url TEXT DEFAULT "",
        acknowledged INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
    @db()->exec('CREATE INDEX IF NOT EXISTS idx_notif_created ON notifications(created_at DESC)');
    $done = true;
}

function notifCount(): array {
    notifDb();
    $d = db();
    $res = $d->query("SELECT priority, COUNT(*) as c FROM notifications WHERE acknowledged=0 GROUP BY priority");
    $counts = ['critical' => 0, 'warning' => 0, 'info' => 0, 'success' => 0, 'total' => 0];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $counts[$r['priority']] = (int)$r['c'];
        $counts['total'] += (int)$r['c'];
    }
    return $counts;
}

return [
    'name'    => 'Notifications',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/notifications',

    'hooks' => [

        'header_links' => function () {
            $c = notifCount();
            $badge = '';
            if ($c['critical'] > 0) $badge = '<span style="background:var(--red);color:#fff;border-radius:50%;font-size:.6rem;padding:1px 4px;margin-left:2px">' . $c['critical'] . '</span>';
            elseif ($c['total'] > 0) $badge = '<span style="background:var(--blue);color:#fff;border-radius:50%;font-size:.6rem;padding:1px 4px;margin-left:2px">' . $c['total'] . '</span>';
            echo '<a href="?action=notifications">Alerts' . $badge . '</a>';
        },

        'dashboard_top' => function () {
            $c = notifCount();
            if ($c['critical'] === 0 && $c['warning'] === 0) return;
            notifDb();
            $d = db();
            $res = $d->query("SELECT * FROM notifications WHERE acknowledged=0 AND priority IN ('critical','warning') ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 END, created_at DESC LIMIT 5");
            $items = [];
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) $items[] = $r;
            if (empty($items)) return;
            ?>
            <div class="notif-banner">
                <?php foreach ($items as $n):
                    $color = $n['priority'] === 'critical' ? 'var(--red)' : 'var(--amber)';
                    $icon = $n['priority'] === 'critical' ? '&#9888;' : '&#9432;';
                ?>
                <div class="notif-banner-item" style="border-left:3px solid <?=$color?>">
                    <span style="color:<?=$color?>;font-weight:700"><?=$icon?></span>
                    <strong><?=e($n['title'])?></strong>
                    <?php if ($n['message']): ?><span style="color:var(--muted)"> &mdash; <?=e(mb_substr($n['message'], 0, 100))?></span><?php endif; ?>
                    <span style="color:var(--subtle);font-size:.7rem;margin-left:auto;white-space:nowrap"><?=e(substr($n['created_at'], 0, 16))?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($c['critical'] + $c['warning'] > 5): ?>
                <a href="?action=notifications" style="font-size:.78rem;display:block;text-align:right;margin-top:.25rem">View all <?=$c['critical'] + $c['warning']?> alerts &rarr;</a>
                <?php endif; ?>
            </div>
            <style>
            .notif-banner{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:.5rem .75rem;margin-bottom:.75rem}
            .notif-banner-item{display:flex;align-items:center;gap:.5rem;padding:.3rem .4rem;font-size:.82rem;border-radius:4px;margin-bottom:.25rem}
            .notif-banner-item:last-of-type{margin-bottom:0}
            </style>
            <?php
        },

        'settings_panel' => function () {
            $token = setting('notif_token');
            if (!$token) { $token = bin2hex(random_bytes(16)); setSetting('notif_token', $token); }
            ?>
            <fieldset style="margin-top:1rem">
                <legend>Notifications</legend>
                <label>Webhook Token</label>
                <div style="display:flex;gap:.5rem;align-items:center;margin-top:.25rem">
                    <code style="background:var(--code-bg);padding:.35rem .5rem;border-radius:4px;font-size:.78rem;flex:1;word-break:break-all"><?=e($token)?></code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?=e($token)?>')" class="btn-sm">Copy</button>
                </div>
                <p style="font-size:.75rem;color:var(--subtle);margin-top:.25rem">Use this token to send notifications via the webhook API.</p>
                <label style="margin-top:.5rem">Retention (days)
                    <input type="number" name="notif_retention_days" value="<?=e(setting('notif_retention_days') ?? '30')?>" min="1" max="365" form="settings-form">
                </label>
                <details style="margin-top:.75rem;font-size:.82rem">
                    <summary style="cursor:pointer;color:var(--blue)">Webhook Usage Examples</summary>
                    <pre style="background:var(--code-bg);padding:.5rem;border-radius:4px;font-size:.75rem;margin-top:.4rem;overflow-x:auto;white-space:pre-wrap">curl -X POST '<?=e(baseUrl())?>?action=notify' \
  -H 'Content-Type: application/json' \
  -d '{
    "token": "<?=e($token)?>",
    "title": "Deploy Complete",
    "message": "v2.1.0 deployed to production",
    "priority": "success",
    "source": "github-actions"
  }'

# Priorities: critical, warning, info, success
# Optional: "server_id": 5, "url": "https://..."</pre>
                </details>
            </fieldset>
            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_retention_days'])) {
                setSetting('notif_retention_days', (string)max(1, (int)$_POST['notif_retention_days']));
            }
        },

        'custom_action' => function (string $action) {
            // ── Public webhook endpoint (no session auth, token auth) ──
            if ($action === 'notify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                notifDb();
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');
                $token = trim($in['token'] ?? '');
                $stored = setting('notif_token');
                if (!$stored || !hash_equals($stored, $token)) jsonErr('Invalid token', 403);

                $title = trim($in['title'] ?? '');
                if ($title === '') jsonErr('Title required');
                $priority = in_array($in['priority'] ?? '', ['critical', 'warning', 'info', 'success']) ? $in['priority'] : 'info';

                $d = db();
                $s = $d->prepare('INSERT INTO notifications (title, message, priority, source, server_id, url) VALUES (:t,:m,:p,:src,:sid,:u)');
                $s->bindValue(':t', mb_substr($title, 0, 200));
                $s->bindValue(':m', mb_substr(trim($in['message'] ?? ''), 0, 2000));
                $s->bindValue(':p', $priority);
                $s->bindValue(':src', mb_substr(trim($in['source'] ?? ''), 0, 100));
                $s->bindValue(':sid', isset($in['server_id']) ? (int)$in['server_id'] : null);
                $s->bindValue(':u', mb_substr(trim($in['url'] ?? ''), 0, 500));
                $s->execute();
                jsonOut(['ok' => true, 'id' => (int)$d->lastInsertRowID()]);
            }

            if (!str_starts_with($action, 'notification')) return;
            notifDb();
            $d = db();

            // ── Acknowledge ──
            if ($action === 'notifications-ack' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) {
                    $s = $d->prepare('UPDATE notifications SET acknowledged=1 WHERE id=:id');
                    $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute();
                }
                jsonOut(['ok' => true]);
            }

            if ($action === 'notifications-ack-all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $priority = $in['priority'] ?? 'all';
                if ($priority === 'all') $d->exec('UPDATE notifications SET acknowledged=1 WHERE acknowledged=0');
                else { $s = $d->prepare('UPDATE notifications SET acknowledged=1 WHERE acknowledged=0 AND priority=:p'); $s->bindValue(':p', $priority); $s->execute(); }
                jsonOut(['ok' => true]);
            }

            if ($action === 'notifications-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) { $s = $d->prepare('DELETE FROM notifications WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute(); }
                jsonOut(['ok' => true]);
            }

            if ($action === 'notifications-list') {
                $filter = $_GET['filter'] ?? 'unread';
                $where = $filter === 'all' ? '1=1' : 'acknowledged=0';
                $res = $d->query("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 100");
                $items = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $items[] = $r;
                jsonOut(['notifications' => $items]);
            }

            // ── Cleanup old notifications ──
            if ($action === 'notifications-cleanup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $ret = (int)(setting('notif_retention_days') ?? 30);
                $s = $d->prepare("DELETE FROM notifications WHERE created_at < strftime('%Y-%m-%dT%H:%M:%SZ','now',:days)");
                $s->bindValue(':days', "-$ret days"); $s->execute();
                jsonOut(['ok' => true, 'deleted' => $d->changes()]);
            }

            // ── Main page ──
            if ($action === 'notifications') {
                pageTop('Notifications');
                ?>
                <div class="detail-header">
                    <a href="?" class="back">&larr; Dashboard</a>
                    <h1 style="margin:.75rem 0">Notifications</h1>
                    <div style="display:flex;gap:.5rem;margin-bottom:1rem;align-items:center;flex-wrap:wrap">
                        <button onclick="ntFilter('unread')" class="btn-sm nt-tab active" id="ntTabUnread">Unread</button>
                        <button onclick="ntFilter('all')" class="btn-sm nt-tab" id="ntTabAll">All</button>
                        <span style="flex:1"></span>
                        <button onclick="ntAckAll('all')" class="btn-sm" style="background:var(--subtle)">Acknowledge All</button>
                        <button onclick="ntCleanup()" class="btn-sm btn-sm-danger">Cleanup Old</button>
                    </div>
                    <div id="ntList">Loading...</div>
                </div>

                <style>
                .nt-item{display:flex;align-items:flex-start;gap:.6rem;padding:.6rem .75rem;border-bottom:1px solid var(--foot-border);font-size:.82rem}
                .nt-item:last-child{border-bottom:none}
                .nt-item.nt-acked{opacity:.5}
                .nt-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px}
                .nt-dot-critical{background:var(--red)}
                .nt-dot-warning{background:var(--amber)}
                .nt-dot-info{background:var(--blue)}
                .nt-dot-success{background:var(--green)}
                .nt-body{flex:1;min-width:0}
                .nt-title{font-weight:600}
                .nt-msg{color:var(--muted);margin-top:.15rem;word-break:break-word}
                .nt-meta{font-size:.7rem;color:var(--subtle);margin-top:.2rem;display:flex;gap:.75rem;flex-wrap:wrap}
                .nt-actions{display:flex;gap:.3rem;flex-shrink:0}
                .nt-tab{border-radius:4px}
                .nt-tab.active{background:var(--blue)!important}
                .nt-tab:not(.active){background:var(--subtle)!important}
                .nt-empty{text-align:center;padding:2rem;color:var(--muted)}
                </style>

                <script>
                (function(){
                    var currentFilter='unread';

                    async function ntLoad(){
                        var resp=await fetch('?action=notifications-list&filter='+currentFilter);
                        var data=await resp.json();
                        ntRender(data.notifications||[]);
                    }

                    function ntRender(items){
                        if(!items.length){document.getElementById('ntList').innerHTML='<div class="nt-empty">No notifications.</div>';return}
                        var html='';
                        for(var n of items){
                            var acked=n.acknowledged==1;
                            html+='<div class="nt-item'+(acked?' nt-acked':'')+'">';
                            html+='<span class="nt-dot nt-dot-'+esc(n.priority)+'"></span>';
                            html+='<div class="nt-body">';
                            html+='<div class="nt-title">'+esc(n.title)+'</div>';
                            if(n.message)html+='<div class="nt-msg">'+esc(n.message)+'</div>';
                            html+='<div class="nt-meta">';
                            html+='<span>'+esc(n.priority.toUpperCase())+'</span>';
                            if(n.source)html+='<span>from: '+esc(n.source)+'</span>';
                            html+='<span>'+esc(n.created_at.replace('T',' ').substr(0,19))+'</span>';
                            if(n.url)html+='<span><a href="'+esc(n.url)+'" target="_blank">Link</a></span>';
                            html+='</div></div>';
                            html+='<div class="nt-actions">';
                            if(!acked)html+='<button onclick="ntAck('+n.id+')" class="btn-sm" style="background:var(--subtle)" title="Acknowledge">&#10003;</button>';
                            html+='<button onclick="ntDel('+n.id+')" class="btn-sm btn-sm-danger" title="Delete">&#10005;</button>';
                            html+='</div></div>';
                        }
                        document.getElementById('ntList').innerHTML=html;
                    }

                    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

                    window.ntFilter=function(f){
                        currentFilter=f;
                        document.getElementById('ntTabUnread').classList.toggle('active',f==='unread');
                        document.getElementById('ntTabAll').classList.toggle('active',f==='all');
                        ntLoad();
                    };

                    window.ntAck=async function(id){await fetch('?action=notifications-ack',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});ntLoad()};
                    window.ntDel=async function(id){await fetch('?action=notifications-delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});ntLoad()};
                    window.ntAckAll=async function(p){if(!confirm('Acknowledge all '+(p==='all'?'':p+' ')+'notifications?'))return;await fetch('?action=notifications-ack-all',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({priority:p})});ntLoad()};
                    window.ntCleanup=async function(){if(!confirm('Delete old notifications beyond retention period?'))return;await fetch('?action=notifications-cleanup',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({})});ntLoad()};

                    ntLoad();
                    setInterval(ntLoad,15000);
                })();
                </script>
                <?php
                pageBottom();
                exit;
            }
        },
    ],
];

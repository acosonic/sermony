<?php
/**
 * Sermony PM2 Plugin — PM2 Process Monitor
 *
 * Shows PM2 processes on server detail pages and makes them
 * searchable from the dashboard. Has its own agent script
 * that collects PM2 process info from all user accounts.
 *
 * Install agent: the main agent install handles plugin agents,
 * or manually:
 *   curl -sf 'https://your-server/?action=plugin-agent&plugin=pm2' \
 *     -o /opt/sermony/plugins/pm2-agent.sh && chmod 700 /opt/sermony/plugins/pm2-agent.sh
 */

function pm2GetData(int $serverId): array {
    @db()->exec('CREATE TABLE IF NOT EXISTS plugin_data (
        server_id INTEGER NOT NULL, plugin TEXT NOT NULL, data TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        PRIMARY KEY (server_id, plugin),
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
    )');
    $s = db()->prepare('SELECT data FROM plugin_data WHERE server_id=:id AND plugin=:p');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':p', 'pm2', SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? (json_decode($r['data'], true) ?: []) : [];
}

return [
    'name'    => 'PM2 Monitor',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/pm2',

    'hooks' => [

        // Show PM2 process count on dashboard cards
        'dashboard_card' => function (array $server) {
            $procs = pm2GetData((int)$server['id']);
            if (empty($procs)) return;
            $online = count(array_filter($procs, fn($p) => ($p['status'] ?? '') === 'online'));
            $total = count($procs);
            $color = $online === $total ? 'var(--green)' : ($online > 0 ? 'var(--amber)' : 'var(--red)');
            echo '<div class="m"><span class="ml">PM2</span><span class="mv" style="color:' . $color . '">' . $online . '/' . $total . ' online</span></div>';
        },

        // Add PM2 column to datagrid
        'datagrid_columns' => function () {
            echo '<th>PM2</th>';
        },

        'datagrid_row' => function (array $server) {
            $procs = pm2GetData((int)$server['id']);
            if (empty($procs)) { echo '<td></td>'; return; }
            $online = count(array_filter($procs, fn($p) => ($p['status'] ?? '') === 'online'));
            $total = count($procs);
            $color = $online === $total ? 'var(--green)' : ($online > 0 ? 'var(--amber)' : 'var(--red)');
            echo '<td style="color:' . $color . '">' . $online . '/' . $total . '</td>';
        },

        // Show PM2 process table on server detail page
        'server_detail' => function (array $server) {
            $procs = pm2GetData((int)$server['id']);
            if (empty($procs)) return;
            ?>
            <div class="pm2-section" style="margin-top:.75rem;padding:.75rem 1rem;border:1px solid var(--card-border);border-radius:8px;background:var(--card)">
                <strong style="font-size:.85rem">PM2 Processes (<?=count($procs)?>)</strong>
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-top:.4rem">
                    <thead><tr>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Name</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">User</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Status</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">CPU</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Mem</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Restarts</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Uptime</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Script</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($procs as $p):
                        $st = $p['status'] ?? 'unknown';
                        $stColor = $st === 'online' ? 'var(--green)' : ($st === 'stopped' ? 'var(--subtle)' : 'var(--red)');
                    ?>
                    <tr>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><strong><?=e($p['name'] ?? '')?></strong></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['user'] ?? '')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);color:<?=$stColor?>;font-weight:600;font-size:.75rem;text-transform:uppercase"><?=e($st)?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['cpu'] ?? '0')?>%</td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['mem'] ?? '')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['restarts'] ?? '0')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['uptime'] ?? '')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);font-size:.75rem;color:var(--muted)"><?=e(basename($p['script'] ?? ''))?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        },
        // Make PM2 process names searchable from dashboard
        'search_data' => function (array $parts, array $server): array {
            $procs = pm2GetData((int)$server['id']);
            if (!empty($procs)) {
                $parts[] = 'pm2';
                foreach ($procs as $p) {
                    $parts[] = $p['name'] ?? '';
                    $parts[] = $p['script'] ?? '';
                }
            }
            return $parts;
        },
    ],
];

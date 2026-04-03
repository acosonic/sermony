<?php
/**
 * Sermony Open Ports Plugin
 *
 * Displays listening TCP/UDP ports per server. Has its own agent
 * that collects port info via ss/netstat and sends to the server.
 */

function portsGetData(int $serverId): array {
    @db()->exec('CREATE TABLE IF NOT EXISTS plugin_data (
        server_id INTEGER NOT NULL, plugin TEXT NOT NULL, data TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        PRIMARY KEY (server_id, plugin),
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
    )');
    $s = db()->prepare('SELECT data FROM plugin_data WHERE server_id=:id AND plugin=:p');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':p', 'ports', SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? (json_decode($r['data'], true) ?: []) : [];
}

/** Check if a port is commonly expected */
function portsIsCommon(int $port): bool {
    return in_array($port, [22, 25, 53, 80, 443, 993, 995, 587, 465, 143, 110, 3306, 5432, 6379, 27017, 11211, 8080, 8443, 9090]);
}

return [
    'name'    => 'Open Ports',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/ports',

    'hooks' => [

        // Show port count on dashboard cards
        'dashboard_card' => function (array $server) {
            $ports = portsGetData((int)$server['id']);
            if (empty($ports)) return;
            $tcp = count(array_filter($ports, fn($p) => ($p['proto'] ?? '') === 'tcp'));
            echo '<div class="m"><span class="ml">Ports</span><span class="mv">' . $tcp . ' TCP</span></div>';
        },

        // Add ports column to datagrid
        'datagrid_columns' => function () {
            echo '<th>Ports</th>';
        },

        'datagrid_row' => function (array $server) {
            $ports = portsGetData((int)$server['id']);
            if (empty($ports)) { echo '<td></td>'; return; }
            $nums = array_map(fn($p) => $p['port'], $ports);
            sort($nums);
            $display = implode(', ', array_slice(array_unique($nums), 0, 8));
            if (count($nums) > 8) $display .= '...';
            echo '<td style="font-size:.75rem;color:var(--muted)" title="' . e(implode(', ', array_unique($nums))) . '">' . e($display) . '</td>';
        },

        // Show port table on server detail page
        'server_detail' => function (array $server) {
            $ports = portsGetData((int)$server['id']);
            if (empty($ports)) return;
            ?>
            <div style="margin-top:.75rem;padding:.75rem 1rem;border:1px solid var(--card-border);border-radius:8px;background:var(--card)">
                <strong style="font-size:.85rem">Open Ports (<?=count($ports)?>)</strong>
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-top:.4rem">
                    <thead><tr>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Port</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Proto</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Bind</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Program</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">PID</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">User</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($ports as $p):
                        $port = (int)($p['port'] ?? 0);
                        $isWild = in_array($p['bind'] ?? '', ['*', '0.0.0.0', '[::]', '']);
                        $color = $isWild ? 'var(--amber)' : 'var(--text)';
                    ?>
                    <tr>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><strong style="color:<?=$color?>"><?=$port?></strong></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e(strtoupper($p['proto'] ?? 'tcp'))?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);font-size:.75rem;color:<?=$color?>"><?=e($p['bind'] ?? '*')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e($p['program'] ?? '')?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);color:var(--muted)"><?=(int)($p['pid'] ?? 0)?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);color:var(--muted)"><?=e($p['user'] ?? '')?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="font-size:.7rem;color:var(--subtle);margin-top:.4rem">Ports bound to <span style="color:var(--amber)">0.0.0.0 / [::]</span> are publicly accessible.</p>
            </div>
            <?php
        },

        // Make ports searchable
        'search_data' => function (array $parts, array $server): array {
            $ports = portsGetData((int)$server['id']);
            if (!empty($ports)) {
                $parts[] = 'ports';
                foreach ($ports as $p) {
                    $parts[] = (string)($p['port'] ?? '');
                    $parts[] = $p['program'] ?? '';
                }
            }
            return $parts;
        },
    ],
];

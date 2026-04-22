<?php
/**
 * Sermony Sites Plugin
 *
 * Displays domains/sites hosted on each server, collected from
 * Apache, nginx, and Virtualmin configs. Domains are searchable.
 */

function sitesGetData(int $serverId): array {
    @db()->exec('CREATE TABLE IF NOT EXISTS plugin_data (
        server_id INTEGER NOT NULL, plugin TEXT NOT NULL, data TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        PRIMARY KEY (server_id, plugin),
        FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
    )');
    $s = db()->prepare('SELECT data FROM plugin_data WHERE server_id=:id AND plugin=:p');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':p', 'sites', SQLITE3_TEXT);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ? (json_decode($r['data'], true) ?: []) : [];
}

return [
    'name'    => 'Sites',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/sites',

    'hooks' => [

        // Show site count on dashboard cards
        'dashboard_card' => function (array $server) {
            $sites = sitesGetData((int)$server['id']);
            if (empty($sites)) return;
            echo '<div class="m"><span class="ml">Sites</span><span class="mv">' . count($sites) . '</span></div>';
        },

        // Add sites column to datagrid
        'datagrid_columns' => function () {
            echo '<th>Sites</th>';
        },

        'datagrid_row' => function (array $server) {
            $sites = sitesGetData((int)$server['id']);
            if (empty($sites)) { echo '<td></td>'; return; }
            echo '<td style="color:var(--muted)">' . count($sites) . '</td>';
        },

        // Show sites table on server detail page
        'server_detail' => function (array $server) {
            $sites = sitesGetData((int)$server['id']);
            if (empty($sites)) return;
            ?>
            <div style="margin-top:.75rem;padding:.75rem 1rem;border:1px solid var(--card-border);border-radius:8px;background:var(--card)">
                <strong style="font-size:.85rem">Hosted Sites (<?=count($sites)?>)</strong>
                <table style="width:100%;border-collapse:collapse;font-size:.8rem;margin-top:.4rem">
                    <thead><tr>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Domain</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Web Server</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">SSL</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--card-border);color:var(--muted);font-size:.7rem;text-transform:uppercase">Document Root</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sites as $s):
                        $proto = !empty($s['ssl']) ? 'https' : 'http';
                        $domain = (string)($s['domain'] ?? '');
                    ?>
                    <tr>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><strong><a href="<?=$proto?>://<?=e($domain)?>" target="_blank" style="color:var(--text);text-decoration:none"><?=e($domain)?></a></strong></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=e((string)($s['webserver'] ?? ''))?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border)"><?=!empty($s['ssl']) ? '<span style="color:var(--green)">&#128274;</span>' : '<span style="color:var(--subtle)">&mdash;</span>'?></td>
                        <td style="padding:.3rem .5rem;border-bottom:1px solid var(--foot-border);color:var(--muted);font-size:.75rem"><?=e((string)($s['docroot'] ?? ''))?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        },

        // Make domains searchable from dashboard
        'search_data' => function (array $parts, array $server): array {
            $sites = sitesGetData((int)$server['id']);
            foreach ($sites as $s) {
                if (!empty($s['domain'])) $parts[] = $s['domain'];
            }
            return $parts;
        },
    ],
];

<?php
/**
 * Example Sermony Plugin
 *
 * Demonstrates all available hook points. Copy this folder,
 * rename it, and modify to create your own plugin.
 *
 * To install: place in plugins/your-plugin/plugin.php
 * To disable: delete or rename the folder
 */
return [
    'name'    => 'Example Plugin',
    'version' => '1.0',
    'author'  => 'Sermony',

    'hooks' => [

        // ── Dashboard Hooks ──────────────────────────────

        // Rendered above the server grid/datagrid
        // 'dashboard_top' => function () {
        //     echo '<div class="detail-header" style="margin-bottom:1rem">
        //         <strong>Announcement:</strong> Maintenance window tonight 10pm-2am
        //     </div>';
        // },

        // Rendered inside each server card, after the metrics grid
        // Receives: $server (full server row + latest metrics)
        // 'dashboard_card' => function (array $server) {
        //     $uptime = json_decode($server['system_info'] ?? '{}', true)['uptime'] ?? '';
        //     if ($uptime) {
        //         echo '<div class="m"><span class="ml">Uptime</span>';
        //         echo '<span class="mv">' . htmlspecialchars($uptime) . '</span></div>';
        //     }
        // },

        // Rendered below the server grid/datagrid
        // 'dashboard_bottom' => function () {
        //     echo '<p style="text-align:center;color:var(--muted);font-size:.8rem;margin-top:1rem">
        //         Custom footer from plugin
        //     </p>';
        // },

        // ── Datagrid Hooks ───────────────────────────────

        // Add extra column headers to the datagrid
        // 'datagrid_columns' => function () {
        //     echo '<th>Uptime</th>';
        // },

        // Add extra cells to each datagrid row
        // Receives: $server
        // 'datagrid_row' => function (array $server) {
        //     $uptime = json_decode($server['system_info'] ?? '{}', true)['uptime'] ?? '';
        //     echo '<td>' . htmlspecialchars($uptime) . '</td>';
        // },

        // ── Server Detail Hooks ──────────────────────────

        // Rendered on the server detail page, after system info
        // Receives: $server
        // 'server_detail' => function (array $server) {
        //     echo '<div style="margin-top:.75rem;padding:.75rem;background:var(--code-bg);border-radius:6px;font-size:.82rem">
        //         <strong>Plugin Info:</strong> Custom section for ' . htmlspecialchars($server['hostname']) . '
        //     </div>';
        // },

        // Rendered after the metrics table
        // Receives: $server, $metrics (array of recent metric rows)
        // 'server_detail_bottom' => function (array $server, array $metrics) {
        //     echo '<p style="color:var(--muted);font-size:.8rem;margin-top:1rem">' .
        //         count($metrics) . ' data points shown</p>';
        // },

        // ── Ingest Hook ─────────────────────────────────

        // Called after metrics are saved to the database
        // Receives: $serverId, $metrics (incoming data), $server (DB row)
        // 'after_ingest' => function (int $serverId, array $metrics, array $server) {
        //     // Example: log high CPU to a custom file
        //     if (($metrics['cpu_usage'] ?? 0) > 95) {
        //         file_put_contents('/tmp/sermony-high-cpu.log',
        //             date('c') . " {$server['hostname']} CPU: {$metrics['cpu_usage']}%\n",
        //             FILE_APPEND);
        //     }
        // },

        // ── Settings Hook ────────────────────────────────

        // Rendered on the settings page, before the Security section
        // 'settings_panel' => function () {
        //     echo '<fieldset style="margin-top:1rem">
        //         <legend>My Plugin Settings</legend>
        //         <label>API Key<input type="text" name="my_plugin_key" value=""></label>
        //     </fieldset>';
        // },

        // ── Header Hook ─────────────────────────────────

        // Add links/buttons to the header navigation
        // 'header_links' => function () {
        //     echo '<a href="https://example.com" target="_blank">Docs</a>';
        // },
    ],
];

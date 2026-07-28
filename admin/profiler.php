<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
}

$history = get_option('wps_profiling_history', []);

if (empty($history)) {
    echo '<div class="wrap"><h1>' . esc_html__('Profiling', 'site-integrity-profiler') . '</h1><p>' . esc_html__('No data yet. Use the button below to force a test:', 'site-integrity-profiler') . '</p>';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('wps_reset_profiling_nonce', 'wps_reset_profiling'); ?>
        <input type="hidden" name="action" value="wps_reset_profiling">
        <button type="submit" class="button button-secondary"><?php esc_html_e('Reset history', 'site-integrity-profiler'); ?></button>
    </form>
    <div class="wps-actions">
        <button id="wpsRunTest" class="button button-primary"><?php esc_html_e('Run test', 'site-integrity-profiler'); ?></button>
        <span id="wpsSpinner" style="display:none;margin-left:10px;">⏳ <?php esc_html_e('Running test...', 'site-integrity-profiler'); ?></span>
        <iframe id="wpsTestFrame" style="display:none;width:1px;height:1px;"></iframe>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const btn = document.getElementById("wpsRunTest");
        const iframe = document.getElementById("wpsTestFrame");
        const spinner = document.getElementById("wpsSpinner");

        btn.addEventListener("click", function () {
            spinner.style.display = "inline";
            iframe.onload = function () {
                window.location.reload();
            };
            // La URL se emite con wp_json_encode (literal JS válido). No usar
            // esc_js(): convierte los '&' en '&amp;' y rompería la cadena de
            // consulta, de modo que el nonce no llegaría a PHP.
            iframe.src = <?php echo wp_json_encode(add_query_arg(['wps_profiling_test' => 1, '_wpnonce' => wp_create_nonce('wps_profiling_test')], home_url('/')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + "&t=" + Date.now();
        });
    });
    </script>
    <?php
    return;
}

$last = end($history);

$sql_time_ms  = isset($last['sql_time']) && $last['sql_time'] !== null ? round($last['sql_time'] * 1000, 2) : null;
$http_time_ms = round(($last['http_time'] ?? 0) * 1000, 2);

$profile_data = [
    __('Core', 'site-integrity-profiler')     => round($last['core']*1000, 2),
    __('Plugins', 'site-integrity-profiler')  => round($last['plugins']*1000, 2),
    __('Theme', 'site-integrity-profiler')    => round($last['theme']*1000, 2),
    __('MySQL', 'site-integrity-profiler')    => $sql_time_ms ?? 0,
    __('External', 'site-integrity-profiler') => $http_time_ms,
    __('Total', 'site-integrity-profiler')    => round($last['total']*1000, 2),
];

$timestamps = array_map(fn($d) => wp_date('H:i:s', $d['timestamp']), $history);
$totals = array_map(fn($d) => round($d['total']*1000,2), $history);
?>
<div class="wrap">
    <h1><?php esc_html_e('Home page profiling', 'site-integrity-profiler'); ?></h1>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['reset_done'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('History cleared successfully.', 'site-integrity-profiler'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('wps_reset_profiling_nonce', 'wps_reset_profiling'); ?>
        <input type="hidden" name="action" value="wps_reset_profiling">
        <button type="submit" class="button button-secondary"><?php esc_html_e('Reset history', 'site-integrity-profiler'); ?></button>
    </form>

    <div class="wps-actions">
        <button id="wpsRunTest" class="button button-primary"><?php esc_html_e('Run test', 'site-integrity-profiler'); ?></button>
        <span id="wpsSpinner" style="display:none;margin-left:10px;">⏳ <?php esc_html_e('Running test...', 'site-integrity-profiler'); ?></span>
        <iframe id="wpsTestFrame" style="display:none;width:1px;height:1px;"></iframe>
    </div>

    <h2><?php esc_html_e('Last measurement', 'site-integrity-profiler'); ?></h2>
    <div class="wps-chart-container">
        <canvas id="wpsProfilingChart"></canvas>
    </div>

    <ul>
        <?php foreach ($profile_data as $k => $v): ?>
            <li>
                <strong><?php echo esc_html($k); ?>:</strong>
                <?php if ($k === __('MySQL', 'site-integrity-profiler') && $sql_time_ms === null): ?>
                    <?php
                    printf(
                        /* translators: %s: the SAVEQUERIES PHP constant snippet. */
                        esc_html__('N/A (enable %s in wp-config.php to measure SQL time)', 'site-integrity-profiler'),
                        "<code>define('SAVEQUERIES', true);</code>"
                    );
                    ?>
                <?php else: ?>
                    <?php echo esc_html($v); ?> ms
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li><strong><?php esc_html_e('SQL queries:', 'site-integrity-profiler'); ?></strong> <?php echo intval($last['sql_count']); ?></li>
        <li><strong><?php esc_html_e('HTTP calls:', 'site-integrity-profiler'); ?></strong> <?php echo intval($last['http_count']); ?></li>
    </ul>

    <h2><?php esc_html_e('Evolution (recent visits)', 'site-integrity-profiler'); ?></h2>
    <div class="wps-chart-container">
        <canvas id="wpsHistoryChart"></canvas>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const btn = document.getElementById("wpsRunTest");
        const iframe = document.getElementById("wpsTestFrame");
        const spinner = document.getElementById("wpsSpinner");

        btn.addEventListener("click", function () {
            spinner.style.display = "inline";
            iframe.onload = function () {
                window.location.reload();
            };
            // La URL se emite con wp_json_encode (literal JS válido). No usar
            // esc_js(): convierte los '&' en '&amp;' y rompería la cadena de
            // consulta, de modo que el nonce no llegaría a PHP.
            iframe.src = <?php echo wp_json_encode(add_query_arg(['wps_profiling_test' => 1, '_wpnonce' => wp_create_nonce('wps_profiling_test')], home_url('/')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> + "&t=" + Date.now();
        });

        // Paleta acorde al panel (texto/rejilla legibles sobre fondo oscuro).
        if (window.Chart) {
            Chart.defaults.color = '#98a2c0';
            Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';
            Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        }
        const gridOpts = {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { grid: { color: 'rgba(255,255,255,0.06)' } },
                y: { grid: { color: 'rgba(255,255,255,0.06)' }, beginAtZero: true }
            }
        };

        const ctx1 = document.getElementById('wpsProfilingChart').getContext('2d');
        const grad1 = ctx1.createLinearGradient(0, 0, 0, 300);
        grad1.addColorStop(0, 'rgba(124, 92, 255, 0.85)');
        grad1.addColorStop(1, 'rgba(34, 211, 238, 0.55)');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: <?php echo wp_json_encode(array_keys($profile_data), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                datasets: [{
                    label: '<?php echo esc_js(__('Time (ms)', 'site-integrity-profiler')); ?>',
                    data: <?php echo wp_json_encode(array_values($profile_data), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    backgroundColor: grad1,
                    borderColor: 'rgba(124, 92, 255, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: gridOpts
        });

        const ctx2 = document.getElementById('wpsHistoryChart').getContext('2d');
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: <?php echo wp_json_encode($timestamps, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                datasets: [{
                    label: '<?php echo esc_js(__('Total time (ms)', 'site-integrity-profiler')); ?>',
                    data: <?php echo wp_json_encode($totals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                    fill: true,
                    backgroundColor: 'rgba(34, 211, 238, 0.12)',
                    borderColor: '#22d3ee',
                    pointBackgroundColor: '#7c5cff',
                    tension: 0.35
                }]
            },
            options: gridOpts
        });
    });
    </script>
</div>

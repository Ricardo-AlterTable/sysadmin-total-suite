<?php
if (!defined('ABSPATH')) exit;

$history = get_option('wps_profiling_history', []);

if (empty($history)) {
    echo '<div class="wrap"><h1>Profiling</h1><p>No hay datos aún. Usa el botón de abajo para forzar una prueba:</p>';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('wps_reset_profiling_nonce', 'wps_reset_profiling'); ?>
        <input type="hidden" name="action" value="wps_reset_profiling">
        <button type="submit" class="button button-secondary">Reset histórico</button>
    </form>
    <div class="wps-actions">
        <button id="wpsRunTest" class="button button-primary">Lanzar prueba</button>
        <span id="wpsSpinner" style="display:none;margin-left:10px;">⏳ Ejecutando prueba...</span>
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
            iframe.src = "<?php echo esc_url(home_url('/')); ?>?wps_profiling_test=1&t=" + Date.now();
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
    'Core'     => round($last['core']*1000, 2),
    'Plugins'  => round($last['plugins']*1000, 2),
    'Tema'     => round($last['theme']*1000, 2),
    'MySQL'    => $sql_time_ms ?? 0,
    'Externas' => $http_time_ms,
    'Total'    => round($last['total']*1000, 2),
];

$timestamps = array_map(fn($d) => date('H:i:s', $d['timestamp']), $history);
$totals = array_map(fn($d) => round($d['total']*1000,2), $history);
?>
<div class="wrap">
    <h1>Profiling del Home</h1>

    <?php if (isset($_GET['reset_done'])): ?>
        <div class="notice notice-success is-dismissible"><p>✅ Histórico borrado correctamente.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('wps_reset_profiling_nonce', 'wps_reset_profiling'); ?>
        <input type="hidden" name="action" value="wps_reset_profiling">
        <button type="submit" class="button button-secondary">Reset histórico</button>
    </form>

    <div class="wps-actions">
        <button id="wpsRunTest" class="button button-primary">Lanzar prueba</button>
        <span id="wpsSpinner" style="display:none;margin-left:10px;">⏳ Ejecutando prueba...</span>
        <iframe id="wpsTestFrame" style="display:none;width:1px;height:1px;"></iframe>
    </div>

    <h2>Última medición</h2>
    <div class="wps-chart-container">
        <canvas id="wpsProfilingChart"></canvas>
    </div>

    <ul>
        <?php foreach ($profile_data as $k => $v): ?>
            <li>
                <strong><?php echo esc_html($k); ?>:</strong>
                <?php if ($k === 'MySQL' && $sql_time_ms === null): ?>
                    N/A <em>(activa <code>define('SAVEQUERIES', true);</code> en wp-config.php para medir el tiempo SQL)</em>
                <?php else: ?>
                    <?php echo esc_html($v); ?> ms
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li><strong>Consultas SQL:</strong> <?php echo intval($last['sql_count']); ?></li>
        <li><strong>Llamadas HTTP:</strong> <?php echo intval($last['http_count']); ?></li>
    </ul>

    <h2>Evolución (últimas visitas)</h2>
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
            iframe.src = "<?php echo esc_url(home_url('/')); ?>?wps_profiling_test=1&t=" + Date.now();
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
                labels: <?php echo json_encode(array_keys($profile_data)); ?>,
                datasets: [{
                    label: 'Tiempo (ms)',
                    data: <?php echo json_encode(array_values($profile_data)); ?>,
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
                labels: <?php echo json_encode($timestamps); ?>,
                datasets: [{
                    label: 'Tiempo total (ms)',
                    data: <?php echo json_encode($totals); ?>,
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

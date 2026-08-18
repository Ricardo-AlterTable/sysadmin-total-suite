<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$history = get_option('stsuite_profiling_history', []);

if (empty($history)) {
    echo '<div class="wrap"><h1>' . esc_html__('Profiling', 'sysadmin-total-suite') . '</h1><p>' . esc_html__('No data yet. Use the button below to force a test:', 'sysadmin-total-suite') . '</p>';
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('stsuite_reset_profiling_nonce', 'stsuite_reset_profiling'); ?>
        <input type="hidden" name="action" value="stsuite_reset_profiling">
        <button type="submit" class="button button-secondary"><?php esc_html_e('Reset history', 'sysadmin-total-suite'); ?></button>
    </form>
    <div class="stsuite-actions">
        <button id="stsuiteRunTest" class="button button-primary"><?php esc_html_e('Run test', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteSpinner" style="display:none;margin-left:10px;">⏳ <?php esc_html_e('Running test...', 'sysadmin-total-suite'); ?></span>
        <iframe id="stsuiteTestFrame" style="display:none;width:1px;height:1px;"></iframe>
    </div>
    <?php
    // Datos para admin/assets/profiler.js (encolado en el fichero principal).
    wp_localize_script('stsuite-profiler-js', 'STSUITE_PROFILER', [
        'testUrl' => add_query_arg(
            [
                'stsuite_profiling_test' => 1,
                '_wpnonce'               => wp_create_nonce('stsuite_profiling_test'),
            ],
            home_url('/')
        ),
    ]);
    ?>
    <?php
    return;
}

$last = end($history);

$sql_time_ms  = isset($last['sql_time']) && $last['sql_time'] !== null ? round($last['sql_time'] * 1000, 2) : null;
$http_time_ms = round(($last['http_time'] ?? 0) * 1000, 2);

$profile_data = [
    __('Core', 'sysadmin-total-suite')     => round($last['core']*1000, 2),
    __('Plugins', 'sysadmin-total-suite')  => round($last['plugins']*1000, 2),
    __('Theme', 'sysadmin-total-suite')    => round($last['theme']*1000, 2),
    __('MySQL', 'sysadmin-total-suite')    => $sql_time_ms ?? 0,
    __('External', 'sysadmin-total-suite') => $http_time_ms,
    __('Total', 'sysadmin-total-suite')    => round($last['total']*1000, 2),
];

$timestamps = array_map(fn($d) => wp_date('H:i:s', $d['timestamp']), $history);
$totals = array_map(fn($d) => round($d['total']*1000,2), $history);
?>
<div class="wrap">
    <h1><?php esc_html_e('Home page profiling', 'sysadmin-total-suite'); ?></h1>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['reset_done'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('History cleared successfully.', 'sysadmin-total-suite'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 20px 0;">
        <?php wp_nonce_field('stsuite_reset_profiling_nonce', 'stsuite_reset_profiling'); ?>
        <input type="hidden" name="action" value="stsuite_reset_profiling">
        <button type="submit" class="button button-secondary"><?php esc_html_e('Reset history', 'sysadmin-total-suite'); ?></button>
    </form>

    <div class="stsuite-actions">
        <button id="stsuiteRunTest" class="button button-primary"><?php esc_html_e('Run test', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteSpinner" style="display:none;margin-left:10px;">⏳ <?php esc_html_e('Running test...', 'sysadmin-total-suite'); ?></span>
        <iframe id="stsuiteTestFrame" style="display:none;width:1px;height:1px;"></iframe>
    </div>

    <h2><?php esc_html_e('Last measurement', 'sysadmin-total-suite'); ?></h2>
    <div class="stsuite-chart-container">
        <canvas id="stsuiteProfilingChart"></canvas>
    </div>

    <ul>
        <?php foreach ($profile_data as $k => $v): ?>
            <li>
                <strong><?php echo esc_html($k); ?>:</strong>
                <?php if ($k === __('MySQL', 'sysadmin-total-suite') && $sql_time_ms === null): ?>
                    <?php
                    printf(
                        /* translators: %s: the SAVEQUERIES PHP constant snippet. */
                        esc_html__('N/A (enable %s in wp-config.php to measure SQL time)', 'sysadmin-total-suite'),
                        "<code>define('SAVEQUERIES', true);</code>"
                    );
                    ?>
                <?php else: ?>
                    <?php echo esc_html($v); ?> ms
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <li><strong><?php esc_html_e('SQL queries:', 'sysadmin-total-suite'); ?></strong> <?php echo intval($last['sql_count']); ?></li>
        <li><strong><?php esc_html_e('HTTP calls:', 'sysadmin-total-suite'); ?></strong> <?php echo intval($last['http_count']); ?></li>
    </ul>

    <h2><?php esc_html_e('Evolution (recent visits)', 'sysadmin-total-suite'); ?></h2>
    <div class="stsuite-chart-container">
        <canvas id="stsuiteHistoryChart"></canvas>
    </div>
    <?php
    // Datos para admin/assets/profiler.js (encolado en el fichero principal).
    wp_localize_script('stsuite-profiler-js', 'STSUITE_PROFILER', [
        'testUrl' => add_query_arg(
            [
                'stsuite_profiling_test' => 1,
                '_wpnonce'               => wp_create_nonce('stsuite_profiling_test'),
            ],
            home_url('/')
        ),
        'labels'     => array_keys($profile_data),
        'values'     => array_values($profile_data),
        'histLabels' => $timestamps,
        'histValues' => $totals,
        'labelTime'  => __('Time (ms)', 'sysadmin-total-suite'),
        'labelTotal' => __('Total time (ms)', 'sysadmin-total-suite'),
    ]);
    ?>
</div>

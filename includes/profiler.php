<?php
if (!defined('ABSPATH')) exit;

global $wps_profiler_data;
$wps_profiler_data = [
    'start'       => microtime(true),
    'milestones'  => [],
    'http_time'   => 0,
    'http_count'  => 0,
];

function wps_profiler_mark($key) {
    global $wps_profiler_data;
    $wps_profiler_data['milestones'][$key] = microtime(true);
}

add_action('plugins_loaded', fn() => wps_profiler_mark('plugins_loaded'));
add_action('after_setup_theme', fn() => wps_profiler_mark('after_setup_theme'));
add_action('template_redirect', fn() => wps_profiler_mark('template_redirect'));

add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    global $wps_profiler_data;
    $wps_profiler_data['http_count']++;
}, 10, 5);

add_action('shutdown', function () {
    // Guardar métrica solo si estamos en el home o si es una prueba lanzada desde el admin
    if (!(is_front_page() || isset($_GET['wps_profiling_test']))) {
        return;
    }

    global $wps_profiler_data, $wpdb;
    $end = microtime(true);

    $data = [
        'total'      => $end - $wps_profiler_data['start'],
        'core'       => ($wps_profiler_data['milestones']['plugins_loaded'] ?? $wps_profiler_data['start']) - $wps_profiler_data['start'],
        'plugins'    => ($wps_profiler_data['milestones']['after_setup_theme'] ?? $wps_profiler_data['start']) - ($wps_profiler_data['milestones']['plugins_loaded'] ?? $wps_profiler_data['start']),
        'theme'      => ($wps_profiler_data['milestones']['template_redirect'] ?? $wps_profiler_data['start']) - ($wps_profiler_data['milestones']['after_setup_theme'] ?? $wps_profiler_data['start']),
        'sql_count'  => is_array($wpdb->queries) ? count($wpdb->queries) : 0,
        'sql_time'   => is_array($wpdb->queries) ? array_sum(array_column($wpdb->queries, 1)) : 0,
        'http_count' => $wps_profiler_data['http_count'],
        'timestamp'  => time(),
    ];

    // Guardar histórico en opción
    $history = get_option('wps_profiling_history', []);
    if (!is_array($history)) $history = [];
    $history[] = $data;

    // Mantener solo las últimas 20
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }

    update_option('wps_profiling_history', $history, false);
});

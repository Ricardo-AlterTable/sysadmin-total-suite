<?php
if (!defined('ABSPATH')) exit;

global $stsuite_profiler_data;
$stsuite_profiler_data = [
    // Usar el inicio REAL de la petición ($timestart lo fija WP en wp-settings.php),
    // no el momento en que se carga este plugin. Así "Core" incluye el bootstrap real.
    'start'       => isset($GLOBALS['timestart']) ? (float) $GLOBALS['timestart'] : microtime(true),
    'milestones'  => [],
    'http_time'   => 0.0,
    'http_count'  => 0,
    'http_stack'  => [],
];

function stsuite_profiler_mark($key) {
    global $stsuite_profiler_data;
    $stsuite_profiler_data['milestones'][$key] = microtime(true);
}

add_action('plugins_loaded', fn() => stsuite_profiler_mark('plugins_loaded'));
add_action('after_setup_theme', fn() => stsuite_profiler_mark('after_setup_theme'));
add_action('template_redirect', fn() => stsuite_profiler_mark('template_redirect'));

// === Medición real del tiempo de las peticiones HTTP salientes ===
// Marcamos el inicio justo antes de que WP haga la petición. Devolvemos el valor
// recibido sin modificar para NO cortocircuitar la petición.
add_filter('pre_http_request', function ($preempt) {
    global $stsuite_profiler_data;
    $stsuite_profiler_data['http_stack'][] = microtime(true);
    return $preempt;
}, 10, 1);

// http_api_debug se dispara al terminar la petición: cerramos el cronómetro.
add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    global $stsuite_profiler_data;
    $stsuite_profiler_data['http_count']++;
    if (!empty($stsuite_profiler_data['http_stack'])) {
        $start = array_pop($stsuite_profiler_data['http_stack']);
        $stsuite_profiler_data['http_time'] += microtime(true) - $start;
    }
}, 10, 5);

add_action('shutdown', function () {
    // Una prueba manual solo cuenta si viene firmada desde el admin: sin esto,
    // cualquier visitante podría forzar una escritura en la base de datos por
    // petición (y saltarse la caché de página) con ?stsuite_profiling_test=1.
    $is_test = false;
    if (isset($_GET['stsuite_profiling_test'])) {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if ($nonce && wp_verify_nonce($nonce, 'stsuite_profiling_test') && current_user_can('manage_options')) {
            $is_test = true;
        } else {
            return; // petición no autorizada: no se registra nada
        }
    }

    if (!($is_test || is_front_page())) {
        return;
    }

    // Limitación de frecuencia para las visitas normales al home: evita escribir
    // en wp_options en cada visita no cacheada (coste y contención de fila).
    if (!$is_test) {
        if (get_transient('stsuite_profiling_throttle')) {
            return;
        }
        set_transient('stsuite_profiling_throttle', 1, MINUTE_IN_SECONDS);
    }

    global $stsuite_profiler_data, $wpdb;
    $end   = microtime(true);
    $start = $stsuite_profiler_data['start'];
    $m     = $stsuite_profiler_data['milestones'];

    // Hitos con respaldo: si un hook no llegó a ejecutarse, usamos el final.
    $plugins_loaded = $m['plugins_loaded']    ?? $end;
    $setup_theme    = $m['after_setup_theme'] ?? $plugins_loaded;
    $tpl_redirect   = $m['template_redirect'] ?? $setup_theme;

    // El tiempo de consultas solo está disponible si SAVEQUERIES está activo.
    $sql_time = null;
    if (defined('SAVEQUERIES') && SAVEQUERIES && is_array($wpdb->queries)) {
        $sql_time = array_sum(array_column($wpdb->queries, 1));
    }

    $data = [
        'total'      => max(0, $end - $start),
        'core'       => max(0, $plugins_loaded - $start),
        'plugins'    => max(0, $setup_theme - $plugins_loaded),
        'theme'      => max(0, $tpl_redirect - $setup_theme),
        'sql_count'  => function_exists('get_num_queries') ? get_num_queries() : (is_array($wpdb->queries) ? count($wpdb->queries) : 0),
        'sql_time'   => $sql_time,
        'http_count' => $stsuite_profiler_data['http_count'],
        'http_time'  => $stsuite_profiler_data['http_time'],
        'timestamp'  => time(),
    ];

    // Guardar histórico en opción (últimas 20 mediciones).
    $history = get_option('stsuite_profiling_history', []);
    if (!is_array($history)) $history = [];
    $history[] = $data;
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }

    update_option('stsuite_profiling_history', $history, false);
});

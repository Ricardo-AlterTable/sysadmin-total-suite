<?php
// includes/tunning.php — Chequeos de rendimiento (Tunning).
if (!defined('ABSPATH')) exit;

/**
 * Plugins instalados / activos. Los activos se cargan en CADA petición,
 * así que son los que pueden afectar a la velocidad de carga.
 */
function wps_tunning_plugins_info(): array {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all    = get_plugins();
    $active = (array) get_option('active_plugins', []);

    if (is_multisite()) {
        $network = array_keys((array) get_site_option('active_sitewide_plugins', []));
        $active  = array_unique(array_merge($active, $network));
    }

    $active_list = [];
    foreach ($active as $file) {
        $active_list[] = [
            'name'    => $all[$file]['Name'] ?? $file,
            'version' => $all[$file]['Version'] ?? '',
        ];
    }
    usort($active_list, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return [
        'total'       => count($all),
        'active'      => count($active),
        'inactive'    => max(0, count($all) - count($active)),
        'active_list' => $active_list,
    ];
}

/**
 * Estadísticas de opciones "autoload": son las que WordPress carga en cada
 * request. Opciones grandes de plugins viejos aquí = lastre de rendimiento.
 */
function wps_tunning_autoload_stats(int $top = 10): array {
    global $wpdb;
    // El valor de la columna autoload cambió en WP 6.6 ('yes/no' -> 'on/off/auto').
    $yes = "autoload IN ('yes', 'on', 'auto', 'auto-on')";

    $total_bytes = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE {$yes}");
    $count       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE {$yes}");
    $largest     = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, LENGTH(option_value) AS sz FROM {$wpdb->options} WHERE {$yes} ORDER BY sz DESC LIMIT %d",
        $top
    ), ARRAY_A);

    return [
        'total_bytes' => $total_bytes,
        'count'       => $count,
        'largest'     => $largest ?: [],
    ];
}

/**
 * Transitorios en wp_options. Los caducados son basura que se puede limpiar.
 */
function wps_tunning_transient_stats(): array {
    global $wpdb;
    $now = time();

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%'"
    );
    $expired = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%%' AND option_value < %d",
        $now
    ));

    return ['total' => $total, 'expired' => $expired];
}

/**
 * Detección de caché: plugins conocidos + drop-ins + constante WP_CACHE.
 */
function wps_tunning_cache_info(): array {
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $known = [
        'litespeed-cache/litespeed-cache.php'     => 'LiteSpeed Cache',
        'wp-super-cache/wp-cache.php'             => 'WP Super Cache',
        'w3-total-cache/w3-total-cache.php'       => 'W3 Total Cache',
        'wp-rocket/wp-rocket.php'                 => 'WP Rocket',
        'cache-enabler/cache-enabler.php'         => 'Cache Enabler',
        'wp-fastest-cache/wpFastestCache.php'     => 'WP Fastest Cache',
        'sg-cachepress/sg-cachepress.php'         => 'SG Optimizer (SiteGround)',
        'redis-cache/redis-cache.php'             => 'Redis Object Cache',
        'nginx-helper/nginx-helper.php'           => 'Nginx Helper',
        'breeze/breeze.php'                       => 'Breeze',
        'swift-performance-lite/performance.php'  => 'Swift Performance Lite',
        'autoptimize/autoptimize.php'             => 'Autoptimize',
    ];

    $active = [];
    foreach ($known as $file => $name) {
        if (is_plugin_active($file)) $active[] = $name;
    }

    $advanced_cache = file_exists(WP_CONTENT_DIR . '/advanced-cache.php');
    $object_cache   = file_exists(WP_CONTENT_DIR . '/object-cache.php');
    $wp_cache_const = defined('WP_CACHE') && WP_CACHE;

    return [
        'plugins'            => $active,
        'page_cache_enabled' => $wp_cache_const && $advanced_cache,
        'advanced_cache'     => $advanced_cache,
        'object_cache'       => $object_cache,
        'wp_cache_const'     => $wp_cache_const,
    ];
}

/**
 * Versiones del entorno: PHP y MySQL/MariaDB.
 */
function wps_tunning_env_versions(): array {
    global $wpdb;
    $server     = $wpdb->db_server_info();               // p.ej. "10.6.12-MariaDB-log"
    $is_mariadb = stripos($server, 'mariadb') !== false;

    return [
        'php'            => phpversion(),
        'db_type'        => $is_mariadb ? 'MariaDB' : 'MySQL',
        'db_server_info' => $server,
        'db_version'     => $wpdb->db_version(),          // numérico (MariaDB reporta 5.5.5 por compatibilidad)
        'wp'             => get_bloginfo('version'),
    ];
}

/**
 * Ajax: limpiar transitorios caducados (seguro; se regeneran solos).
 */
add_action('wp_ajax_wps_clean_transients', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_clean_transients', 'nonce');

    $before = wps_tunning_transient_stats();

    if (function_exists('delete_expired_transients')) {
        delete_expired_transients(true); // fuerza aunque haya caché de objetos externa
    }

    $after   = wps_tunning_transient_stats();
    $removed = max(0, $before['expired'] - $after['expired']);

    wp_send_json_success([
        'removed' => $removed,
        'message' => 'Transitorios caducados eliminados: ' . $removed,
    ]);
});

<?php
/**
 * Desinstalación: elimina los datos que crea el plugin.
 *
 * Solo se ejecuta cuando el usuario borra el plugin desde WordPress.
 * NO se eliminan las copias de seguridad de archivos del core: son datos que el
 * usuario ha pedido conservar explícitamente y su borrado sería irreversible.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wps_options = [
    'wps_profiling_history',
    'wps_aibots_settings',
    'wps_cron_backup',
];

/**
 * Borra las opciones y transitorios del sitio actual.
 */
function wps_uninstall_clean_site($options) {
    foreach ($options as $option) {
        delete_option($option);
    }
    delete_transient('wps_last_analysis');
    delete_transient('wps_profiling_throttle');
}

if (is_multisite()) {
    // uninstall.php se ejecuta una sola vez: hay que recorrer toda la red.
    foreach ($wps_options as $wps_option) {
        delete_site_option($wps_option);
    }
    if (function_exists('get_sites') && function_exists('switch_to_blog')) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $wps_blog_id) {
            switch_to_blog($wps_blog_id);
            wps_uninstall_clean_site($wps_options);
            restore_current_blog();
        }
    }
} else {
    wps_uninstall_clean_site($wps_options);
}

// Caché de ZIP descargados en uploads.
$wps_upload = wp_upload_dir();
if (empty($wps_upload['error']) && !empty($wps_upload['basedir'])) {
    $wps_cache_dir = trailingslashit($wps_upload['basedir']) . 'wp-profiler-security-cache/';
    if (is_dir($wps_cache_dir)) {
        // Incluye los ficheros de protección (.htaccess / index.php).
        foreach ((array) glob($wps_cache_dir . '{,.}*', GLOB_BRACE) as $wps_file) {
            if (is_file($wps_file)) {
                @unlink($wps_file);
            }
        }
        @rmdir($wps_cache_dir);
    }
}

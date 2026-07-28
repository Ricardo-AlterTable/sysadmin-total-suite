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

// Caché de ZIP descargados en uploads (nombre actual y el previo al renombrado).
$wps_upload = wp_upload_dir();
if (empty($wps_upload['error']) && !empty($wps_upload['basedir'])) {
    $wps_cache_dirs = [
        trailingslashit($wps_upload['basedir']) . 'site-integrity-profiler-cache/',
        trailingslashit($wps_upload['basedir']) . 'wp-profiler-security-cache/',
    ];

    require_once ABSPATH . 'wp-admin/includes/file.php';
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        WP_Filesystem();
    }

    foreach ($wps_cache_dirs as $wps_cache_dir) {
        if (!is_dir($wps_cache_dir)) {
            continue;
        }
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->delete($wps_cache_dir, true);
            continue;
        }
        // Reserva si WP_Filesystem no está disponible. Incluye los ficheros de
        // protección (.htaccess / index.php), de ahí el patrón con GLOB_BRACE.
        foreach ((array) glob($wps_cache_dir . '{,.}*', GLOB_BRACE) as $wps_file) {
            if (is_file($wps_file)) {
                wp_delete_file($wps_file);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Reserva cuando WP_Filesystem no está disponible.
        @rmdir($wps_cache_dir);
    }
}

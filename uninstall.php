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

$stsuite_options = [
    'stsuite_profiling_history',
    'stsuite_aibots_settings',
    'stsuite_cron_backup',
];

/**
 * Borra las opciones y transitorios del sitio actual.
 */
function stsuite_uninstall_clean_site($options) {
    foreach ($options as $option) {
        delete_option($option);
    }
    delete_transient('stsuite_last_analysis');
    delete_transient('stsuite_profiling_throttle');
}

if (is_multisite()) {
    // uninstall.php se ejecuta una sola vez: hay que recorrer toda la red.
    foreach ($stsuite_options as $stsuite_option) {
        delete_site_option($stsuite_option);
    }
    if (function_exists('get_sites') && function_exists('switch_to_blog')) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $stsuite_blog_id) {
            switch_to_blog($stsuite_blog_id);
            stsuite_uninstall_clean_site($stsuite_options);
            restore_current_blog();
        }
    }
} else {
    stsuite_uninstall_clean_site($stsuite_options);
}

// Caché de ZIP descargados en uploads (nombre actual y el previo al renombrado).
$stsuite_upload = wp_upload_dir();
if (empty($stsuite_upload['error']) && !empty($stsuite_upload['basedir'])) {
    $stsuite_cache_dirs = [
        trailingslashit($stsuite_upload['basedir']) . 'sysadmin-total-suite-cache/',
        trailingslashit($stsuite_upload['basedir']) . 'wp-profiler-security-cache/',
    ];

    require_once ABSPATH . 'wp-admin/includes/file.php';
    global $wp_filesystem;
    if (empty($wp_filesystem)) {
        WP_Filesystem();
    }

    foreach ($stsuite_cache_dirs as $stsuite_cache_dir) {
        if (!is_dir($stsuite_cache_dir)) {
            continue;
        }
        if ($wp_filesystem instanceof WP_Filesystem_Base) {
            $wp_filesystem->delete($stsuite_cache_dir, true);
            continue;
        }
        // Reserva si WP_Filesystem no está disponible. Incluye los ficheros de
        // protección (.htaccess / index.php), de ahí el patrón con GLOB_BRACE.
        foreach ((array) glob($stsuite_cache_dir . '{,.}*', GLOB_BRACE) as $stsuite_file) {
            if (is_file($stsuite_file)) {
                wp_delete_file($stsuite_file);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Reserva cuando WP_Filesystem no está disponible.
        @rmdir($stsuite_cache_dir);
    }
}

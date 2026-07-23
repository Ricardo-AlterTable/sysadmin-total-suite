<?php
/**
 * Plugin Name: WP Profiler & Security
 * Description: Analiza la integridad del core de WordPress, permite restaurar archivos modificados y añade una sección de profiling de tiempos (core, plugins, tema, SQL y HTTP).
 * Version: 2.7
 * Author: Ricardo Morales
 * Author URI: https://github.com/Ricardo-AlterTable
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

define('WPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPS_PLUGIN_DIR . 'includes/diff.php';
require_once WPS_PLUGIN_DIR . 'includes/profiler.php';
require_once WPS_PLUGIN_DIR . 'includes/users.php';
require_once WPS_PLUGIN_DIR . 'includes/tunning.php';

/**
 * Indica si una ruta relativa pertenece realmente al core de WordPress.
 * Solo wp-admin/, wp-includes/ y los ficheros sueltos de la raíz forman parte
 * del ZIP oficial. wp-content/ (temas y plugins) queda fuera: no se puede
 * verificar contra los checksums del core ni restaurar desde él.
 */
function wps_is_core_path($rel) {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (strpos($rel, 'wp-admin/') === 0) return true;
    if (strpos($rel, 'wp-includes/') === 0) return true;
    if (strpos($rel, '/') === false) return true; // fichero suelto en la raíz
    return false;
}

// =============================
// Menús del admin
// =============================
add_action('admin_menu', function () {
    add_menu_page(
        'WP Profiler & Security',
        'WP Profiler & Security',
        'manage_options',
        'wp-profiler-security',
        'wps_profiler_dashboard',
        'dashicons-shield',
        3
    );

    add_submenu_page(
        'wp-profiler-security',
        'Integridad',
        'Integridad',
        'manage_options',
        'wp-profiler-security',
        'wps_profiler_dashboard'
    );

    add_submenu_page(
        'wp-profiler-security',
        'Profiling',
        'Profiling',
        'manage_options',
        'wp-profiler-profiling',
        'wps_profiler_profiling_page'
    );

    add_submenu_page(
        'wp-profiler-security',
        'Comprobar usuarios WP',
        'Comprobar usuarios WP',
        'list_users',
        'wp-profiler-users',
        'wps_profiler_users_page'
    );

    add_submenu_page(
        'wp-profiler-security',
        'Tunning',
        'Tunning',
        'manage_options',
        'wp-profiler-tunning',
        'wps_profiler_tunning_page'
    );
});

// =============================
// Assets
// =============================
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'wp-profiler-security') === false) return;

    wp_enqueue_style('wps-admin-css', WPS_PLUGIN_URL . 'admin/assets/admin.css', [], '2.7');
    wp_enqueue_script('wps-admin-js', WPS_PLUGIN_URL . 'admin/assets/admin.js', ['jquery'], '2.7', true);

    // Chart.js solo en profiling
    if (isset($_GET['page']) && $_GET['page'] === 'wp-profiler-profiling') {
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);
    }

    wp_localize_script('wps-admin-js', 'WPS_AJAX', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wps_diff_nonce'),
    ]);
});

// =============================
// Páginas
// =============================
function wps_profiler_dashboard() {
    include WPS_PLUGIN_DIR . 'admin/dashboard.php';
}

function wps_profiler_profiling_page() {
    include WPS_PLUGIN_DIR . 'admin/profiler.php';
}

function wps_profiler_users_page() {
    include WPS_PLUGIN_DIR . 'admin/users.php';
}

function wps_profiler_tunning_page() {
    include WPS_PLUGIN_DIR . 'admin/tunning.php';
}

// =============================
// Acción de análisis (integridad)
// =============================
add_action('admin_post_wps_run_analysis', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes', 403);
    }
    check_admin_referer('wps_run_analysis_nonce');

    // Ficheros de la raíz que no forman parte del core y no deben marcarse como "Extra".
    $excluded_files = [
        'wp-config.php',
        '.htaccess',
        'robots.txt',
    ];

    global $wpdb;
    $start = microtime(true);

    $wpdb->get_results("SELECT * FROM {$wpdb->posts} LIMIT 5");
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    $version  = get_bloginfo('version');
    $locale   = get_locale();
    $url      = "https://api.wordpress.org/core/checksums/1.0/?version=" . rawurlencode($version) . "&locale=" . rawurlencode($locale);
    $response = wp_remote_get($url, ['timeout'=>20]);

    $checksum_result = "No se pudo verificar el core.";
    $errors = [];
    $modified_files = [];

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['checksums']) && is_array($data['checksums'])) {
            $checksums = $data['checksums'];

            // Verificar solo los ficheros que pertenecen de verdad al core
            // (wp-admin/, wp-includes/ y ficheros sueltos de la raíz). Los archivos
            // de wp-content/ no están en el ZIP del core, así que se ignoran.
            foreach ($checksums as $file => $md5) {
                if (!wps_is_core_path($file) || in_array($file, $excluded_files, true)) {
                    continue;
                }
                $path = ABSPATH . $file;
                if (file_exists($path)) {
                    if (@md5_file($path) !== $md5) {
                        $errors[] = "Modificado: $file";
                        $modified_files[] = $file;
                    }
                } else {
                    $errors[] = "Faltante: $file";
                    $modified_files[] = $file;
                }
            }

            // Detección de "Extra": wp-admin y wp-includes se recorren completos;
            // la raíz solo a primer nivel (sin entrar en wp-content ni en uploads).
            foreach (['wp-admin', 'wp-includes'] as $dir) {
                $base = ABSPATH . $dir;
                if (!is_dir($base)) continue;
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $fileinfo) {
                    if (!$fileinfo->isFile()) continue;
                    $rel = str_replace('\\', '/', str_replace(ABSPATH, '', $fileinfo->getPathname()));
                    if (in_array($rel, $excluded_files, true)) continue;
                    if (!isset($checksums[$rel])) {
                        $errors[] = "Extra: $rel";
                    }
                }
            }

            foreach (new DirectoryIterator(ABSPATH) as $fileinfo) {
                if (!$fileinfo->isFile()) continue;
                $rel = $fileinfo->getFilename();
                if (in_array($rel, $excluded_files, true)) continue;
                if (!isset($checksums[$rel])) {
                    $errors[] = "Extra: $rel";
                }
            }

            $checksum_result = empty($errors)
                ? "✔ Core verificado correctamente"
                : "⚠ Problemas detectados:\n" . implode("\n", array_slice($errors, 0, 100));
        }
    }

    $analysis_data = [
        'time_ms' => $elapsed,
        'checksum' => $checksum_result,
        'errors' => $errors,
        'modified_files' => array_values(array_unique($modified_files)),
        'version' => $version,
        'locale' => $locale,
        'checked_at' => time(),
    ];
    set_transient('wps_last_analysis', $analysis_data, 5 * MINUTE_IN_SECONDS);

    wp_redirect(admin_url('admin.php?page=wp-profiler-security'));
    exit;
});

// =============================
// Acción para purgar la caché
// =============================
add_action('admin_post_wps_purge_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes', 403);
    }
    check_admin_referer('wps_purge_cache_nonce', 'wps_purge_cache');

    // Borrar el transitorio de análisis
    delete_transient('wps_last_analysis');

    // Borrar la caché de archivos ZIP
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/wp-profiler-security-cache/';
    if (is_dir($cache_dir)) {
        $files = glob($cache_dir . '/*'); 
        foreach($files as $file){ 
            if(is_file($file)) {
                @unlink($file); 
            }
        }
        @rmdir($cache_dir);
    }

    wp_redirect(admin_url('admin.php?page=wp-profiler-security&cache_purged=1'));
    exit;
});


// =============================
// Reset histórico profiling
// =============================
add_action('admin_post_wps_reset_profiling', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes', 403);
    }
    check_admin_referer('wps_reset_profiling_nonce', 'wps_reset_profiling');

    delete_option('wps_profiling_history');

    wp_redirect(admin_url('admin.php?page=wp-profiler-profiling&reset_done=1'));
    exit;
});

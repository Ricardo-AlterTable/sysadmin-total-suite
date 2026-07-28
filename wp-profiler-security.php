<?php
/**
 * Plugin Name: WP Profiler & Security
 * Description: Integridad del core, profiling de tiempos, gestión de usuarios, WPO (rendimiento) y bloqueo de bots de IA, en un panel de administración unificado.
 * Version: 3.7
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Ricardo Morales
 * Author URI: https://github.com/Ricardo-AlterTable
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-profiler-security
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('WPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Cargar traducciones.
add_action('init', function () {
    load_plugin_textdomain('wp-profiler-security', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once WPS_PLUGIN_DIR . 'includes/diff.php';
require_once WPS_PLUGIN_DIR . 'includes/profiler.php';
require_once WPS_PLUGIN_DIR . 'includes/users.php';
require_once WPS_PLUGIN_DIR . 'includes/wpo.php';
require_once WPS_PLUGIN_DIR . 'includes/aibots.php';

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

/**
 * Resuelve una ruta relativa a una ruta absoluta dentro de ABSPATH, de forma
 * segura y SIN exigir que el archivo exista (necesario para restaurar archivos
 * faltantes, donde realpath() devolvería false).
 *
 * Normaliza la ruta resolviendo '.' y '..' de forma léxica y comprueba que el
 * resultado siga dentro de la raíz del sitio. Además, si el directorio padre ya
 * existe, valida su realpath para evitar escapes por enlaces simbólicos.
 *
 * @return string|false Ruta absoluta normalizada, o false si no es válida.
 */
function wps_resolve_site_path($rel) {
    $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
    if ($rel === '' || strpos($rel, "\0") !== false) return false;

    // Resolución léxica de segmentos: rechaza cualquier salida de la raíz.
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (empty($parts)) return false; // intenta salir de ABSPATH
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    if (empty($parts)) return false;

    $root   = wp_normalize_path(realpath(ABSPATH));
    $target = wp_normalize_path(trailingslashit($root) . implode('/', $parts));

    if (strpos($target, trailingslashit($root)) !== 0) return false;

    // Si el padre existe, su realpath debe seguir dentro de la raíz (symlinks).
    $parent = dirname($target);
    if (is_dir($parent)) {
        $real_parent = realpath($parent);
        if ($real_parent === false) return false;
        $real_parent = wp_normalize_path($real_parent);
        if ($real_parent !== $root && strpos(trailingslashit($real_parent), trailingslashit($root)) !== 0) {
            return false;
        }
    }

    // Si el archivo ya existe, su realpath también debe estar dentro.
    if (file_exists($target)) {
        $real = realpath($target);
        if ($real === false || strpos(wp_normalize_path($real), trailingslashit($root)) !== 0) {
            return false;
        }
    }

    return $target;
}

/**
 * Devuelve la ruta relativa a ABSPATH de una ruta absoluta ya validada.
 *
 * @return string|false
 */
function wps_relative_site_path($abs) {
    $root = wp_normalize_path(trailingslashit(realpath(ABSPATH)));
    $abs  = wp_normalize_path((string) $abs);
    if (strpos($abs, $root) !== 0) return false;
    $rel = substr($abs, strlen($root));
    return ($rel === '' || $rel === false) ? false : $rel;
}

/**
 * Protege un directorio frente a listado y acceso directo por HTTP.
 *
 * Nota: .htaccess solo lo respetan Apache/LiteSpeed. En nginx hay que denegar
 * la ruta en la configuración del servidor, por eso el contenido se guarda
 * además con una extensión neutralizada (.bak) para que no sea ejecutable.
 */
function wps_protect_dir($dir) {
    $dir = trailingslashit($dir);

    if (!file_exists($dir . '.htaccess')) {
        // Se cubren las sintaxis de Apache 2.4 (mod_authz_core) y 2.2 para no
        // provocar un error 500 en servidores antiguos.
        $htaccess  = "Options -Indexes\n";
        $htaccess .= "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n";
        $htaccess .= "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
        @file_put_contents($dir . '.htaccess', $htaccess);
    }
    if (!file_exists($dir . 'index.php')) {
        @file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n");
    }
}

/**
 * Ruta de un directorio de trabajo del plugin dentro de uploads, protegido.
 *
 * @param string $name   Nombre del directorio base.
 * @param string $subdir Subdirectorio opcional dentro del base.
 * @return array{dir:string,rel:string}|false
 */
function wps_plugin_dir_in_uploads($name, $subdir = '') {
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir'])) return false;

    $base = trailingslashit($upload['basedir']) . $name . '/';
    if (!wp_mkdir_p($base)) return false;
    wps_protect_dir($base);

    $dir = $base . ($subdir !== '' ? trailingslashit($subdir) : '');
    if ($subdir !== '' && !wp_mkdir_p($dir)) return false;

    // Ruta relativa a la raíz del sitio, derivada de la real (soporta uploads
    // personalizados y multisitio, donde basedir incluye /sites/N/).
    $root = wp_normalize_path(trailingslashit(ABSPATH));
    $rel  = str_replace($root, '', wp_normalize_path(trailingslashit($dir)));

    return ['dir' => trailingslashit($dir), 'rel' => $rel];
}

/**
 * Directorio de copias de seguridad de la restauración.
 *
 * El subdirectorio lleva un sufijo aleatorio para que la ruta no sea adivinable:
 * la copia contiene el archivo TAL CUAL estaba antes de restaurar, es decir, la
 * versión posiblemente manipulada por un atacante.
 *
 * @return array{dir:string,rel:string}|false
 */
function wps_backup_dir() {
    $subdir = gmdate('Y-m-d-His') . '-' . wp_generate_password(12, false, false);
    return wps_plugin_dir_in_uploads('wp-profiler-security-backups', $subdir);
}

/**
 * Guarda una copia del archivo con la extensión neutralizada (.bak), para que
 * no sea ejecutable como PHP si el directorio quedara accesible por HTTP.
 */
function wps_backup_file($abs_file, $rel, array $backup) {
    $dest = $backup['dir'] . $rel . '.bak';
    if (!wp_mkdir_p(dirname($dest))) return false;
    if (!@copy($abs_file, $dest)) return false;
    return $backup['rel'] . $rel . '.bak';
}

/**
 * Eleva los límites de ejecución/memoria en operaciones largas (análisis de
 * miles de ficheros, restauración masiva) para no morir a mitad del proceso.
 */
function wps_raise_limits() {
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    if (function_exists('set_time_limit') && !ini_get('safe_mode')) {
        @set_time_limit(300);
    }
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
        esc_html__('Integrity', 'wp-profiler-security'),
        esc_html__('Integrity', 'wp-profiler-security'),
        'manage_options',
        'wp-profiler-security',
        'wps_profiler_dashboard'
    );

    add_submenu_page(
        'wp-profiler-security',
        esc_html__('Profiling', 'wp-profiler-security'),
        esc_html__('Profiling', 'wp-profiler-security'),
        'manage_options',
        'wp-profiler-profiling',
        'wps_profiler_profiling_page'
    );

    add_submenu_page(
        'wp-profiler-security',
        esc_html__('Check WP users', 'wp-profiler-security'),
        esc_html__('Check WP users', 'wp-profiler-security'),
        'list_users',
        'wp-profiler-users',
        'wps_profiler_users_page'
    );

    add_submenu_page(
        'wp-profiler-security',
        esc_html__('WPO', 'wp-profiler-security'),
        esc_html__('WPO', 'wp-profiler-security'),
        'manage_options',
        'wp-profiler-wpo',
        'wps_profiler_wpo_page'
    );

    add_submenu_page(
        'wp-profiler-security',
        esc_html__('AI bot blocking', 'wp-profiler-security'),
        esc_html__('AI bot blocking', 'wp-profiler-security'),
        'manage_options',
        'wp-profiler-aibots',
        'wps_profiler_aibots_page'
    );
});

// =============================
// Assets
// =============================
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'wp-profiler-security') === false) return;

    wp_enqueue_style('wps-admin-css', WPS_PLUGIN_URL . 'admin/assets/admin.css', [], '3.7');
    wp_enqueue_script('wps-admin-js', WPS_PLUGIN_URL . 'admin/assets/admin.js', ['jquery'], '3.7', true);

    // Chart.js (empaquetada localmente; WordPress.org no permite CDN externos).
    if (isset($_GET['page']) && $_GET['page'] === 'wp-profiler-profiling') {
        wp_enqueue_script('chartjs', WPS_PLUGIN_URL . 'admin/assets/chart.min.js', [], '4.4.0', true);
    }

    wp_localize_script('wps-admin-js', 'WPS_AJAX', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wps_diff_nonce'),
        'i18n'     => [
            'timeout'          => __('The operation timed out.', 'wp-profiler-security'),
            'commError'        => __('Communication error with the server.', 'wp-profiler-security'),
            'errorPrefix'      => __('Error: %s', 'wp-profiler-security'),
            'loadingDiff'      => __('Loading diff...', 'wp-profiler-security'),
            'noWpsAjax'        => __('WPS_AJAX is not defined. Make sure wp_localize_script() ran.', 'wp-profiler-security'),
            // Restore file
            'confirmRestore'   => __('Are you sure you want to restore this file from the original core?', 'wp-profiler-security'),
            'askBackupFile'    => __("Do you want to keep a backup of the current file before overwriting it?\n\nOK = yes, make a backup\nCancel = restore without a backup", 'wp-profiler-security'),
            'restoring'        => __('Restoring...', 'wp-profiler-security'),
            'restore'          => __('Restore', 'wp-profiler-security'),
            'fileRestored'     => __('File restored: %s', 'wp-profiler-security'),
            'backupSavedIn'    => __('Backup saved in:', 'wp-profiler-security'),
            'noBackupMade'     => __('(No backup was created)', 'wp-profiler-security'),
            'restoreError'     => __('Could not restore', 'wp-profiler-security'),
            // Restore all
            'confirmRestoreAll'=> __('Are you sure you want to restore ALL modified/missing files?', 'wp-profiler-security'),
            'askBackupAll'     => __("Do you want to keep a backup of the current files before overwriting them?\n\nOK = yes, make a backup\nCancel = restore without a backup", 'wp-profiler-security'),
            'noFilesToRestore' => __('There are no files to restore.', 'wp-profiler-security'),
            'restoreAll'       => __('Restore all', 'wp-profiler-security'),
            'opDone'           => __('Operation completed.', 'wp-profiler-security'),
            'filesRestoredN'   => __('Restored files (%d):', 'wp-profiler-security'),
            'filesWithErrorsN' => __('Files with errors (%d):', 'wp-profiler-security'),
            'restoreAllError'  => __('Could not restore all', 'wp-profiler-security'),
            // Delete extra
            'confirmDeleteExtra'=> __("Delete this file?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the file is permanently removed and cannot be undone.", 'wp-profiler-security'),
            'confirmDeleteAllExtra'=> __("Delete ALL files not recognized by WordPress?\n\n⚠ This action is IRREVERSIBLE: the files are permanently removed and cannot be undone.", 'wp-profiler-security'),
            'deleting'         => __('Deleting...', 'wp-profiler-security'),
            'delete'           => __('Delete', 'wp-profiler-security'),
            'deleteAllExtra'   => __('Delete all extra files', 'wp-profiler-security'),
            'deletedN'         => __('Deleted (%d):', 'wp-profiler-security'),
            'deleteError'      => __('Could not delete', 'wp-profiler-security'),
            // Transients
            'confirmCleanTransients'=> __("Clean up expired transients?\n\nThis is safe: they are expired temporary data and WordPress regenerates them when needed.", 'wp-profiler-security'),
            'cleaning'         => __('Cleaning...', 'wp-profiler-security'),
            'transientsRemoved'=> __('Expired transients removed: %s', 'wp-profiler-security'),
            'cleanTransients'  => __('Clean up expired transients', 'wp-profiler-security'),
            'cleanError'       => __('Could not clean up', 'wp-profiler-security'),
            // Cron
            'confirmCleanCronHook'=> __("Remove the cron tasks for this hook?\n\n%s\n\nIt appears orphaned (no code attached right now), but a plugin may register it only on the front end. A copy of the schedule is saved beforehand so it can be restored manually.", 'wp-profiler-security'),
            'confirmCleanCronAll'=> __("Remove ALL cron tasks detected as orphaned?\n\nSome may belong to plugins that register their hook only on the front end, so review the list first. A copy of the schedule is saved beforehand so it can be restored manually.", 'wp-profiler-security'),
            'cronRemoved'      => __('Orphan cron tasks removed: %s', 'wp-profiler-security'),
            'hooksCleaned'     => __('Cleaned hooks:', 'wp-profiler-security'),
            'cleanAllCron'     => __('Clean up all orphan cron tasks', 'wp-profiler-security'),
            // Delete user
            'confirmDeleteUser1'=> __("Are you sure you want to DELETE the user?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the user is permanently removed and cannot be undone.", 'wp-profiler-security'),
            'confirmDeleteUser2'=> __("Final confirmation.\n\nThe user \"%s\" and the content they authored will be removed.\n\nContinue with permanent deletion?", 'wp-profiler-security'),
            'deleteUserError'  => __('Could not delete the user', 'wp-profiler-security'),
        ],
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

function wps_profiler_wpo_page() {
    include WPS_PLUGIN_DIR . 'admin/wpo.php';
}

function wps_profiler_aibots_page() {
    include WPS_PLUGIN_DIR . 'admin/aibots.php';
}

// =============================
// Acción de análisis (integridad)
// =============================
add_action('admin_post_wps_run_analysis', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
    }
    check_admin_referer('wps_run_analysis_nonce');
    wps_raise_limits();

    // Ficheros que no forman parte del core pero son legítimos: no deben
    // marcarse como "Extra" para que no se ofrezca su borrado.
    // Además, más abajo se ignoran TODOS los ficheros ocultos de la raíz
    // (.user.ini, .env, .htpasswd, ...) por el mismo motivo.
    $excluded_files = [
        'wp-config.php',
        'wp-config-sample.php',
        'robots.txt',
        'ads.txt',
        'app-ads.txt',
        'sitemap.xml',
        'sitemap_index.xml',
        'sitemap.xml.gz',
        'favicon.ico',
        'favicon.png',
        'apple-touch-icon.png',
        'apple-touch-icon-precomposed.png',
        'browserconfig.xml',
        'manifest.json',
        'site.webmanifest',
        'llms.txt',
        'security.txt',
        'humans.txt',
        'php.ini',
        'web.config',
        'wp-cli.yml',
        'wp-cli.local.yml',
    ];

    /**
     * Permite ajustar la lista de ficheros que nunca se marcan como "Extra".
     *
     * @param string[] $excluded_files Nombres de fichero relativos a la raíz.
     */
    $excluded_files = (array) apply_filters('wps_integrity_excluded_files', $excluded_files);

    global $wpdb;
    $start = microtime(true);

    $wpdb->get_results("SELECT * FROM {$wpdb->posts} LIMIT 5");
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    $version  = get_bloginfo('version');
    $locale   = get_locale();
    $url      = "https://api.wordpress.org/core/checksums/1.0/?version=" . rawurlencode($version) . "&locale=" . rawurlencode($locale);
    $response = wp_remote_get($url, ['timeout'=>20]);

    $checksum_result = 'core_check_failed';
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
                        $errors[] = "Modified: $file";
                        $modified_files[] = $file;
                    }
                } else {
                    $errors[] = "Missing: $file";
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
                // Los ficheros ocultos de la raíz (.htaccess, .user.ini, .env,
                // .htpasswd...) son configuración legítima del sitio o del
                // hosting: no se marcan como intrusos.
                if ($rel === '' || $rel[0] === '.') continue;
                // Los ficheros de verificación de buscadores tienen nombre
                // variable (googleXXXX.html, BingSiteAuth.xml...).
                if (preg_match('/^(google[0-9a-f]{8,}\.html|BingSiteAuth\.xml|yandex_[0-9a-f]+\.html|pinterest-[0-9a-z]+\.html)$/i', $rel)) continue;
                if (in_array($rel, $excluded_files, true)) continue;
                if (!isset($checksums[$rel])) {
                    $errors[] = "Extra: $rel";
                }
            }

            $checksum_result = empty($errors)
                ? 'core_ok'
                : 'issues_found';
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
    set_transient('wps_last_analysis', $analysis_data, HOUR_IN_SECONDS);

    wp_redirect(admin_url('admin.php?page=wp-profiler-security'));
    exit;
});

// =============================
// Acción para purgar la caché
// =============================
add_action('admin_post_wps_purge_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
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
        wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
    }
    check_admin_referer('wps_reset_profiling_nonce', 'wps_reset_profiling');

    delete_option('wps_profiling_history');

    wp_redirect(admin_url('admin.php?page=wp-profiler-profiling&reset_done=1'));
    exit;
});

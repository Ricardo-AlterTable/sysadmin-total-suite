<?php
/**
 * Plugin Name: Sysadmin Total Suite
 * Description: Core integrity checks, load-time profiling, user review, performance (WPO) diagnostics and AI bot blocking in a single admin panel.
 * Version: 5.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Ricardo Morales
 * Author URI: https://github.com/Ricardo-AlterTable
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sysadmin-total-suite
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('STSUITE_VERSION', '5.0');
define('STSUITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('STSUITE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Las traducciones las carga WordPress automáticamente a partir de la 4.6 para
// los plugins alojados en WordPress.org, así que no se llama a
// load_plugin_textdomain(). Ver: https://make.wordpress.org/core/2024/10/21/i18n-improvements-6-7/

require_once STSUITE_PLUGIN_DIR . 'includes/diff.php';
require_once STSUITE_PLUGIN_DIR . 'includes/profiler.php';
require_once STSUITE_PLUGIN_DIR . 'includes/users.php';
require_once STSUITE_PLUGIN_DIR . 'includes/wpo.php';
require_once STSUITE_PLUGIN_DIR . 'includes/aibots.php';

/**
 * Migración única de los datos guardados con el prefijo anterior ('wps_'),
 * para que al renombrar el plugin no se pierdan los ajustes del usuario.
 */
add_action('admin_init', function () {
    if (get_option('stsuite_migrated_prefix')) {
        return;
    }
    $map = [
        'wps_profiling_history' => 'stsuite_profiling_history',
        'wps_aibots_settings'   => 'stsuite_aibots_settings',
        'wps_cron_backup'       => 'stsuite_cron_backup',
    ];
    foreach ($map as $old => $new) {
        $value = get_option($old, null);
        if ($value !== null && get_option($new, null) === null) {
            update_option($new, $value, false);
        }
        delete_option($old);
    }
    update_option('stsuite_migrated_prefix', 1, false);
});

/**
 * Indica si una ruta relativa pertenece realmente al core de WordPress.
 * Solo wp-admin/, wp-includes/ y los ficheros sueltos de la raíz forman parte
 * del ZIP oficial. wp-content/ (temas y plugins) queda fuera: no se puede
 * verificar contra los checksums del core ni restaurar desde él.
 */
function stsuite_is_core_path($rel) {
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
function stsuite_resolve_site_path($rel) {
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
function stsuite_relative_site_path($abs) {
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
function stsuite_protect_dir($dir) {
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
function stsuite_plugin_dir_in_uploads($name, $subdir = '') {
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir'])) return false;

    $base = trailingslashit($upload['basedir']) . $name . '/';
    if (!wp_mkdir_p($base)) return false;
    stsuite_protect_dir($base);

    $dir = $base . ($subdir !== '' ? trailingslashit($subdir) : '');
    if ($subdir !== '' && !wp_mkdir_p($dir)) return false;

    // Ruta relativa a la raíz del sitio, derivada de la real (soporta uploads
    // personalizados y multisitio, donde basedir incluye /sites/N/).
    $root = wp_normalize_path(trailingslashit(ABSPATH));
    $rel  = str_replace($root, '', wp_normalize_path(trailingslashit($dir)));

    return ['dir' => trailingslashit($dir), 'rel' => $rel];
}



/**
 * Borra un archivo y devuelve si ha desaparecido.
 *
 * wp_delete_file() no devuelve valor, de ahí este envoltorio para los sitios
 * donde hay que informar del resultado al usuario.
 */
function stsuite_delete_file($path) {
    wp_delete_file($path);
    clearstatcache(true, $path);
    return !file_exists($path);
}

/**
 * Inicializa WP_Filesystem y lo devuelve, o null si no está disponible.
 */
function stsuite_filesystem() {
    global $wp_filesystem;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (empty($wp_filesystem)) {
        WP_Filesystem();
    }
    return ($wp_filesystem instanceof WP_Filesystem_Base) ? $wp_filesystem : null;
}

/**
 * Borra un directorio y su contenido usando la API de WordPress.
 *
 * Si WP_Filesystem no puede inicializarse (hosting que pide credenciales FTP),
 * se recurre a las funciones nativas para no dejar la operación a medias.
 */
function stsuite_delete_dir($dir) {
    $fs = stsuite_filesystem();
    if ($fs) {
        return (bool) $fs->delete($dir, true);
    }

    // Reserva: WP_Filesystem no disponible.
    if (!is_dir($dir)) return false;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Reserva cuando WP_Filesystem no está disponible.
            @rmdir($item->getPathname());
        } else {
            wp_delete_file($item->getPathname());
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Reserva cuando WP_Filesystem no está disponible.
    return @rmdir($dir);
}

/**
 * Eleva los límites de ejecución/memoria en operaciones largas (análisis de
 * miles de ficheros, restauración masiva) para no morir a mitad del proceso.
 */
function stsuite_raise_limits() {
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    if (function_exists('set_time_limit')) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- El análisis recorre miles de archivos; sin esto el proceso muere a mitad.
        @set_time_limit(300);
    }
}

// =============================
// Menús del admin
// =============================
add_action('admin_menu', function () {
    add_menu_page(
        'Sysadmin Total Suite',
        'Sysadmin Total Suite',
        'manage_options',
        'sysadmin-total-suite',
        'stsuite_profiler_dashboard',
        'dashicons-shield',
        // Posición baja: el menú no debe competir con los elementos del core.
        80
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('Integrity', 'sysadmin-total-suite'),
        esc_html__('Integrity', 'sysadmin-total-suite'),
        'manage_options',
        'sysadmin-total-suite',
        'stsuite_profiler_dashboard'
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('Profiling', 'sysadmin-total-suite'),
        esc_html__('Profiling', 'sysadmin-total-suite'),
        'manage_options',
        'sysadmin-total-suite-profiling',
        'stsuite_profiler_profiling_page'
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('Check WP users', 'sysadmin-total-suite'),
        esc_html__('Check WP users', 'sysadmin-total-suite'),
        'list_users',
        'sysadmin-total-suite-users',
        'stsuite_profiler_users_page'
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('WPO', 'sysadmin-total-suite'),
        esc_html__('WPO', 'sysadmin-total-suite'),
        'manage_options',
        'sysadmin-total-suite-wpo',
        'stsuite_profiler_wpo_page'
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('AI bot blocking', 'sysadmin-total-suite'),
        esc_html__('AI bot blocking', 'sysadmin-total-suite'),
        'manage_options',
        'sysadmin-total-suite-aibots',
        'stsuite_profiler_aibots_page'
    );
});

// =============================
// Assets
// =============================
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'sysadmin-total-suite') === false) return;

    wp_enqueue_style('stsuite-admin-css', STSUITE_PLUGIN_URL . 'admin/assets/admin.css', [], STSUITE_VERSION);
    wp_enqueue_script('stsuite-admin-js', STSUITE_PLUGIN_URL . 'admin/assets/admin.js', ['jquery'], STSUITE_VERSION, true);

    // Chart.js y la lógica de la pantalla de Profiling: solo se cargan ahí.
    // Chart.js va empaquetada en el plugin (no se permiten CDN externos).
    if (strpos($hook, 'sysadmin-total-suite-profiling') !== false) {
        wp_enqueue_script('stsuite-chartjs', STSUITE_PLUGIN_URL . 'admin/assets/chart.min.js', [], '4.5.1', true);
        wp_enqueue_script('stsuite-profiler-js', STSUITE_PLUGIN_URL . 'admin/assets/profiler.js', ['stsuite-chartjs'], STSUITE_VERSION, true);
    }

    wp_localize_script('stsuite-admin-js', 'STSUITE_AJAX', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('stsuite_diff_nonce'),
        'i18n'     => [
            'timeout'          => __('The operation timed out.', 'sysadmin-total-suite'),
            'commError'        => __('Communication error with the server.', 'sysadmin-total-suite'),
            /* translators: %s: error message. */
            'errorPrefix'      => __('Error: %s', 'sysadmin-total-suite'),
            'loadingDiff'      => __('Loading diff...', 'sysadmin-total-suite'),
            'noWpsAjax'        => __('STSUITE_AJAX is not defined. Make sure wp_localize_script() ran.', 'sysadmin-total-suite'),
            'deleting'         => __('Deleting...', 'sysadmin-total-suite'),
            'delete'           => __('Delete', 'sysadmin-total-suite'),
            // Transients
            'confirmCleanTransients'=> __("Clean up expired transients?\n\nThis is safe: they are expired temporary data and WordPress regenerates them when needed.", 'sysadmin-total-suite'),
            'cleaning'         => __('Cleaning...', 'sysadmin-total-suite'),
            /* translators: %d: number of transients removed. */
            'transientsRemoved'=> __('Expired transients removed: %s', 'sysadmin-total-suite'),
            'cleanTransients'  => __('Clean up expired transients', 'sysadmin-total-suite'),
            'cleanError'       => __('Could not clean up', 'sysadmin-total-suite'),
            // Cron
            /* translators: %s: cron hook name. */
            'confirmCleanCronHook'=> __("Remove the cron tasks for this hook?\n\n%s\n\nIt appears orphaned (no code attached right now), but a plugin may register it only on the front end. A copy of the schedule is saved beforehand so it can be restored manually.", 'sysadmin-total-suite'),
            'confirmCleanCronAll'=> __("Remove ALL cron tasks detected as orphaned?\n\nSome may belong to plugins that register their hook only on the front end, so review the list first. A copy of the schedule is saved beforehand so it can be restored manually.", 'sysadmin-total-suite'),
            /* translators: %s: value. */
            'cronRemoved'      => __('Orphan cron tasks removed: %s', 'sysadmin-total-suite'),
            'hooksCleaned'     => __('Cleaned hooks:', 'sysadmin-total-suite'),
            'cleanAllCron'     => __('Clean up all orphan cron tasks', 'sysadmin-total-suite'),
            // Delete user
            /* translators: %s: user login name. */
            'confirmDeleteUser1'=> __("Are you sure you want to DELETE the user?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the user is permanently removed and cannot be undone.", 'sysadmin-total-suite'),
            /* translators: %s: user login name. */
            'confirmDeleteUser2'=> __("Final confirmation.\n\nThe user \"%s\" and the content they authored will be removed.\n\nContinue with permanent deletion?", 'sysadmin-total-suite'),
            'deleteUserError'  => __('Could not delete the user', 'sysadmin-total-suite'),
        ],
    ]);
});

// =============================
// Páginas
// =============================
function stsuite_profiler_dashboard() {
    include STSUITE_PLUGIN_DIR . 'admin/dashboard.php';
}

function stsuite_profiler_profiling_page() {
    include STSUITE_PLUGIN_DIR . 'admin/profiler.php';
}

function stsuite_profiler_users_page() {
    include STSUITE_PLUGIN_DIR . 'admin/users.php';
}

function stsuite_profiler_wpo_page() {
    include STSUITE_PLUGIN_DIR . 'admin/wpo.php';
}

function stsuite_profiler_aibots_page() {
    include STSUITE_PLUGIN_DIR . 'admin/aibots.php';
}

// =============================
// Acción de análisis (integridad)
// =============================
add_action('admin_post_stsuite_run_analysis', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_run_analysis_nonce');
    stsuite_raise_limits();

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
    $excluded_files = (array) apply_filters('stsuite_integrity_excluded_files', $excluded_files);

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
                if (!stsuite_is_core_path($file) || in_array($file, $excluded_files, true)) {
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
        'checksum' => $checksum_result,
        'errors' => $errors,
        'modified_files' => array_values(array_unique($modified_files)),
        'version' => $version,
        'locale' => $locale,
        'checked_at' => time(),
    ];
    set_transient('stsuite_last_analysis', $analysis_data, HOUR_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=sysadmin-total-suite'));
    exit;
});

// =============================
// Acción para purgar la caché
// =============================
add_action('admin_post_stsuite_purge_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_purge_cache_nonce', 'stsuite_purge_cache');

    // Borrar el transitorio de análisis
    delete_transient('stsuite_last_analysis');

    // Borrar la caché de archivos ZIP
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/sysadmin-total-suite-cache/';
    if (is_dir($cache_dir)) {
        stsuite_delete_dir($cache_dir);
    }

    wp_safe_redirect(admin_url('admin.php?page=sysadmin-total-suite&cache_purged=1'));
    exit;
});


// =============================
// Reset histórico profiling
// =============================
add_action('admin_post_stsuite_reset_profiling', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_reset_profiling_nonce', 'stsuite_reset_profiling');

    delete_option('stsuite_profiling_history');

    wp_safe_redirect(admin_url('admin.php?page=sysadmin-total-suite-profiling&reset_done=1'));
    exit;
});

<?php
/**
 * Plugin Name: Site Integrity & Profiler
 * Description: Core integrity checks, load-time profiling, user review, performance (WPO) diagnostics and AI bot blocking in a single admin panel.
 * Version: 4.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Ricardo Morales
 * Author URI: https://github.com/Ricardo-AlterTable
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-integrity-profiler
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('WPS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Cargar traducciones. Se mantiene la llamada explícita para que la traducción
// incluida en /languages funcione también al instalar el plugin desde GitHub,
// donde no existe la carga automática del repositorio de WordPress.org.
add_action('init', function () {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Necesario para las traducciones incluidas en el paquete.
    load_plugin_textdomain('site-integrity-profiler', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

require_once WPS_PLUGIN_DIR . 'includes/diff.php';
require_once WPS_PLUGIN_DIR . 'includes/profiler.php';
require_once WPS_PLUGIN_DIR . 'includes/users.php';
require_once WPS_PLUGIN_DIR . 'includes/wpo.php';
require_once WPS_PLUGIN_DIR . 'includes/aibots.php';
require_once WPS_PLUGIN_DIR . 'includes/backups.php';

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
    return wps_plugin_dir_in_uploads('site-integrity-profiler-backups', $subdir);
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
 * Borra un archivo y devuelve si ha desaparecido.
 *
 * wp_delete_file() no devuelve valor, de ahí este envoltorio para los sitios
 * donde hay que informar del resultado al usuario.
 */
function wps_delete_file($path) {
    wp_delete_file($path);
    clearstatcache(true, $path);
    return !file_exists($path);
}

/**
 * Inicializa WP_Filesystem y lo devuelve, o null si no está disponible.
 */
function wps_filesystem() {
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
function wps_delete_dir($dir) {
    $fs = wps_filesystem();
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
function wps_raise_limits() {
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
        'Site Integrity & Profiler',
        'Site Integrity & Profiler',
        'manage_options',
        'site-integrity-profiler',
        'wps_profiler_dashboard',
        'dashicons-shield',
        3
    );

    add_submenu_page(
        'site-integrity-profiler',
        esc_html__('Integrity', 'site-integrity-profiler'),
        esc_html__('Integrity', 'site-integrity-profiler'),
        'manage_options',
        'site-integrity-profiler',
        'wps_profiler_dashboard'
    );

    add_submenu_page(
        'site-integrity-profiler',
        esc_html__('Profiling', 'site-integrity-profiler'),
        esc_html__('Profiling', 'site-integrity-profiler'),
        'manage_options',
        'site-integrity-profiler-profiling',
        'wps_profiler_profiling_page'
    );

    add_submenu_page(
        'site-integrity-profiler',
        esc_html__('Check WP users', 'site-integrity-profiler'),
        esc_html__('Check WP users', 'site-integrity-profiler'),
        'list_users',
        'site-integrity-profiler-users',
        'wps_profiler_users_page'
    );

    add_submenu_page(
        'site-integrity-profiler',
        esc_html__('WPO', 'site-integrity-profiler'),
        esc_html__('WPO', 'site-integrity-profiler'),
        'manage_options',
        'site-integrity-profiler-wpo',
        'wps_profiler_wpo_page'
    );

    add_submenu_page(
        'site-integrity-profiler',
        esc_html__('AI bot blocking', 'site-integrity-profiler'),
        esc_html__('AI bot blocking', 'site-integrity-profiler'),
        'manage_options',
        'site-integrity-profiler-aibots',
        'wps_profiler_aibots_page'
    );
});

// =============================
// Assets
// =============================
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'site-integrity-profiler') === false) return;

    wp_enqueue_style('wps-admin-css', WPS_PLUGIN_URL . 'admin/assets/admin.css', [], '4.0');
    wp_enqueue_script('wps-admin-js', WPS_PLUGIN_URL . 'admin/assets/admin.js', ['jquery'], '4.0', true);

    // Chart.js (empaquetada localmente; WordPress.org no permite CDN externos).
    if (isset($_GET['page']) && $_GET['page'] === 'site-integrity-profiler-profiling') {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
        wp_enqueue_script('chartjs', WPS_PLUGIN_URL . 'admin/assets/chart.min.js', [], '4.4.0', true);
    }

    wp_localize_script('wps-admin-js', 'WPS_AJAX', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wps_diff_nonce'),
        'i18n'     => [
            'timeout'          => __('The operation timed out.', 'site-integrity-profiler'),
            'commError'        => __('Communication error with the server.', 'site-integrity-profiler'),
            /* translators: %s: error message. */
            'errorPrefix'      => __('Error: %s', 'site-integrity-profiler'),
            'loadingDiff'      => __('Loading diff...', 'site-integrity-profiler'),
            'noWpsAjax'        => __('WPS_AJAX is not defined. Make sure wp_localize_script() ran.', 'site-integrity-profiler'),
            // Restore file
            'confirmRestore'   => __('Are you sure you want to restore this file from the original core?', 'site-integrity-profiler'),
            'askBackupFile'    => __("Do you want to keep a backup of the current file before overwriting it?\n\nOK = yes, make a backup\nCancel = restore without a backup", 'site-integrity-profiler'),
            'restoring'        => __('Restoring...', 'site-integrity-profiler'),
            'restore'          => __('Restore', 'site-integrity-profiler'),
            /* translators: %s: file path. */
            'fileRestored'     => __('File restored: %s', 'site-integrity-profiler'),
            'backupSavedIn'    => __('Backup saved in:', 'site-integrity-profiler'),
            'noBackupMade'     => __('(No backup was created)', 'site-integrity-profiler'),
            'restoreError'     => __('Could not restore', 'site-integrity-profiler'),
            // Restore all
            'confirmRestoreAll'=> __('Are you sure you want to restore ALL modified/missing files?', 'site-integrity-profiler'),
            'askBackupAll'     => __("Do you want to keep a backup of the current files before overwriting them?\n\nOK = yes, make a backup\nCancel = restore without a backup", 'site-integrity-profiler'),
            'noFilesToRestore' => __('There are no files to restore.', 'site-integrity-profiler'),
            'restoreAll'       => __('Restore all', 'site-integrity-profiler'),
            'opDone'           => __('Operation completed.', 'site-integrity-profiler'),
            /* translators: %d: number of files. */
            'filesRestoredN'   => __('Restored files (%d):', 'site-integrity-profiler'),
            /* translators: %d: number of files. */
            'filesWithErrorsN' => __('Files with errors (%d):', 'site-integrity-profiler'),
            'restoreAllError'  => __('Could not restore all', 'site-integrity-profiler'),
            // Delete extra
            /* translators: %s: file path. */
            'confirmDeleteExtra'=> __("Delete this file?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the file is permanently removed and cannot be undone.", 'site-integrity-profiler'),
            'confirmDeleteAllExtra'=> __("Delete ALL files not recognized by WordPress?\n\n⚠ This action is IRREVERSIBLE: the files are permanently removed and cannot be undone.", 'site-integrity-profiler'),
            'deleting'         => __('Deleting...', 'site-integrity-profiler'),
            'delete'           => __('Delete', 'site-integrity-profiler'),
            'deleteAllExtra'   => __('Delete all extra files', 'site-integrity-profiler'),
            /* translators: %d: number of files. */
            'deletedN'         => __('Deleted (%d):', 'site-integrity-profiler'),
            'deleteError'      => __('Could not delete', 'site-integrity-profiler'),
            // Transients
            'confirmCleanTransients'=> __("Clean up expired transients?\n\nThis is safe: they are expired temporary data and WordPress regenerates them when needed.", 'site-integrity-profiler'),
            'cleaning'         => __('Cleaning...', 'site-integrity-profiler'),
            /* translators: %d: number of transients removed. */
            'transientsRemoved'=> __('Expired transients removed: %s', 'site-integrity-profiler'),
            'cleanTransients'  => __('Clean up expired transients', 'site-integrity-profiler'),
            'cleanError'       => __('Could not clean up', 'site-integrity-profiler'),
            // Cron
            /* translators: %s: cron hook name. */
            'confirmCleanCronHook'=> __("Remove the cron tasks for this hook?\n\n%s\n\nIt appears orphaned (no code attached right now), but a plugin may register it only on the front end. A copy of the schedule is saved beforehand so it can be restored manually.", 'site-integrity-profiler'),
            'confirmCleanCronAll'=> __("Remove ALL cron tasks detected as orphaned?\n\nSome may belong to plugins that register their hook only on the front end, so review the list first. A copy of the schedule is saved beforehand so it can be restored manually.", 'site-integrity-profiler'),
            /* translators: %s: value. */
            'cronRemoved'      => __('Orphan cron tasks removed: %s', 'site-integrity-profiler'),
            'hooksCleaned'     => __('Cleaned hooks:', 'site-integrity-profiler'),
            'cleanAllCron'     => __('Clean up all orphan cron tasks', 'site-integrity-profiler'),
            // Delete user
            /* translators: %s: user login name. */
            'confirmDeleteUser1'=> __("Are you sure you want to DELETE the user?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the user is permanently removed and cannot be undone.", 'site-integrity-profiler'),
            /* translators: %s: user login name. */
            'confirmDeleteUser2'=> __("Final confirmation.\n\nThe user \"%s\" and the content they authored will be removed.\n\nContinue with permanent deletion?", 'site-integrity-profiler'),
            'deleteUserError'  => __('Could not delete the user', 'site-integrity-profiler'),
            // Backups
            /* translators: %s: file path. */
            'confirmRestoreBackup'   => __("Restore this file from the backup?\n\n%s\n\nThe file currently on the site will be overwritten.", 'site-integrity-profiler'),
            /* translators: %s: value. */
            'confirmDeleteBackupFile'=> __("Delete this backup copy?\n\n%s\n\n⚠ This action is IRREVERSIBLE: the copy is permanently removed and cannot be undone.", 'site-integrity-profiler'),
            'confirmDeleteBackupBatch'=> __("Delete this whole backup?\n\n⚠ This action is IRREVERSIBLE: every copy it contains is permanently removed and cannot be undone.", 'site-integrity-profiler'),
            'confirmDeleteAllBackups'=> __("Delete ALL backups?\n\n⚠ This action is IRREVERSIBLE: every saved copy is permanently removed and cannot be undone.", 'site-integrity-profiler'),
            'deleteBackupBatch'      => __('Delete this backup', 'site-integrity-profiler'),
            'deleteAllBackups'       => __('Delete all backups', 'site-integrity-profiler'),
            /* translators: %s: number of backups deleted. */
            'backupsDeleted'         => __('Backups deleted: %s', 'site-integrity-profiler'),
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
        wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
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
        'checksum' => $checksum_result,
        'errors' => $errors,
        'modified_files' => array_values(array_unique($modified_files)),
        'version' => $version,
        'locale' => $locale,
        'checked_at' => time(),
    ];
    set_transient('wps_last_analysis', $analysis_data, HOUR_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=site-integrity-profiler'));
    exit;
});

// =============================
// Acción para purgar la caché
// =============================
add_action('admin_post_wps_purge_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
    }
    check_admin_referer('wps_purge_cache_nonce', 'wps_purge_cache');

    // Borrar el transitorio de análisis
    delete_transient('wps_last_analysis');

    // Borrar la caché de archivos ZIP
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/site-integrity-profiler-cache/';
    if (is_dir($cache_dir)) {
        wps_delete_dir($cache_dir);
    }

    wp_safe_redirect(admin_url('admin.php?page=site-integrity-profiler&cache_purged=1'));
    exit;
});


// =============================
// Reset histórico profiling
// =============================
add_action('admin_post_wps_reset_profiling', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
    }
    check_admin_referer('wps_reset_profiling_nonce', 'wps_reset_profiling');

    delete_option('wps_profiling_history');

    wp_safe_redirect(admin_url('admin.php?page=site-integrity-profiler-profiling&reset_done=1'));
    exit;
});

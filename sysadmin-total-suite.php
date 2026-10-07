<?php
/**
 * Plugin Name: Sysadmin Total Suite
 * Description: Core integrity, vulnerability audit of core, plugins and themes, unused media cleanup, disk usage, profiling, user review, performance (WPO) diagnostics and AI bot blocking in a single admin panel.
 * Version: 5.4.1
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

define('STSUITE_VERSION', '5.4.1');
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
require_once STSUITE_PLUGIN_DIR . 'includes/audit.php';
require_once STSUITE_PLUGIN_DIR . 'includes/disk.php';
require_once STSUITE_PLUGIN_DIR . 'includes/media.php';
require_once STSUITE_PLUGIN_DIR . 'includes/media-quarantine.php';

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
 * verificar contra los checksums del core ni comparar con el ZIP oficial.
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
 * segura. No exige que el archivo exista (realpath() devolvería false), así
 * que quien la llame debe comprobarlo si lo necesita.
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
 * Nota: el .htaccess solo lo aplica Apache por completo. nginx no lo lee y
 * LiteSpeed sirve los archivos estáticos existentes sin aplicarlo (comprobado
 * en producción: devuelve 403 para lo inexistente y 200 para un archivo real).
 * Por eso lo que deba ser privado (la cuarentena) usa además una ruta con una
 * clave aleatoria. La caché del diff solo guarda el ZIP oficial de WordPress,
 * que es público, así que no expone nada.
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
 * @param string $name Nombre del directorio.
 * @return array{dir:string}|false
 */
function stsuite_plugin_dir_in_uploads($name) {
    $upload = wp_upload_dir();
    if (!empty($upload['error']) || empty($upload['basedir'])) return false;

    $dir = trailingslashit($upload['basedir']) . $name . '/';
    if (!wp_mkdir_p($dir)) return false;
    stsuite_protect_dir($dir);

    return ['dir' => $dir];
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
 * Eleva los límites de ejecución/memoria en operaciones largas (el análisis
 * recorre miles de ficheros) para no morir a mitad del proceso.
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
        esc_html__('Audit', 'sysadmin-total-suite'),
        esc_html__('Audit', 'sysadmin-total-suite'),
        stsuite_audit_capability(),
        'sysadmin-total-suite-audit',
        'stsuite_profiler_audit_page'
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
        esc_html__('Disk usage', 'sysadmin-total-suite'),
        esc_html__('Disk usage', 'sysadmin-total-suite'),
        stsuite_disk_capability(),
        'sysadmin-total-suite-disk',
        'stsuite_profiler_disk_page'
    );

    add_submenu_page(
        'sysadmin-total-suite',
        esc_html__('Unused media', 'sysadmin-total-suite'),
        esc_html__('Unused media', 'sysadmin-total-suite'),
        stsuite_media_capability(),
        'sysadmin-total-suite-media',
        'stsuite_profiler_media_page'
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

/**
 * Versión de un asset para la URL (?ver=): la del plugin más la fecha de
 * modificación del archivo. Así un cambio en el archivo invalida la caché del
 * navegador y la de servidores como LiteSpeed aunque no se suba la versión.
 */
function stsuite_asset_ver(string $rel): string {
    $mtime = @filemtime(STSUITE_PLUGIN_DIR . $rel); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Si no existe, se usa solo la versión del plugin.
    return $mtime ? STSUITE_VERSION . '.' . $mtime : STSUITE_VERSION;
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'sysadmin-total-suite') === false) return;

    wp_enqueue_style('stsuite-admin-css', STSUITE_PLUGIN_URL . 'admin/assets/admin.css', [], stsuite_asset_ver('admin/assets/admin.css'));
    wp_enqueue_script('stsuite-admin-js', STSUITE_PLUGIN_URL . 'admin/assets/admin.js', ['jquery'], stsuite_asset_ver('admin/assets/admin.js'), true);

    // Chart.js y la lógica de la pantalla de Profiling: solo se cargan ahí.
    // Chart.js va empaquetada en el plugin (no se permiten CDN externos).
    if (strpos($hook, 'sysadmin-total-suite-profiling') !== false) {
        wp_enqueue_script('stsuite-chartjs', STSUITE_PLUGIN_URL . 'admin/assets/chart.min.js', [], '4.5.1', true);
        wp_enqueue_script('stsuite-profiler-js', STSUITE_PLUGIN_URL . 'admin/assets/profiler.js', ['stsuite-chartjs'], stsuite_asset_ver('admin/assets/profiler.js'), true);
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
            // Plugin audit
            'auditStarting'    => __('Preparing the scan...', 'sysadmin-total-suite'),
            /* translators: 1: items checked so far, 2: total items (core, themes and plugins). */
            'auditProgress'    => __('Checking: %1$s of %2$s...', 'sysadmin-total-suite'),
            'auditDone'        => __('Scan complete. Reloading...', 'sysadmin-total-suite'),
            'auditScan'        => __('Scan now', 'sysadmin-total-suite'),
            // Disk usage
            'diskStarting'     => __('Listing folders...', 'sysadmin-total-suite'),
            /* translators: 1: folders measured so far, 2: total folders. */
            'diskProgress'     => __('Measuring folders: %1$s of %2$s...', 'sysadmin-total-suite'),
            'diskDone'         => __('Measurement complete. Reloading...', 'sysadmin-total-suite'),
            'diskScan'         => __('Measure now', 'sysadmin-total-suite'),
            // Unused media
            'mediaStarting'    => __('Preparing the scan...', 'sysadmin-total-suite'),
            /* translators: 1: current step, 2: total steps. */
            'mediaProgress'    => __('Looking for references: step %1$s of %2$s...', 'sysadmin-total-suite'),
            'mediaDone'        => __('Scan complete. Reloading...', 'sysadmin-total-suite'),
            'mediaScan'        => __('Scan media', 'sysadmin-total-suite'),
            'selectSomething'  => __('Select at least one item first.', 'sysadmin-total-suite'),
            /* translators: %s: number of selected media items. */
            'confirmQuarantine'=> __("Move %s media item(s) to quarantine?\n\nThey will be hidden from the media library and their files moved to a protected folder. Any page that still uses them will show a broken image until you restore them. Nothing is deleted.", 'sysadmin-total-suite'),
            'purgeQuarantine1' => __("Empty the quarantine?\n\nEvery media item in it and all its files will be PERMANENTLY deleted.", 'sysadmin-total-suite'),
            'purgeQuarantine2' => __("Final confirmation.\n\nThis cannot be undone. Continue with permanent deletion?", 'sysadmin-total-suite'),
        ],
    ]);
});

// =============================
// Páginas
// =============================
function stsuite_profiler_dashboard() {
    include STSUITE_PLUGIN_DIR . 'admin/dashboard.php';
}

function stsuite_profiler_audit_page() {
    include STSUITE_PLUGIN_DIR . 'admin/audit.php';
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

function stsuite_profiler_disk_page() {
    include STSUITE_PLUGIN_DIR . 'admin/disk.php';
}

function stsuite_profiler_media_page() {
    include STSUITE_PLUGIN_DIR . 'admin/media.php';
}

function stsuite_profiler_aibots_page() {
    include STSUITE_PLUGIN_DIR . 'admin/aibots.php';
}


/**
 * Descarga los checksums oficiales del core para una versión e idioma.
 *
 * @return array<string,string>|false Mapa ruta => md5, o false si falla.
 */
function stsuite_fetch_checksums($version, $locale) {
    $url = 'https://api.wordpress.org/core/checksums/1.0/?version=' . rawurlencode($version)
         . '&locale=' . rawurlencode($locale);

    $response = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    return (isset($data['checksums']) && is_array($data['checksums'])) ? $data['checksums'] : false;
}

/**
 * Checksums del paquete internacional (en_US), descargados una sola vez y solo
 * cuando hacen falta.
 *
 * Un sitio con idioma es_ES puede estar ejecutando el paquete internacional: en
 * ese caso algunos ficheros (p. ej. wp-includes/version.php, que en los paquetes
 * traducidos incluye $wp_local_package) no coinciden con los checksums del
 * idioma sin que haya nada modificado. Comparar también con el paquete
 * internacional evita ese falso positivo.
 *
 * @return array<string,string>|false
 */
function stsuite_fallback_checksums($version, $locale) {
    static $cache = null;

    if ($cache === null) {
        $cache = (strpos($locale, 'en_US') === 0)
            ? []                                          // ya era el internacional
            : stsuite_fetch_checksums($version, 'en_US');
        if ($cache === false) $cache = [];
    }

    return $cache;
}

/**
 * md5 de wp-includes/version.php sin la línea que añaden los paquetes
 * traducidos. Un paquete es_ES es idéntico al internacional salvo ese archivo,
 * que termina con una línea en blanco y "$wp_local_package = 'es_ES';"
 * (comprobado con los ZIP oficiales). Así se puede verificar contra los
 * checksums internacionales cuando WordPress.org no publica los del idioma.
 *
 * @return string|false
 */
function stsuite_md5_without_local_package($path) {
    $content = @file_get_contents($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura de un archivo local del core; si falla se trata como no coincidente.
    if ($content === false) return false;
    $normalized = preg_replace('/\R\$wp_local_package\s*=\s*[\'"][A-Za-z_@-]+[\'"];\R?/', '', $content, 1, $count);
    return $count ? md5((string) $normalized) : false;
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

    $version = get_bloginfo('version');
    $locale  = get_locale();

    $checksum_result = 'core_check_failed';
    $errors = [];
    $modified_files = [];

    // Checksums del idioma del sitio. WordPress.org no siempre los publica
    // (p. ej. es_ES de 7.0.6, 7.1.1, 7.1.2 y 7.1.3): entonces se usan los del
    // paquete internacional, que es idéntico salvo wp-includes/version.php.
    // Antes, sin checksums del idioma, el análisis fallaba y el informe decía
    // "sin problemas".
    $reference = 'locale';
    $checksums = stsuite_fetch_checksums($version, $locale);
    if (!is_array($checksums) && strpos($locale, 'en_US') !== 0) {
        $international = stsuite_fallback_checksums($version, $locale);
        if (!empty($international)) {
            $checksums = $international;
            $reference = 'international';
        }
    }

    if (is_array($checksums)) {

            // Verificar solo los ficheros que pertenecen de verdad al core
            // (wp-admin/, wp-includes/ y ficheros sueltos de la raíz). Los archivos
            // de wp-content/ no están en el ZIP del core, así que se ignoran.
            foreach ($checksums as $file => $md5) {
                if (!stsuite_is_core_path($file) || in_array($file, $excluded_files, true)) {
                    continue;
                }
                $path = ABSPATH . $file;
                if (file_exists($path)) {
                    $actual = @md5_file($path);
                    // Paquete traducido comparado con los checksums internacionales:
                    // version.php solo difiere en $wp_local_package.
                    if ($actual !== $md5 && $file === 'wp-includes/version.php' && stsuite_md5_without_local_package($path) === $md5) {
                        $actual = $md5;
                    }
                    if ($actual !== $md5) {
                        // Puede tratarse del paquete internacional en un sitio
                        // traducido: solo se marca si tampoco coincide con él.
                        $fallback = stsuite_fallback_checksums($version, $locale);
                        if (!isset($fallback[$file]) || $fallback[$file] !== $actual) {
                            $errors[] = "Modified: $file";
                            $modified_files[] = $file;
                        }
                    }
                } else {
                    // Un fichero que solo existe en el paquete del idioma no
                    // falta de verdad si el sitio usa el paquete internacional.
                    $fallback = stsuite_fallback_checksums($version, $locale);
                    if (empty($fallback) || isset($fallback[$file])) {
                        $errors[] = "Missing: $file";
                        $modified_files[] = $file;
                    }
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
                        // Puede pertenecer al paquete internacional aunque no
                        // esté en el del idioma: entonces no es un intruso.
                        $fallback = stsuite_fallback_checksums($version, $locale);
                        if (!isset($fallback[$rel])) {
                            $errors[] = "Extra: $rel";
                        }
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
                    $fallback = stsuite_fallback_checksums($version, $locale);
                    if (!isset($fallback[$rel])) {
                        $errors[] = "Extra: $rel";
                    }
                }
            }

            $checksum_result = empty($errors)
                ? 'core_ok'
                : 'issues_found';
    }

    $analysis_data = [
        'checksum' => $checksum_result,
        'errors' => $errors,
        'modified_files' => array_values(array_unique($modified_files)),
        'version' => $version,
        'locale' => $locale,
        'reference' => $reference, // 'locale' | 'international'
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

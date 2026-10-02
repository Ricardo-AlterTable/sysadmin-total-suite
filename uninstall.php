<?php
/**
 * Desinstalación: elimina los datos que crea el plugin.
 *
 * Solo se ejecuta cuando el usuario borra el plugin desde WordPress.
 * NO se eliminan las copias de seguridad de archivos del core que pudieran haber
 * creado versiones anteriores a la 5.0: son datos que el usuario pidió conservar
 * explícitamente y su borrado sería irreversible.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$stsuite_options = [
    'stsuite_profiling_history',
    'stsuite_aibots_settings',
    'stsuite_cron_backup',
    'stsuite_migrated_prefix',
    'stsuite_audit_results',
    'stsuite_audit_settings',
    'stsuite_disk_results',
    'stsuite_media_scan',
    'stsuite_quarantine_key',
];

/**
 * Devuelve a su sitio los medios que sigan en cuarentena: desinstalar el
 * plugin no debe borrar ni dejar ocultos datos del usuario. Si un archivo no se
 * puede devolver (ya existe otro con el mismo nombre), ese adjunto conserva su
 * marca y sus archivos siguen en la carpeta de cuarentena, que no se borra.
 */
function stsuite_uninstall_restore_quarantine() {
    global $wpdb;
    $upload = wp_upload_dir(null, false);
    if (!empty($upload['error']) || empty($upload['basedir'])) {
        return;
    }
    $base = untrailingslashit(wp_normalize_path($upload['basedir']));
    // Carpetas posibles: la del nombre antiguo y la de la clave aleatoria del sitio.
    $qdirs = [$base . '/sysadmin-total-suite-quarantine'];
    $key   = (string) get_option('stsuite_quarantine_key', '');
    if (preg_match('/^[a-z0-9]{20}$/', $key)) {
        $qdirs[] = $base . '/sysadmin-total-suite-quarantine-' . $key;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Desinstalación: listado único de adjuntos en cuarentena.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_stsuite_quarantine'));
    $pending = false;
    foreach (array_map('intval', $ids) as $id) {
        $data = get_post_meta($id, '_stsuite_quarantine', true);
        $left = false;
        $qname = (is_array($data) && preg_match('/^sysadmin-total-suite-quarantine(-[a-z0-9]{20})?$/', (string) ($data['qdir'] ?? ''))) ? $data['qdir'] : 'sysadmin-total-suite-quarantine';
        if (is_array($data) && preg_match('#^\d{4}/\d{2}$#', (string) ($data['dir'] ?? ''))) {
            foreach ((array) $data['files'] as $name) {
                $name = basename((string) $name);
                $src  = $base . '/' . $qname . '/' . $data['dir'] . '/' . $name;
                $dst  = $base . '/' . $data['dir'] . '/' . $name;
                if (!is_file($src)) {
                    continue;
                }
                // Desinstalación sin WP_Filesystem garantizado; no sobrescribe (se comprueba antes) y el resultado se comprueba.
                if (file_exists($dst) || !wp_mkdir_p(dirname($dst)) || !@rename($src, $dst)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
                    $left = true;
                }
            }
        }
        if ($left) {
            $pending = true;
        } else {
            delete_post_meta($id, '_stsuite_quarantine');
        }
    }

    // Las carpetas solo se eliminan si ya no guardan ningún medio.
    if ($pending) {
        return;
    }
    foreach ($qdirs as $qdir) {
        if (is_dir($qdir)) {
            stsuite_uninstall_remove_empty_dir($qdir);
        }
    }
}

/**
 * Elimina una carpeta propia del plugin si solo contiene sus ficheros de
 * protección (.htaccess / index.php) y subcarpetas vacías.
 */
function stsuite_uninstall_remove_empty_dir($qdir) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($qdir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isFile() && !in_array($item->getFilename(), ['.htaccess', 'index.php'], true)) {
            return; // queda algo inesperado: no se toca
        }
    }
    foreach ($items as $item) {
        if ($item->isDir()) {
            // Carpetas vacías del propio plugin; si alguna no lo está, se deja.
            @rmdir($item->getPathname()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
        } else {
            wp_delete_file($item->getPathname());
        }
    }
    // Carpeta propia del plugin, ya vacía; si no lo está, se deja.
    @rmdir($qdir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Borra las opciones y transitorios del sitio actual.
 */
function stsuite_uninstall_clean_site($options) {
    stsuite_uninstall_restore_quarantine();
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

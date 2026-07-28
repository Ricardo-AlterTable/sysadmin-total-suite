<?php
// includes/diff.php
if (!defined('ABSPATH')) exit;

global $wps_fetch_last_error;
$wps_fetch_last_error = '';

/**
 * Simple unified diff renderer: prefer wp_text_diff if available, fallback to a simple line diff.
 */
if (!function_exists('wps_unified_diff')) {
    function wps_unified_diff($old, $new, $context = 3) {
        $a = is_array($old) ? $old : explode("\n", str_replace("\r", '', $old));
        $b = is_array($new) ? $new : explode("\n", str_replace("\r", '', $new));

        $max = max(count($a), count($b));
        $lines = [];
        for ($i = 0; $i < $max; $i++) {
            $la = $a[$i] ?? null;
            $lb = $b[$i] ?? null;
            if ($la === $lb) {
                $lines[] = '  ' . ($la ?? '');
            } else {
                if ($la !== null) $lines[] = '- ' . $la;
                if ($lb !== null) $lines[] = '+ ' . $lb;
            }
        }
        return implode("\n", $lines);
    }
}

/**
 * Ajax: devolver diff (texto plano)
 */
add_action('wp_ajax_wps_show_diff', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_diff_nonce', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel) {
        wp_send_json_error(['message' => __('Invalid path', 'wp-profiler-security')], 400);
    }

    // Resolver ANTES de validar: así '..' ya está normalizado y wps_is_core_path()
    // se evalúa sobre la ruta real, no sobre la que envió el cliente.
    $absFile = wps_resolve_site_path($rel);
    if (!$absFile || !is_file($absFile)) {
        wp_send_json_error(['message' => sprintf(__('File not found: %s', 'wp-profiler-security'), $rel)], 404);
    }
    $rel = wps_relative_site_path($absFile);
    if (!$rel || !wps_is_core_path($rel)) {
        wp_send_json_error(['message' => __('Only WordPress core files can be compared.', 'wp-profiler-security')], 400);
    }

    $current = @file_get_contents($absFile);
    if ($current === false) {
        wp_send_json_error(['message' => sprintf(__('Could not read the current file: %s', 'wp-profiler-security'), $rel)], 500);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);

    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => __('Could not fetch the original file.', 'wp-profiler-security'), 'details' => $GLOBALS['wps_fetch_last_error'] ?? 'n/a'], 500);
    }

    $original = $fetch['body'];

    if (hash('sha256', $current) === hash('sha256', $original)) {
        wp_send_json_success(['path' => $rel, 'diff' => __('No differences found.', 'wp-profiler-security')]);
    }

    $diff = wps_unified_diff($original, $current, 3);
    wp_send_json_success(['path' => $rel, 'diff' => $diff]);
});

/**
 * Ajax: restaurar archivo individual (backup en carpeta raíz)
 */
add_action('wp_ajax_wps_restore_file', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_restore_file', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel) {
        wp_send_json_error(['message' => __('Invalid path', 'wp-profiler-security')], 400);
    }

    // Resolución segura que también admite archivos faltantes. Se resuelve ANTES
    // de validar para que '..' esté normalizado al comprobar que es del core.
    $absFile = wps_resolve_site_path($rel);
    if (!$absFile) {
        wp_send_json_error(['message' => sprintf(__('File outside the site: %s', 'wp-profiler-security'), $rel)], 403);
    }
    $rel = wps_relative_site_path($absFile);
    if (!$rel || !wps_is_core_path($rel)) {
        wp_send_json_error(['message' => __('Only WordPress core files can be restored.', 'wp-profiler-security')], 400);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);
    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => __('Could not fetch the original file.', 'wp-profiler-security'), 'details' => $GLOBALS['wps_fetch_last_error'] ?? 'n/a'], 500);
    }

    // === Copia de seguridad opcional (a petición del usuario) ===
    $do_backup  = isset($_POST['backup']) && $_POST['backup'] === '1';
    $backup_rel = null;

    if ($do_backup && is_file($absFile)) {
        $backup = wps_backup_dir();
        if (!$backup) {
            wp_send_json_error(['message' => __('Could not create the backup. Check permissions; the file was NOT restored.', 'wp-profiler-security')], 500);
        }
        $backup_rel = wps_backup_file($absFile, $rel, $backup);
        if (!$backup_rel) {
            // Si se pidió copia y no se puede crear, abortamos sin sobrescribir.
            wp_send_json_error(['message' => __('Could not create the backup. Check permissions; the file was NOT restored.', 'wp-profiler-security')], 500);
        }
    }

    // Restaurar (creando el directorio si el archivo estaba faltante).
    wp_mkdir_p(dirname($absFile));
    $ok = @file_put_contents($absFile, $fetch['body']);
    if ($ok === false) {
        wp_send_json_error(['message' => __('Could not write the local file. Check permissions.', 'wp-profiler-security')], 500);
    }

    wp_send_json_success([
        'message' => sprintf(__('File restored successfully: %s', 'wp-profiler-security'), $rel),
        'backup'  => $backup_rel, // ruta relativa a la raíz de WordPress, o null si no se pidió copia
    ]);
});

/**
 * Ajax: restaurar todos los archivos modificados
 */
add_action('wp_ajax_wps_restore_all_files', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_restore_all', 'nonce');
    wps_raise_limits();

    $files = isset($_POST['files']) && is_array($_POST['files']) ? array_map('sanitize_text_field', wp_unslash($_POST['files'])) : [];
    if (empty($files)) {
        wp_send_json_error(['message' => __('No files were specified to restore.', 'wp-profiler-security')], 400);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $restored = [];
    $errors = [];

    // Copia de seguridad opcional: una carpeta por lote, fuera de la raíz web.
    $do_backup = isset($_POST['backup']) && $_POST['backup'] === '1';
    $batch     = $do_backup ? wps_backup_dir() : null;
    if ($do_backup && !$batch) {
        wp_send_json_error(['message' => __('Could not create the backup. Check permissions; nothing was restored.', 'wp-profiler-security')], 500);
    }

    foreach ($files as $rel) {
        if (!$rel) {
            $errors[$rel] = __('Invalid path', 'wp-profiler-security');
            continue;
        }
        $absFile = wps_resolve_site_path($rel);
        if (!$absFile) {
            $errors[$rel] = __('File outside the site', 'wp-profiler-security');
            continue;
        }
        $rel = wps_relative_site_path($absFile);
        if (!$rel || !wps_is_core_path($rel)) {
            $errors[$rel] = __('Only WordPress core files can be restored.', 'wp-profiler-security');
            continue;
        }
        $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);
        if (!is_array($fetch) || empty($fetch['body'])) {
            $errors[$rel] = sprintf(__('Could not fetch the original: %s', 'wp-profiler-security'), $GLOBALS['wps_fetch_last_error'] ?? '');
            continue;
        }

        // Copia de seguridad (solo si se pidió).
        if ($do_backup && is_file($absFile)) {
            if (!wps_backup_file($absFile, $rel, $batch)) {
                $errors[$rel] = __('Could not create the backup; not restored', 'wp-profiler-security');
                continue;
            }
        }

        // Restaurar (creando el directorio si el archivo estaba faltante).
        wp_mkdir_p(dirname($absFile));
        $ok = @file_put_contents($absFile, $fetch['body']);
        if ($ok === false) {
            $errors[$rel] = __('Could not write', 'wp-profiler-security');
            continue;
        }
        $restored[] = $rel;
    }

    wp_send_json_success([
        'restored'   => $restored,
        'errors'     => $errors,
        'backup_dir' => ($do_backup && $batch && !empty($restored)) ? $batch['rel'] : null,
    ]);
});

/**
 * Devuelve la lista de rutas marcadas como "Extra" en el último análisis.
 */
function wps_get_extra_files(): array {
    $analysis = get_transient('wps_last_analysis');
    $extras = [];
    if ($analysis && !empty($analysis['errors']) && is_array($analysis['errors'])) {
        foreach ($analysis['errors'] as $err) {
            if (strpos($err, 'Extra:') === 0) {
                $extras[] = trim(preg_replace('/^Extra:\s*/', '', $err));
            }
        }
    }
    return $extras;
}

/**
 * Elimina de la lista de errores del transient las rutas "Extra" indicadas,
 * para que la interfaz quede coherente tras un borrado.
 */
function wps_remove_extras_from_analysis(array $rels): void {
    $analysis = get_transient('wps_last_analysis');
    if (!$analysis || empty($analysis['errors'])) return;

    $analysis['errors'] = array_values(array_filter($analysis['errors'], function ($err) use ($rels) {
        if (strpos($err, 'Extra:') === 0) {
            $p = trim(preg_replace('/^Extra:\s*/', '', $err));
            return !in_array($p, $rels, true);
        }
        return true;
    }));

    set_transient('wps_last_analysis', $analysis, HOUR_IN_SECONDS);
}

/**
 * Ajax: eliminar un archivo "extra" (no reconocido por el core). IRREVERSIBLE.
 * Solo se permite borrar rutas que el último análisis marcó como "Extra".
 */
add_action('wp_ajax_wps_delete_extra', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_delete_extra', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel) {
        wp_send_json_error(['message' => __('Invalid path', 'wp-profiler-security')], 400);
    }

    // Salvaguarda: solo se borran ficheros marcados como "Extra" en el análisis.
    if (!in_array($rel, wps_get_extra_files(), true)) {
        wp_send_json_error(['message' => __('The file is not in the extras list of the last analysis. Run the analysis again.', 'wp-profiler-security')], 400);
    }

    $absFile = wps_resolve_site_path($rel);
    if (!$absFile || !is_file($absFile)) {
        wp_send_json_error(['message' => sprintf(__('File not found: %s', 'wp-profiler-security'), $rel)], 404);
    }

    if (!@unlink($absFile)) {
        wp_send_json_error(['message' => __('Could not delete the file. Check permissions.', 'wp-profiler-security')], 500);
    }

    wps_remove_extras_from_analysis([$rel]);
    wp_send_json_success(['message' => sprintf(__('File deleted: %s', 'wp-profiler-security'), $rel), 'deleted' => $rel]);
});

/**
 * Ajax: eliminar TODOS los archivos "extra" del último análisis. IRREVERSIBLE.
 */
add_action('wp_ajax_wps_delete_all_extras', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_delete_all_extras', 'nonce');
    wps_raise_limits();

    $extras = wps_get_extra_files();
    if (empty($extras)) {
        wp_send_json_error(['message' => __('There are no extra files to delete.', 'wp-profiler-security')], 400);
    }

    $deleted = [];
    $errors  = [];

    foreach ($extras as $rel) {
        if (!$rel) {
            $errors[$rel] = __('Invalid path', 'wp-profiler-security');
            continue;
        }
        $absFile = wps_resolve_site_path($rel);
        if (!$absFile || !is_file($absFile)) {
            $errors[$rel] = __('Not found', 'wp-profiler-security');
            continue;
        }
        if (@unlink($absFile)) {
            $deleted[] = $rel;
        } else {
            $errors[$rel] = __('Could not delete (permissions)', 'wp-profiler-security');
        }
    }

    wps_remove_extras_from_analysis($deleted);
    wp_send_json_success(['deleted' => $deleted, 'errors' => $errors]);
});

/**
 * Descargar el ZIP oficial de WordPress y extraer solo el archivo solicitado.
 *
 * Para instalaciones traducidas (locale != en_US) los builds localizados
 * modifican algunos ficheros del core (p. ej. version.php lleva
 * $wp_local_package). Por eso se prueba primero el paquete del idioma —que es
 * coherente con los checksums usados en el análisis— y, si falla, se recurre
 * al paquete internacional.
 */
function wps_fetch_core_file_from_zip(string $version, string $relative_path, string $locale = ''): ?array {
    global $wps_fetch_last_error;
    $wps_fetch_last_error = '';

    // Saneado de entradas.
    $version = preg_replace('/[^0-9.]/', '', $version);
    $locale  = preg_replace('/[^a-zA-Z_]/', '', $locale ?: get_locale());
    $relative_path = ltrim($relative_path, '/');

    if (!class_exists('ZipArchive')) {
        $wps_fetch_last_error = __('The ZipArchive class is not available in your PHP version.', 'wp-profiler-security');
        return null;
    }

    // Directorio de caché protegido: la ruta del ZIP es calculable desde fuera
    // (md5 de una URL pública), así que debe estar denegado por HTTP.
    $cache = wps_plugin_dir_in_uploads('wp-profiler-security-cache');
    if (!$cache) {
        $wps_fetch_last_error = __('Could not create the cache directory. Check permissions.', 'wp-profiler-security');
        return null;
    }
    $cache_dir = $cache['dir'];

    // Lista de ZIP a intentar en orden de preferencia.
    $urls = [];
    if ($locale && strpos($locale, 'en_US') !== 0) {
        $sub = strtolower(substr($locale, 0, 2)); // es_ES -> es, fr_FR -> fr, ...
        $urls[] = "https://{$sub}.wordpress.org/wordpress-{$version}-{$locale}.zip";
    }
    $urls[] = "https://downloads.wordpress.org/release/wordpress-{$version}.zip";

    $errors = [];
    foreach ($urls as $zip_url) {
        // Nombre de caché único por URL (localizado e internacional no colisionan).
        $zip_path = $cache_dir . 'wps-' . md5($zip_url) . '.zip';

        if (!file_exists($zip_path) || filesize($zip_path) === 0) {
            $res = wp_remote_get($zip_url, ['timeout' => 120, 'stream' => true, 'filename' => $zip_path]);
            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
                $errors[] = basename($zip_url) . ': ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res));
                if (file_exists($zip_path)) @unlink($zip_path);
                continue;
            }
        }

        // El handle del ZIP se reutiliza durante toda la petición: en una
        // restauración masiva, reabrir un archivo de ~30 MB por cada fichero
        // agotaba el tiempo de ejecución y dejaba el core a medio restaurar.
        static $handles = [];
        if (!isset($handles[$zip_path])) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== true) {
                $errors[] = basename($zip_url) . ': ' . __('corrupt ZIP', 'wp-profiler-security');
                @unlink($zip_path);
                $handles[$zip_path] = false;
                continue;
            }
            $handles[$zip_path] = $zip;
        }
        if ($handles[$zip_path] === false) {
            $errors[] = basename($zip_url) . ': ' . __('corrupt ZIP', 'wp-profiler-security');
            continue;
        }
        $zip = $handles[$zip_path];

        $content = $zip->getFromName('wordpress/' . $relative_path);
        if ($content === false) {
            $content = $zip->getFromName($relative_path); // Fallback para ficheros de la raíz.
        }

        if ($content !== false) {
            return ['body' => $content, 'url' => $zip_url];
        }
        $errors[] = basename($zip_url) . ': ' . sprintf(__('does not contain %s', 'wp-profiler-security'), $relative_path);
    }

    $wps_fetch_last_error = __('Could not fetch the original file.', 'wp-profiler-security') . ' ' . implode(' | ', $errors);
    return null;
}

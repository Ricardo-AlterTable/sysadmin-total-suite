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
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_diff_nonce', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel || strpos($rel, '..') !== false) {
        wp_send_json_error(['message' => 'Ruta no válida'], 400);
    }

    $absRoot = wp_normalize_path(realpath(ABSPATH));
    $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
    if (!$absFile || strpos($absFile, $absRoot) !== 0 || !is_file($absFile)) {
        wp_send_json_error(['message' => 'Archivo no encontrado: ' . $rel], 404);
    }

    $current = @file_get_contents($absFile);
    if ($current === false) {
        wp_send_json_error(['message' => 'No se pudo leer el archivo actual: ' . $rel], 500);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);

    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => 'No se pudo obtener el archivo original.', 'details' => $GLOBALS['wps_fetch_last_error'] ?? 'n/a'], 500);
    }

    $original = $fetch['body'];

    if (hash('sha256', $current) === hash('sha256', $original)) {
        wp_send_json_success(['path' => $rel, 'diff' => "No se detectaron diferencias."]);
    }

    $diff = wps_unified_diff($original, $current, 3);
    wp_send_json_success(['path' => $rel, 'diff' => $diff]);
});

/**
 * Ajax: restaurar archivo individual (backup en carpeta raíz)
 */
add_action('wp_ajax_wps_restore_file', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_restore_file', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel || strpos($rel, '..') !== false) {
        wp_send_json_error(['message' => 'Ruta no válida'], 400);
    }

    $absRoot = wp_normalize_path(realpath(ABSPATH));
    $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
    if (!$absFile || strpos($absFile, $absRoot) !== 0) {
        wp_send_json_error(['message' => 'Archivo fuera del sitio: ' . $rel], 403);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);
    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => 'No se pudo obtener el archivo original.', 'details' => $GLOBALS['wps_fetch_last_error'] ?? 'n/a'], 500);
    }

    // === Copia de seguridad opcional (a petición del usuario) ===
    $do_backup  = isset($_POST['backup']) && $_POST['backup'] === '1';
    $backup_rel = null;

    if ($do_backup && is_file($absFile)) {
        $backup_rel  = 'backup_wp-profiler-security/' . time() . '/' . $rel;
        $backup_path = ABSPATH . $backup_rel;
        wp_mkdir_p(dirname($backup_path));
        if (!@copy($absFile, $backup_path)) {
            // Si se pidió copia y no se puede crear, abortamos sin sobrescribir.
            wp_send_json_error(['message' => 'No se pudo crear la copia de seguridad. Revisa permisos; el archivo NO se ha restaurado.'], 500);
        }
    }

    // Restaurar
    $ok = @file_put_contents($absFile, $fetch['body']);
    if ($ok === false) {
        wp_send_json_error(['message' => 'No se pudo escribir el archivo local. Revisa permisos.'], 500);
    }

    wp_send_json_success([
        'message' => 'Archivo restaurado correctamente: ' . $rel,
        'backup'  => $backup_rel, // ruta relativa a la raíz de WordPress, o null si no se pidió copia
    ]);
});

/**
 * Ajax: restaurar todos los archivos modificados
 */
add_action('wp_ajax_wps_restore_all_files', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_restore_all', 'nonce');

    $files = isset($_POST['files']) && is_array($_POST['files']) ? array_map('sanitize_text_field', wp_unslash($_POST['files'])) : [];
    if (empty($files)) {
        wp_send_json_error(['message' => 'No se especificaron archivos a restaurar.'], 400);
    }

    $analysis = get_transient('wps_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $restored = [];
    $errors = [];

    // Copia de seguridad opcional: una carpeta por lote.
    $do_backup  = isset($_POST['backup']) && $_POST['backup'] === '1';
    $batch_rel  = 'backup_wp-profiler-security/' . time() . '/';
    $batch_root = ABSPATH . $batch_rel;

    foreach ($files as $rel) {
        if (!$rel || strpos($rel, '..') !== false) {
            $errors[$rel] = 'Ruta no válida';
            continue;
        }
        $absRoot = wp_normalize_path(realpath(ABSPATH));
        $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
        if (!$absFile || strpos($absFile, $absRoot) !== 0) {
            $errors[$rel] = 'Archivo fuera del sitio';
            continue;
        }
        $fetch = wps_fetch_core_file_from_zip($version, $rel, $locale);
        if (!is_array($fetch) || empty($fetch['body'])) {
            $errors[$rel] = 'No se pudo obtener original: ' . ($GLOBALS['wps_fetch_last_error'] ?? '');
            continue;
        }

        // Copia de seguridad (solo si se pidió).
        if ($do_backup && is_file($absFile)) {
            $backup_path = $batch_root . $rel;
            wp_mkdir_p(dirname($backup_path));
            if (!@copy($absFile, $backup_path)) {
                $errors[$rel] = 'No se pudo crear la copia de seguridad; no se restauró';
                continue;
            }
        }

        // Restaurar
        $ok = @file_put_contents($absFile, $fetch['body']);
        if ($ok === false) {
            $errors[$rel] = 'No se pudo escribir';
            continue;
        }
        $restored[] = $rel;
    }

    wp_send_json_success([
        'restored'   => $restored,
        'errors'     => $errors,
        'backup_dir' => ($do_backup && !empty($restored)) ? $batch_rel : null,
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

    set_transient('wps_last_analysis', $analysis, 5 * MINUTE_IN_SECONDS);
}

/**
 * Ajax: eliminar un archivo "extra" (no reconocido por el core). IRREVERSIBLE.
 * Solo se permite borrar rutas que el último análisis marcó como "Extra".
 */
add_action('wp_ajax_wps_delete_extra', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_delete_extra', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel || strpos($rel, '..') !== false) {
        wp_send_json_error(['message' => 'Ruta no válida'], 400);
    }

    // Salvaguarda: solo se borran ficheros marcados como "Extra" en el análisis.
    if (!in_array($rel, wps_get_extra_files(), true)) {
        wp_send_json_error(['message' => 'El archivo no está en la lista de extras del último análisis. Vuelve a analizar.'], 400);
    }

    $absRoot = wp_normalize_path(realpath(ABSPATH));
    $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
    if (!$absFile || strpos($absFile, $absRoot) !== 0 || !is_file($absFile)) {
        wp_send_json_error(['message' => 'Archivo no encontrado: ' . $rel], 404);
    }

    if (!@unlink($absFile)) {
        wp_send_json_error(['message' => 'No se pudo eliminar el archivo. Revisa permisos.'], 500);
    }

    wps_remove_extras_from_analysis([$rel]);
    wp_send_json_success(['message' => 'Archivo eliminado: ' . $rel, 'deleted' => $rel]);
});

/**
 * Ajax: eliminar TODOS los archivos "extra" del último análisis. IRREVERSIBLE.
 */
add_action('wp_ajax_wps_delete_all_extras', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_delete_all_extras', 'nonce');

    $extras = wps_get_extra_files();
    if (empty($extras)) {
        wp_send_json_error(['message' => 'No hay archivos extra para eliminar.'], 400);
    }

    $absRoot = wp_normalize_path(realpath(ABSPATH));
    $deleted = [];
    $errors  = [];

    foreach ($extras as $rel) {
        if (!$rel || strpos($rel, '..') !== false) {
            $errors[$rel] = 'Ruta no válida';
            continue;
        }
        $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
        if (!$absFile || strpos($absFile, $absRoot) !== 0 || !is_file($absFile)) {
            $errors[$rel] = 'No encontrado';
            continue;
        }
        if (@unlink($absFile)) {
            $deleted[] = $rel;
        } else {
            $errors[$rel] = 'No se pudo eliminar (permisos)';
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
        $wps_fetch_last_error = 'La clase ZipArchive no está disponible en tu versión de PHP.';
        return null;
    }

    $upload_dir = wp_upload_dir();
    $cache_dir  = trailingslashit($upload_dir['basedir']) . 'wp-profiler-security-cache/';
    wp_mkdir_p($cache_dir);

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

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            $errors[] = basename($zip_url) . ': ZIP corrupto';
            @unlink($zip_path);
            continue;
        }

        $content = $zip->getFromName('wordpress/' . $relative_path);
        if ($content === false) {
            $content = $zip->getFromName($relative_path); // Fallback para ficheros de la raíz.
        }
        $zip->close();

        if ($content !== false) {
            return ['body' => $content, 'url' => $zip_url];
        }
        $errors[] = basename($zip_url) . ": no contiene {$relative_path}";
    }

    $wps_fetch_last_error = 'No se pudo obtener el archivo original. ' . implode(' | ', $errors);
    return null;
}

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

    $version = get_transient('wps_last_analysis')['version'] ?? get_bloginfo('version');
    $fetch = wps_fetch_core_file_from_zip($version, $rel);

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
    check_ajax_referer('wps_diff_nonce', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel || strpos($rel, '..') !== false) {
        wp_send_json_error(['message' => 'Ruta no válida'], 400);
    }

    $absRoot = wp_normalize_path(realpath(ABSPATH));
    $absFile = wp_normalize_path(realpath(ABSPATH . $rel));
    if (!$absFile || strpos($absFile, $absRoot) !== 0) {
        wp_send_json_error(['message' => 'Archivo fuera del sitio: ' . $rel], 403);
    }

    $version = get_transient('wps_last_analysis')['version'] ?? get_bloginfo('version');
    $fetch = wps_fetch_core_file_from_zip($version, $rel);
    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => 'No se pudo obtener el archivo original.', 'details' => $GLOBALS['wps_fetch_last_error'] ?? 'n/a'], 500);
    }

    // === Backup en carpeta raíz ===
    $backup_root = ABSPATH . 'backup_wp-profiler-security/' . time() . '/';
    $backup_path = $backup_root . $rel;
    wp_mkdir_p(dirname($backup_path));

    if (is_file($absFile)) {
        @copy($absFile, $backup_path);
    }

    // Restaurar
    $ok = @file_put_contents($absFile, $fetch['body']);
    if ($ok === false) {
        wp_send_json_error(['message' => 'No se pudo escribir el archivo local. Revisa permisos.'], 500);
    }

    wp_send_json_success(['message' => 'Archivo restaurado correctamente: ' . $rel, 'backup' => $backup_path]);
});

/**
 * Ajax: restaurar todos los archivos modificados
 */
add_action('wp_ajax_wps_restore_all_files', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
    }
    check_ajax_referer('wps_diff_nonce', 'nonce');

    $files = isset($_POST['files']) && is_array($_POST['files']) ? array_map('sanitize_text_field', wp_unslash($_POST['files'])) : [];
    if (empty($files)) {
        wp_send_json_error(['message' => 'No se especificaron archivos a restaurar.'], 400);
    }

    $version = get_transient('wps_last_analysis')['version'] ?? get_bloginfo('version');
    $restored = [];
    $errors = [];

    // Crear carpeta de backups por lote
    $batch_root = ABSPATH . 'backup_wp-profiler-security/' . time() . '/';

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
        $fetch = wps_fetch_core_file_from_zip($version, $rel);
        if (!is_array($fetch) || empty($fetch['body'])) {
            $errors[$rel] = 'No se pudo obtener original: ' . ($GLOBALS['wps_fetch_last_error'] ?? '');
            continue;
        }

        // Backup
        $backup_path = $batch_root . $rel;
        wp_mkdir_p(dirname($backup_path));
        if (is_file($absFile)) {
            @copy($absFile, $backup_path);
        }

        // Restaurar
        $ok = @file_put_contents($absFile, $fetch['body']);
        if ($ok === false) {
            $errors[$rel] = 'No se pudo escribir';
            continue;
        }
        $restored[] = $rel;
    }

    wp_send_json_success(['restored' => $restored, 'errors' => $errors]);
});

/**
 * Descargar ZIP oficial y extraer solo el archivo solicitado
 */
function wps_fetch_core_file_from_zip(string $version, string $relative_path): ?array {
    global $wps_fetch_last_error;
    $wps_fetch_last_error = '';
    $relative_path = ltrim($relative_path, '/');

    $zip_url = "https://downloads.wordpress.org/release/wordpress-{$version}.zip";
    $tmpfile = wp_tempnam($zip_url);
    if ($tmpfile === false) $tmpfile = tempnam(sys_get_temp_dir(), 'wpszip_');

    $res = wp_remote_get($zip_url, ['timeout' => 60, 'stream' => true, 'filename' => $tmpfile]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        $wps_fetch_last_error = 'No se pudo descargar ZIP oficial: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res));
        @unlink($tmpfile);
        return null;
    }

    if (!class_exists('ZipArchive')) {
        $wps_fetch_last_error = 'ZipArchive no disponible en PHP.';
        @unlink($tmpfile);
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpfile) !== true) {
        $wps_fetch_last_error = 'No se pudo abrir ZIP descargado.';
        @unlink($tmpfile);
        return null;
    }

    $internal = 'wordpress/' . $relative_path;
    $content = $zip->getFromName($internal);
    if ($content === false) {
        $content = $zip->getFromName($relative_path);
    }
    $zip->close();
    @unlink($tmpfile);

    if ($content === false) {
        $wps_fetch_last_error = "Archivo $relative_path no encontrado dentro del ZIP.";
        return null;
    }

    return ['body' => $content, 'url' => $zip_url];
}

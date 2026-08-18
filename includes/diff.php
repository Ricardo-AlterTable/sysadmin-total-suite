<?php
// includes/diff.php
if (!defined('ABSPATH')) exit;

global $stsuite_fetch_last_error;
$stsuite_fetch_last_error = '';

/**
 * Simple unified diff renderer: prefer wp_text_diff if available, fallback to a simple line diff.
 */
if (!function_exists('stsuite_unified_diff')) {
    function stsuite_unified_diff($old, $new, $context = 3) {
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
add_action('wp_ajax_stsuite_show_diff', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_diff_nonce', 'nonce');

    $rel = isset($_POST['path']) ? sanitize_text_field(wp_unslash($_POST['path'])) : '';
    if (!$rel) {
        wp_send_json_error(['message' => __('Invalid path', 'sysadmin-total-suite')], 400);
    }

    // Resolver ANTES de validar: así '..' ya está normalizado y stsuite_is_core_path()
    // se evalúa sobre la ruta real, no sobre la que envió el cliente.
    $absFile = stsuite_resolve_site_path($rel);
    if (!$absFile || !is_file($absFile)) {
        /* translators: %s: file path. */
        wp_send_json_error(['message' => sprintf(__('File not found: %s', 'sysadmin-total-suite'), $rel)], 404);
    }
    $rel = stsuite_relative_site_path($absFile);
    if (!$rel || !stsuite_is_core_path($rel)) {
        wp_send_json_error(['message' => __('Only WordPress core files can be compared.', 'sysadmin-total-suite')], 400);
    }

    $current = @file_get_contents($absFile);
    if ($current === false) {
        /* translators: %s: file path. */
        wp_send_json_error(['message' => sprintf(__('Could not read the current file: %s', 'sysadmin-total-suite'), $rel)], 500);
    }

    $analysis = get_transient('stsuite_last_analysis');
    $version  = $analysis['version'] ?? get_bloginfo('version');
    $locale   = $analysis['locale'] ?? get_locale();
    $fetch = stsuite_fetch_core_file_from_zip($version, $rel, $locale);

    if (!is_array($fetch) || empty($fetch['body'])) {
        wp_send_json_error(['message' => __('Could not fetch the original file.', 'sysadmin-total-suite'), 'details' => $GLOBALS['stsuite_fetch_last_error'] ?? 'n/a'], 500);
    }

    $original = $fetch['body'];

    if (hash('sha256', $current) === hash('sha256', $original)) {
        wp_send_json_success(['path' => $rel, 'diff' => __('No differences found.', 'sysadmin-total-suite')]);
    }

    $diff = stsuite_unified_diff($original, $current, 3);
    wp_send_json_success(['path' => $rel, 'diff' => $diff]);
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
function stsuite_fetch_core_file_from_zip(string $version, string $relative_path, string $locale = ''): ?array {
    global $stsuite_fetch_last_error;
    $stsuite_fetch_last_error = '';

    // Saneado de entradas.
    $version = preg_replace('/[^0-9.]/', '', $version);
    $locale  = preg_replace('/[^a-zA-Z_]/', '', $locale ?: get_locale());
    $relative_path = ltrim($relative_path, '/');

    if (!class_exists('ZipArchive')) {
        $stsuite_fetch_last_error = __('The ZipArchive class is not available in your PHP version.', 'sysadmin-total-suite');
        return null;
    }

    // Directorio de caché protegido: la ruta del ZIP es calculable desde fuera
    // (md5 de una URL pública), así que debe estar denegado por HTTP.
    $cache = stsuite_plugin_dir_in_uploads('sysadmin-total-suite-cache');
    if (!$cache) {
        $stsuite_fetch_last_error = __('Could not create the cache directory. Check permissions.', 'sysadmin-total-suite');
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
        $zip_path = $cache_dir . 'stsuite-' . md5($zip_url) . '.zip';

        if (!file_exists($zip_path) || filesize($zip_path) === 0) {
            $res = wp_remote_get($zip_url, ['timeout' => 120, 'stream' => true, 'filename' => $zip_path]);
            if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
                $errors[] = basename($zip_url) . ': ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res));
                if (file_exists($zip_path)) wp_delete_file($zip_path);
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
                $errors[] = basename($zip_url) . ': ' . __('corrupt ZIP', 'sysadmin-total-suite');
                wp_delete_file($zip_path);
                $handles[$zip_path] = false;
                continue;
            }
            $handles[$zip_path] = $zip;
        }
        if ($handles[$zip_path] === false) {
            $errors[] = basename($zip_url) . ': ' . __('corrupt ZIP', 'sysadmin-total-suite');
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
        /* translators: %s: file path inside the ZIP. */
        $errors[] = basename($zip_url) . ': ' . sprintf(__('does not contain %s', 'sysadmin-total-suite'), $relative_path);
    }

    $stsuite_fetch_last_error = __('Could not fetch the original file.', 'sysadmin-total-suite') . ' ' . implode(' | ', $errors);
    return null;
}

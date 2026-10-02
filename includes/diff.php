<?php
// includes/diff.php
if (!defined('ABSPATH')) exit;

global $stsuite_fetch_last_error;
$stsuite_fetch_last_error = '';

/**
 * Diff unificado (como `diff -u`) entre el original y el archivo actual, en
 * texto plano: bloques "@@ -a,b +c,d @@" con $context líneas de contexto y
 * líneas prefijadas con " ", "-" o "+".
 *
 * Usa Text_Diff, el motor que WordPress incluye para comparar revisiones
 * (busca la subsecuencia común más larga), así que una línea añadida o
 * borrada no desplaza el resto del archivo. Si no estuviera disponible, se
 * recurre a una comparación línea a línea por posición.
 *
 * @param string|string[] $old
 * @param string|string[] $new
 * @return string '' si no hay diferencias de contenido (p. ej. solo saltos de línea).
 */
function stsuite_unified_diff($old, $new, int $context = 3): string {
    $a = is_array($old) ? $old : explode("\n", str_replace("\r", '', (string) $old));
    $b = is_array($new) ? $new : explode("\n", str_replace("\r", '', (string) $new));
    // El salto de línea final no es una línea más (como en diff).
    foreach ([&$a, &$b] as &$lines) {
        if (count($lines) > 1 && end($lines) === '') array_pop($lines);
    }
    unset($lines);

    if (!class_exists('Text_Diff', false) && is_file(ABSPATH . WPINC . '/Text/Diff.php')) {
        require_once ABSPATH . WPINC . '/Text/Diff.php';
    }

    // Filas: [tipo, texto, nº de línea en el original, nº en el actual].
    $rows = [];
    $o = 1;
    $n = 1;
    if (class_exists('Text_Diff', false)) {
        $diff = new Text_Diff('auto', [$a, $b]);
        foreach ($diff->getDiff() as $op) {
            $orig  = is_array($op->orig) ? $op->orig : [];
            $final = is_array($op->final) ? $op->final : [];
            if ($op instanceof Text_Diff_Op_copy) {
                foreach ($orig as $line) $rows[] = [' ', $line, $o++, $n++];
            } else {
                foreach ($orig as $line) $rows[] = ['-', $line, $o++, $n];
                foreach ($final as $line) $rows[] = ['+', $line, $o, $n++];
            }
        }
    } else {
        $max = max(count($a), count($b));
        for ($i = 0; $i < $max; $i++) {
            $la = $a[$i] ?? null;
            $lb = $b[$i] ?? null;
            if ($la === $lb) {
                $rows[] = [' ', (string) $la, $o++, $n++];
            } else {
                if ($la !== null) $rows[] = ['-', $la, $o++, $n];
                if ($lb !== null) $rows[] = ['+', $lb, $o, $n++];
            }
        }
    }

    // Rangos de filas que se muestran: cada cambio con su contexto, fusionando
    // los que se solapan o se tocan.
    $ranges = [];
    foreach ($rows as $i => $row) {
        if ($row[0] === ' ') continue;
        $from = max(0, $i - $context);
        $to   = min(count($rows) - 1, $i + $context);
        $last = count($ranges) - 1;
        if ($last >= 0 && $from <= $ranges[$last][1] + 1) {
            $ranges[$last][1] = max($ranges[$last][1], $to);
        } else {
            $ranges[] = [$from, $to];
        }
    }
    if (!$ranges) return '';

    $out = [];
    foreach ($ranges as [$from, $to]) {
        $old_len = 0;
        $new_len = 0;
        $body    = [];
        for ($i = $from; $i <= $to; $i++) {
            [$type, $text] = $rows[$i];
            if ($type !== '+') $old_len++;
            if ($type !== '-') $new_len++;
            $body[] = $type . $text;
        }
        // Como en diff -u: un bloque vacío en un lado empieza en la línea anterior.
        $old_start = $old_len ? $rows[$from][2] : $rows[$from][2] - 1;
        $new_start = $new_len ? $rows[$from][3] : $rows[$from][3] - 1;
        $out[] = sprintf('@@ -%d,%d +%d,%d @@', $old_start, $old_len, $new_start, $new_len);
        foreach ($body as $line) $out[] = $line;
    }
    return implode("\n", $out);
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
    if ($diff === '') {
        $diff = __('The contents are identical: the files only differ in their line endings (Windows/Unix).', 'sysadmin-total-suite');
    }
    wp_send_json_success(['path' => $rel, 'diff' => $diff]);
});

/**
 * Borra de la caché los ZIP oficiales que no corresponden a la versión e
 * idioma actuales. Sin esto, cada actualización de WordPress dejaba atrás
 * ~30-40 MB por paquete. Solo actúa sobre los ficheros propios del plugin
 * (stsuite-<md5>.zip) dentro de su carpeta de caché.
 *
 * @param string   $cache_dir Carpeta de caché (con barra final).
 * @param string[] $keep_urls URL de los paquetes vigentes.
 */
function stsuite_prune_zip_cache(string $cache_dir, array $keep_urls): void {
    $keep = [];
    foreach ($keep_urls as $url) {
        $keep['stsuite-' . md5($url) . '.zip'] = true;
    }
    foreach ((array) glob($cache_dir . 'stsuite-*.zip') as $file) {
        if (is_string($file) && preg_match('/^stsuite-[0-9a-f]{32}\.zip$/', basename($file)) && !isset($keep[basename($file)])) {
            wp_delete_file($file);
        }
    }
}

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
            // Recién descargado: los ZIP de otras versiones ya no sirven.
            stsuite_prune_zip_cache($cache_dir, $urls);
        }

        // El handle del ZIP se reutiliza durante toda la petición para no
        // reabrir un archivo de ~30 MB cada vez que se lee un fichero de él.
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

<?php
// includes/backups.php — Gestión de las copias de seguridad de la restauración.
if (!defined('ABSPATH')) exit;

/**
 * Directorios donde puede haber copias de seguridad.
 *
 * - 'uploads': ubicación actual, protegida frente a acceso por HTTP.
 * - 'legacy' : ubicación de versiones anteriores a la 3.6, en la raíz del sitio
 *              (accesible por HTTP). Se listan para poder purgarlas.
 *
 * @return array<string,string> clave => ruta absoluta con barra final
 */
function wps_backup_bases(): array {
    $bases  = [];
    $upload = wp_upload_dir();

    if (empty($upload['error']) && !empty($upload['basedir'])) {
        $bases['uploads'] = trailingslashit(wp_normalize_path($upload['basedir'])) . 'wp-profiler-security-backups/';
    }
    $bases['legacy'] = trailingslashit(wp_normalize_path(ABSPATH)) . 'backup_wp-profiler-security/';

    return $bases;
}

/**
 * Interpreta el nombre de un lote y devuelve su fecha (epoch UTC) o null.
 *
 * Formatos admitidos:
 *  - 'Y-m-d-His-xxxxxxxx' (3.6+, con sufijo aleatorio)
 *  - '1234567890'         (versiones anteriores: time())
 */
function wps_backup_batch_time(string $batch): ?int {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})(?:-[A-Za-z0-9]+)?$/', $batch, $m)) {
        return gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    }
    if (ctype_digit($batch)) {
        return (int) $batch;
    }
    return null;
}

/**
 * Valida el identificador de un lote (nombre de directorio).
 */
function wps_backup_valid_batch(string $batch): bool {
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,64}$/', $batch)
        && strpos($batch, '..') === false;
}

/**
 * Resuelve de forma segura una ruta dentro de un almacén de copias.
 *
 * @return string|false Ruta absoluta normalizada dentro del almacén, o false.
 */
function wps_resolve_backup_path(string $store, string $batch, string $rel = '') {
    $bases = wps_backup_bases();
    if (!isset($bases[$store]) || !wps_backup_valid_batch($batch)) return false;

    $base = trailingslashit(wp_normalize_path($bases[$store]));

    // Resolución léxica: ningún segmento puede salir del almacén.
    $parts = [$batch];
    foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') return false;
        if (strpos($seg, "\0") !== false) return false;
        $parts[] = $seg;
    }

    $target = wp_normalize_path($base . implode('/', $parts));

    // 1) Comprobación léxica sobre la ruta lógica.
    if (strpos($target, $base) !== 0) return false;

    // 2) Comprobación por realpath, resuelta en AMBOS lados: el docroot o
    //    uploads pueden estar tras un enlace simbólico (habitual en hosting),
    //    y comparar una ruta resuelta con otra sin resolver daría falsos
    //    negativos que impedirían gestionar copias legítimas.
    $base_real = realpath($bases[$store]);
    if ($base_real === false) {
        return $target; // el almacén aún no existe: nada más que validar
    }
    $base_real = trailingslashit(wp_normalize_path($base_real));

    // Se resuelve el ancestro existente más cercano (el destino puede no existir).
    $probe = $target;
    while (!file_exists($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) return false;
        $probe = $parent;
    }
    $probe_real = realpath($probe);
    if ($probe_real === false) return false;
    $probe_real = trailingslashit(wp_normalize_path($probe_real));

    if (strpos($probe_real, $base_real) !== 0) return false;

    return $target;
}

/**
 * Lista los lotes de copias de seguridad, con su fecha y los archivos que contienen.
 *
 * @return array Lista de lotes ordenados de más reciente a más antiguo.
 */
function wps_list_backups(): array {
    $batches = [];

    foreach (wps_backup_bases() as $store => $base) {
        if (!is_dir($base)) continue;

        foreach ((array) scandir($base) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $dir = trailingslashit($base) . $entry;
            if (!is_dir($dir) || !wps_backup_valid_batch($entry)) continue;

            $files = [];
            $total = 0;
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $info) {
                    if (!$info->isFile()) continue;
                    $name = $info->getFilename();
                    // Ficheros de protección, no son copias.
                    if ($name === '.htaccess' || $name === 'index.php') continue;

                    $rel  = ltrim(str_replace(wp_normalize_path(trailingslashit($dir)), '', wp_normalize_path($info->getPathname())), '/');
                    $size = (int) $info->getSize();
                    $total += $size;

                    $files[] = [
                        'rel'    => $rel,                                  // ruta dentro del lote (puede acabar en .bak)
                        'target' => preg_replace('/\.bak$/', '', $rel),    // ruta original en el sitio
                        'size'   => $size,
                    ];
                }
            } catch (Exception $e) {
                continue;
            }

            if (empty($files)) continue;
            usort($files, fn($a, $b) => strcmp($a['target'], $b['target']));

            $batches[] = [
                'store'  => $store,
                'batch'  => $entry,
                'time'   => wps_backup_batch_time($entry),
                'files'  => $files,
                'count'  => count($files),
                'size'   => $total,
                'legacy' => ($store === 'legacy'),
            ];
        }
    }

    usort($batches, function ($a, $b) {
        return ($b['time'] ?? 0) <=> ($a['time'] ?? 0);
    });

    return $batches;
}

/**
 * Borra un directorio de forma recursiva, siempre dentro de un almacén de copias.
 */
function wps_delete_backup_dir(string $dir): bool {
    if (!is_dir($dir)) return false;

    // Contención verificada con realpath en ambos lados (ver wps_resolve_backup_path).
    $dir_real = realpath($dir);
    if ($dir_real === false) return false;
    $dir_real = trailingslashit(wp_normalize_path($dir_real));

    $inside = false;
    foreach (wps_backup_bases() as $base) {
        $base_real = realpath($base);
        if ($base_real === false) continue;
        $base_real = trailingslashit(wp_normalize_path($base_real));
        // Debe estar DENTRO del almacén, nunca ser el almacén mismo.
        if ($dir_real !== $base_real && strpos($dir_real, $base_real) === 0) {
            $inside = true;
            break;
        }
    }
    if (!$inside) return false;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $info) {
        if ($info->isDir()) {
            @rmdir($info->getPathname());
        } else {
            @unlink($info->getPathname());
        }
    }
    return @rmdir($dir);
}

/**
 * Ajax: restaurar un archivo desde una copia de seguridad.
 */
add_action('wp_ajax_wps_restore_backup', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_backups', 'nonce');

    $store = isset($_POST['store']) ? sanitize_key(wp_unslash($_POST['store'])) : '';
    $batch = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : '';
    $rel   = isset($_POST['file'])  ? sanitize_text_field(wp_unslash($_POST['file']))  : '';

    $source = wps_resolve_backup_path($store, $batch, $rel);
    if (!$source || !is_file($source)) {
        wp_send_json_error(['message' => __('The backup file was not found.', 'wp-profiler-security')], 404);
    }

    // Destino: la ruta original (sin el sufijo .bak), validada como en el core.
    $target_rel = preg_replace('/\.bak$/', '', $rel);
    $target     = wps_resolve_site_path($target_rel);
    if (!$target) {
        wp_send_json_error(['message' => __('Invalid destination path.', 'wp-profiler-security')], 400);
    }
    $target_rel = wps_relative_site_path($target);
    if (!$target_rel || !wps_is_core_path($target_rel)) {
        wp_send_json_error(['message' => __('Only WordPress core files can be restored.', 'wp-profiler-security')], 400);
    }

    wp_mkdir_p(dirname($target));
    if (!@copy($source, $target)) {
        wp_send_json_error(['message' => __('Could not write the local file. Check permissions.', 'wp-profiler-security')], 500);
    }

    wp_send_json_success([
        'message' => sprintf(__('File restored from backup: %s', 'wp-profiler-security'), $target_rel),
    ]);
});

/**
 * Ajax: eliminar un archivo concreto de una copia de seguridad. IRREVERSIBLE.
 */
add_action('wp_ajax_wps_delete_backup_file', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_backups', 'nonce');

    $store = isset($_POST['store']) ? sanitize_key(wp_unslash($_POST['store'])) : '';
    $batch = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : '';
    $rel   = isset($_POST['file'])  ? sanitize_text_field(wp_unslash($_POST['file']))  : '';

    $path = wps_resolve_backup_path($store, $batch, $rel);
    if (!$path || !is_file($path)) {
        wp_send_json_error(['message' => __('The backup file was not found.', 'wp-profiler-security')], 404);
    }
    if (!@unlink($path)) {
        wp_send_json_error(['message' => __('Could not delete the backup. Check permissions.', 'wp-profiler-security')], 500);
    }

    // Si el lote se ha quedado vacío, se elimina también.
    $batch_dir = wps_resolve_backup_path($store, $batch);
    if ($batch_dir && is_dir($batch_dir)) {
        $rest = array_diff((array) scandir($batch_dir), ['.', '..']);
        if (empty($rest)) {
            wps_delete_backup_dir($batch_dir);
        }
    }

    wp_send_json_success(['message' => __('Backup deleted.', 'wp-profiler-security')]);
});

/**
 * Ajax: eliminar un lote completo de copias. IRREVERSIBLE.
 */
add_action('wp_ajax_wps_delete_backup_batch', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_backups', 'nonce');

    $store = isset($_POST['store']) ? sanitize_key(wp_unslash($_POST['store'])) : '';
    $batch = isset($_POST['batch']) ? sanitize_text_field(wp_unslash($_POST['batch'])) : '';

    $dir = wps_resolve_backup_path($store, $batch);
    if (!$dir || !is_dir($dir)) {
        wp_send_json_error(['message' => __('The backup was not found.', 'wp-profiler-security')], 404);
    }
    if (!wps_delete_backup_dir($dir)) {
        wp_send_json_error(['message' => __('Could not delete the backup. Check permissions.', 'wp-profiler-security')], 500);
    }

    wp_send_json_success(['message' => __('Backup deleted.', 'wp-profiler-security')]);
});

/**
 * Ajax: eliminar TODAS las copias de seguridad. IRREVERSIBLE.
 */
add_action('wp_ajax_wps_delete_all_backups', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'wp-profiler-security')], 403);
    }
    check_ajax_referer('wps_backups', 'nonce');
    wps_raise_limits();

    $deleted = 0;
    $errors  = [];
    foreach (wps_list_backups() as $b) {
        $dir = wps_resolve_backup_path($b['store'], $b['batch']);
        if ($dir && is_dir($dir)) {
            if (wps_delete_backup_dir($dir)) {
                $deleted++;
            } else {
                $errors[] = $b['batch'];
            }
        }
    }

    if ($deleted === 0 && empty($errors)) {
        wp_send_json_error(['message' => __('There are no backups to delete.', 'wp-profiler-security')], 400);
    }

    wp_send_json_success(['deleted' => $deleted, 'errors' => $errors]);
});

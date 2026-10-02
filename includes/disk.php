<?php
// includes/disk.php — Espacio consumido: archivos del sitio (core, uploads,
// plugins, temas y otras carpetas) y tablas de la base de datos. Solo lectura.
if (!defined('ABSPATH')) exit;

if (!defined('STSUITE_DISK_OPTION')) {
    define('STSUITE_DISK_OPTION', 'stsuite_disk_results');
}

/** Segundos de trabajo por petición AJAX antes de devolver el control al navegador. */
if (!defined('STSUITE_DISK_STEP_SECONDS')) {
    define('STSUITE_DISK_STEP_SECONDS', 5);
}

/** Tope por carpeta: si una sola tarda más, se guarda lo medido y se marca como parcial. */
if (!defined('STSUITE_DISK_UNIT_SECONDS')) {
    define('STSUITE_DISK_UNIT_SECONDS', 20);
}

/**
 * Capacidad requerida. En multisite el disco y la base de datos son de toda la
 * red, así que el administrador de un subsitio no debe verlos.
 */
function stsuite_disk_capability(): string {
    return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Carpeta raíz de uploads de la instalación completa. En multisite,
 * wp_upload_dir() devuelve la de cada sitio (uploads/sites/N): se sube al
 * directorio común para medir la red entera una sola vez.
 */
function stsuite_disk_uploads_root(): string {
    $up = wp_upload_dir(null, false);
    if (!empty($up['error']) || empty($up['basedir'])) return '';
    $base = wp_normalize_path(untrailingslashit($up['basedir']));
    if (is_multisite()) {
        $base = preg_replace('#/sites/\d+$#', '', $base);
    }
    return is_dir($base) ? $base : '';
}

/**
 * Ruta absoluta normalizada y sin barra final, o '' si no existe.
 */
function stsuite_disk_real(string $path): string {
    $real = realpath($path);
    return $real === false ? '' : wp_normalize_path(untrailingslashit($real));
}

/**
 * Ruta para mostrar: relativa a la raíz del sitio cuando está dentro de ella.
 */
function stsuite_disk_display_path(string $abs): string {
    $root = wp_normalize_path(untrailingslashit(ABSPATH));
    $abs  = wp_normalize_path($abs);
    if ($abs === $root) return '/';
    return strpos($abs, $root . '/') === 0 ? substr($abs, strlen($root)) : $abs;
}

/**
 * Lista de subdirectorios y de si hay ficheros sueltos en un directorio.
 *
 * @return array{dirs:string[],has_files:bool}
 */
function stsuite_disk_list(string $dir): array {
    $dirs = [];
    $has_files = false;
    try {
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) continue; // no se siguen enlaces simbólicos
            if ($entry->isDir()) {
                $dirs[] = wp_normalize_path($entry->getPathname());
            } elseif ($entry->isFile()) {
                $has_files = true;
            }
        }
    } catch (Exception $e) {
        // Directorio ilegible: se trata como vacío.
        return ['dirs' => [], 'has_files' => false];
    }
    sort($dirs, SORT_STRING);
    return ['dirs' => $dirs, 'has_files' => $has_files];
}

/**
 * Construye la lista de unidades a medir. Cada unidad es una carpeta (medida
 * de forma recursiva) o el conjunto de ficheros sueltos de una carpeta
 * ('files'), para que ninguna cuente dos veces el mismo contenido.
 *
 * @return array<string,array{group:string,label:string,path:string,mode:string}>
 */
function stsuite_disk_build_units(): array {
    $units = [];
    $add = function (string $group, string $label, string $path, string $mode = 'tree') use (&$units) {
        $key = md5($group . '|' . $mode . '|' . $path);
        $units[$key] = ['group' => $group, 'label' => $label, 'path' => $path, 'mode' => $mode];
    };

    $root        = stsuite_disk_real(ABSPATH);
    $content     = stsuite_disk_real(WP_CONTENT_DIR);
    $uploads     = stsuite_disk_real(stsuite_disk_uploads_root());
    $plugins     = stsuite_disk_real(WP_PLUGIN_DIR);
    $themes      = stsuite_disk_real(get_theme_root());
    $mu_plugins  = defined('WPMU_PLUGIN_DIR') ? stsuite_disk_real(WPMU_PLUGIN_DIR) : '';
    $handled     = array_filter([$content, $uploads, $plugins, $themes]);

    // Core: wp-admin, wp-includes y ficheros sueltos de la raíz.
    if ($root !== '') {
        foreach (['wp-admin', 'wp-includes'] as $d) {
            if (is_dir($root . '/' . $d)) $add('core', $d, $root . '/' . $d);
        }
        $add('core', __('Files in the site root', 'sysadmin-total-suite'), $root, 'files');
    }

    // Uploads: un año se divide en meses; el resto de carpetas, una a una.
    if ($uploads !== '') {
        $list = stsuite_disk_list($uploads);
        foreach ($list['dirs'] as $dir) {
            $name = basename($dir);
            if (preg_match('/^(19|20)\d{2}$/', $name)) {
                $months = stsuite_disk_list($dir);
                foreach ($months['dirs'] as $m) {
                    $add('uploads', $name . '/' . basename($m), $m);
                }
                if ($months['has_files']) $add('uploads', $name, $dir, 'files');
            } elseif (is_multisite() && $name === 'sites') {
                $add('uploads', 'sites/ ' . __('(network sites)', 'sysadmin-total-suite'), $dir);
            } else {
                $add('uploads', $name . '/', $dir);
            }
        }
        if ($list['has_files']) $add('uploads', __('Loose files', 'sysadmin-total-suite'), $uploads, 'files');
    }

    // Plugins y temas: uno por carpeta.
    foreach (['plugins' => $plugins, 'themes' => $themes] as $group => $base) {
        if ($base === '') continue;
        $list = stsuite_disk_list($base);
        foreach ($list['dirs'] as $dir) {
            $add($group, basename($dir), $dir);
        }
        if ($list['has_files']) $add($group, __('Loose files', 'sysadmin-total-suite'), $base, 'files');
    }

    // Resto de wp-content (caché, copias de otros plugins, idiomas, mu-plugins...).
    if ($content !== '') {
        $list = stsuite_disk_list($content);
        foreach ($list['dirs'] as $dir) {
            if (in_array($dir, $handled, true)) continue;
            $label = basename($dir) . '/';
            if ($dir === $mu_plugins) $label .= ' (mu-plugins)';
            $add('content', $label, $dir);
        }
        if ($list['has_files']) $add('content', __('Files in wp-content', 'sysadmin-total-suite'), $content, 'files');
    }

    // Otras carpetas de la raíz que no son de WordPress (copias, otras webs...).
    if ($root !== '') {
        $list = stsuite_disk_list($root);
        foreach ($list['dirs'] as $dir) {
            $name = basename($dir);
            if (in_array($name, ['wp-admin', 'wp-includes'], true)) continue;
            // wp-content (o uploads/plugins si están fuera de él) ya se miden aparte.
            $skip = false;
            foreach ($handled as $h) {
                if ($dir === $h || strpos($h . '/', $dir . '/') === 0) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) $add('root', $name . '/', $dir);
        }
    }

    return $units;
}

/**
 * Mide una unidad: suma de tamaños y número de ficheros.
 * No sigue enlaces simbólicos y se detiene (marcando 'partial') si supera el
 * tiempo máximo por carpeta. 'unreadable' indica que la carpeta no se pudo
 * abrir por permisos.
 *
 * @return array{bytes:int,files:int,partial:bool,unreadable:bool}
 */
function stsuite_disk_measure(string $path, string $mode): array {
    $bytes = 0;
    $files = 0;
    $partial = false;
    $deadline = microtime(true) + STSUITE_DISK_UNIT_SECONDS;

    if (!is_dir($path)) {
        return ['bytes' => 0, 'files' => 0, 'partial' => false, 'unreadable' => false];
    }
    if (!is_readable($path)) {
        return ['bytes' => 0, 'files' => 0, 'partial' => false, 'unreadable' => true];
    }

    // Abrir la carpeta: si falla, es ilegible.
    try {
        if ($mode === 'files') {
            $it = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        } else {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD // subcarpetas ilegibles: se omiten
            );
        }
    } catch (Exception $e) {
        return ['bytes' => 0, 'files' => 0, 'partial' => false, 'unreadable' => true];
    }

    // Recorrerla: un error a mitad deja la medición como parcial.
    try {
        foreach ($it as $f) {
            if ($f->isLink() || !$f->isFile()) continue;
            $bytes += (int) $f->getSize();
            $files++;
            if (($files % 2000) === 0 && microtime(true) > $deadline) {
                $partial = true;
                break;
            }
        }
    } catch (Exception $e) {
        $partial = true;
    }

    return ['bytes' => $bytes, 'files' => $files, 'partial' => $partial, 'unreadable' => false];
}

/**
 * Tamaño de las tablas de la base de datos de WordPress.
 *
 * @return array{tables:array,other_bytes:int,other_count:int}|null
 */
function stsuite_disk_db_tables(): ?array {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnóstico en tiempo real del tamaño de las tablas; no hay API de WordPress para esto.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_count, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s',
            DB_NAME
        ),
        ARRAY_A
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if (!is_array($rows)) return null;

    $prefix = $wpdb->base_prefix;
    $tables = [];
    $other_bytes = 0;
    $other_count = 0;

    foreach ($rows as $r) {
        $size = (int) $r['data_bytes'] + (int) $r['index_bytes'];
        // Las tablas de otras aplicaciones que compartan base de datos se suman aparte.
        if (strpos((string) $r['name'], $prefix) !== 0) {
            $other_bytes += $size;
            $other_count++;
            continue;
        }
        $tables[] = [
            'name'   => (string) $r['name'],
            'engine' => (string) $r['engine'],
            'rows'   => (int) $r['row_count'],
            'bytes'  => $size,
            // En InnoDB, DATA_FREE es espacio reservado del tablespace (extents
            // preasignados), no "overhead" recuperable: solo se informa para
            // MyISAM/Aria, igual que phpMyAdmin.
            'free'   => in_array(strtolower((string) $r['engine']), ['myisam', 'aria'], true) ? (int) $r['free_bytes'] : 0,
        ];
    }
    usort($tables, fn($a, $b) => $b['bytes'] <=> $a['bytes']);

    return ['tables' => $tables, 'other_bytes' => $other_bytes, 'other_count' => $other_count];
}

/**
 * Espacio libre y total del disco donde está el sitio, si el hosting lo permite.
 *
 * @return array{free:float,total:float}|null
 */
function stsuite_disk_space(): ?array {
    if (!function_exists('disk_free_space') || !function_exists('disk_total_space')) return null;
    $free  = disk_free_space(ABSPATH);
    $total = disk_total_space(ABSPATH);
    if ($free === false || $total === false || $total <= 0) return null;
    return ['free' => (float) $free, 'total' => (float) $total];
}

/**
 * Resultados guardados de la última medición.
 */
function stsuite_disk_results(): array {
    $r = get_option(STSUITE_DISK_OPTION, []);
    return is_array($r) ? $r : [];
}

/**
 * Ajax: iniciar la medición. La base de datos y el disco se miden aquí (son
 * consultas rápidas); las carpetas se reparten en lotes.
 */
add_action('wp_ajax_stsuite_disk_start', function () {
    if (!current_user_can(stsuite_disk_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_disk', 'nonce');

    $units = stsuite_disk_build_units();
    $counts = function_exists('wp_count_attachments') ? (array) wp_count_attachments() : [];
    unset($counts['trash']);

    update_option(STSUITE_DISK_OPTION, [
        'status'      => 'running',
        'started_at'  => time(),
        'checked_at'  => 0,
        'queue'       => array_keys($units),
        'units'       => $units,
        'db'          => stsuite_disk_db_tables(),
        'disk'        => stsuite_disk_space(),
        'attachments' => (int) array_sum(array_map('intval', $counts)),
    ], false);

    wp_send_json_success(['total' => count($units)]);
});

/**
 * Ajax: medir carpetas hasta agotar el tiempo de trabajo de esta petición.
 */
add_action('wp_ajax_stsuite_disk_step', function () {
    if (!current_user_can(stsuite_disk_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_disk', 'nonce');

    $results = stsuite_disk_results();
    if (($results['status'] ?? '') !== 'running' || !isset($results['queue'], $results['units'])) {
        wp_send_json_error(['message' => __('No scan in progress.', 'sysadmin-total-suite')], 400);
    }

    stsuite_raise_limits();
    $deadline = microtime(true) + STSUITE_DISK_STEP_SECONDS;

    while (!empty($results['queue']) && microtime(true) < $deadline) {
        $key = array_shift($results['queue']);
        if (!isset($results['units'][$key])) continue;
        $unit = $results['units'][$key];
        $results['units'][$key] = array_merge($unit, stsuite_disk_measure($unit['path'], $unit['mode']));
    }

    $done = empty($results['queue']);
    if ($done) {
        $results['status']     = 'done';
        $results['checked_at'] = time();
        unset($results['queue']);
    }
    update_option(STSUITE_DISK_OPTION, $results, false);

    $total = count($results['units']);
    wp_send_json_success([
        'done'      => $done,
        'total'     => $total,
        'processed' => $done ? $total : $total - count($results['queue']),
    ]);
});

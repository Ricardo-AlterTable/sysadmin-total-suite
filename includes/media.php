<?php
// includes/media.php — Medios no usados: informe de SOLO LECTURA de adjuntos
// sin referencias y de archivos de uploads que no pertenecen a ningún adjunto.
//
// La detección es heurística y CONSERVADORA: ante la duda, un medio se
// considera "en uso". Un falso "en uso" solo hace que no se proponga; un falso
// "no usado" podría acabar en el borrado de una imagen necesaria.
if (!defined('ABSPATH')) exit;

if (!defined('STSUITE_MEDIA_OPTION')) {
    define('STSUITE_MEDIA_OPTION', 'stsuite_media_scan');
}
if (!defined('STSUITE_MEDIA_STEP_SECONDS')) {
    define('STSUITE_MEDIA_STEP_SECONDS', 5);
}

/** Extensiones de archivos de medios que se buscan en las referencias. */
function stsuite_media_ext_regex(): string {
    return 'jpe?g|png|gif|webp|avif|bmp|tiff?|svg|ico|heic|pdf|mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg|zip|docx?|xlsx?|pptx?|odt|csv|txt';
}

/**
 * Capacidad requerida: los medios son de cada sitio, así que basta con la
 * administración del sitio actual.
 */
function stsuite_media_capability(): string {
    return 'manage_options';
}

/**
 * Clave normalizada de un archivo de uploads: ruta relativa sin extensión ni
 * sufijos de tamaño (-300x200), -scaled, -rotated o de edición (-e123...).
 * Así "2024/01/foto-1024x768.jpg" y "2024/01/foto-scaled.jpg" comparten la
 * clave "2024/01/foto".
 */
function stsuite_media_key(string $rel): string {
    $rel  = ltrim(wp_normalize_path(rawurldecode($rel)), '/');
    $base = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $rel);
    $base = preg_replace('/(-e\d{10,13})?(-\d+x\d+)?$/', '', (string) $base);
    $base = preg_replace('/-(scaled|rotated)$/', '', (string) $base);
    return strtolower((string) $base);
}

/**
 * Extrae referencias de un texto (contenido, metadato u opción).
 *
 * @param string $text Texto a analizar.
 * @param string $mode 'content' | 'meta' | 'option'. En 'meta' se aceptan
 *                     también valores numéricos sueltos o listas de números
 *                     (ACF, galerías), que en las opciones serían demasiado
 *                     ambiguos.
 * @return array{ids:int[],keys:string[]}
 */
function stsuite_media_extract(string $text, string $mode): array {
    $ids  = [];
    $keys = [];
    if ($text === '') return ['ids' => [], 'keys' => []];

    // JSON con barras escapadas (Elementor, bloques): "\/uploads\/2024\/01\/a.jpg".
    $text = str_replace('\\/', '/', $text);
    $ext  = stsuite_media_ext_regex();

    // 1) Rutas de uploads en URL completas o relativas.
    if (preg_match_all('#/uploads/([^"\'\s()<>?\#\\\\]+?\.(?:' . $ext . '))(?=[^A-Za-z0-9]|$)#i', $text, $m)) {
        foreach ($m[1] as $rel) $keys[stsuite_media_key($rel)] = true;
    }
    if (preg_match_all('#(?<![\w/])(\d{4}/\d{2}/[^"\'\s()<>?\#\\\\/]+?\.(?:' . $ext . '))(?=[^A-Za-z0-9]|$)#i', $text, $m)) {
        foreach ($m[1] as $rel) $keys[stsuite_media_key($rel)] = true;
    }

    // 2) IDs explícitos de adjunto.
    $patterns = [
        '/wp-image-(\d+)/',                                                                   // clase de las imágenes del editor
        '/"(?:id|ID|attachment_?[iI]d|image_?[iI]d|imageId|media_?[iI]d|mediaId|thumbnail_id)"\s*:\s*"?(\d+)/', // JSON (bloques, Elementor)
        '/s:\d+:"(?:id|attachment_id|image_id|media_id|thumbnail_id|custom_logo|site_icon)";(?:i:(\d+)|s:\d+:"(\d+)")/', // serializado
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $text, $m)) {
            foreach (array_slice($m, 1) as $group) {
                foreach ($group as $v) {
                    if ($v !== '') $ids[(int) $v] = true;
                }
            }
        }
    }
    // Atributos de shortcode con listas de IDs: [gallery ids="1,2"], gallery_ids, include...
    if (preg_match_all('/\b[\w-]*(?:ids?|images?|attachments?|attachment_id|image_id|include)\s*=\s*["\']([\d,\s]+)["\']/i', $text, $m)) {
        foreach ($m[1] as $list) {
            foreach (preg_split('/[\s,]+/', $list) as $v) {
                if ($v !== '') $ids[(int) $v] = true;
            }
        }
    }

    // 3) Solo en metadatos: valores numéricos y listas (ACF, galerías de WooCommerce).
    if ($mode === 'meta') {
        $trim = trim($text);
        if (preg_match('/^\d+(?:\s*,\s*\d+)*$/', $trim)) {
            foreach (preg_split('/\s*,\s*/', $trim) as $v) $ids[(int) $v] = true;
        }
        if (preg_match_all('/i:\d+;(?:i:(\d+);|s:\d+:"(\d+)";)/', $text, $m)) { // arrays serializados de IDs
            foreach (array_merge($m[1], $m[2]) as $v) {
                if ($v !== '') $ids[(int) $v] = true;
            }
        }
        if (preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $text, $m)) {        // arrays JSON de IDs
            foreach ($m[1] as $list) {
                foreach (preg_split('/\s*,\s*/', $list) as $v) $ids[(int) $v] = true;
            }
        }
    }

    unset($ids[0]);
    return ['ids' => array_keys($ids), 'keys' => array_keys($keys)];
}

/**
 * Fases del escaneo, en orden. Cada una se procesa por lotes con un cursor.
 */
function stsuite_media_phases(): array {
    return ['posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'classify', 'orphans'];
}

/**
 * Procesa un lote de la fase actual. Devuelve true si la fase ha terminado.
 */
function stsuite_media_run_phase(array &$s): bool {
    global $wpdb;
    $cursor = (int) $s['cursor'];
    $add = function (array $found) use (&$s) {
        foreach ($found['ids'] as $id) {
            if ($id <= $s['max_id']) $s['ids'][$id] = 1;
        }
        foreach ($found['keys'] as $k) $s['keys'][$k] = 1;
    };

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recorrido por lotes de tablas completas para buscar referencias; no hay API equivalente.
    switch ($s['phase']) {
        case 'posts':
            // Todo menos adjuntos, revisiones y borradores automáticos. Las
            // entradas en la papelera cuentan: se pueden restaurar.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment', 'revision') AND post_status <> 'auto-draft' ORDER BY ID LIMIT 100",
                $cursor
            ));
            foreach ($rows as $r) {
                $add(stsuite_media_extract((string) $r->post_content . "\n" . (string) $r->post_excerpt, 'content'));
                // [gallery] sin ids muestra los adjuntos hijos de la entrada.
                if (preg_match('/\[gallery(?![^\]]*\bids\s*=)[^\]]*\]/i', (string) $r->post_content)) {
                    $s['gallery_parents'][(int) $r->ID] = 1;
                }
                $cursor = (int) $r->ID;
            }
            break;

        case 'postmeta':
            // Los metadatos de los propios adjuntos no cuentan (cada uno se
            // referenciaría a sí mismo).
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_id > %d AND p.post_type NOT IN ('attachment', 'revision') ORDER BY pm.meta_id LIMIT 500",
                $cursor
            ));
            foreach ($rows as $r) {
                $add(stsuite_media_extract((string) $r->meta_value, 'meta'));
                $cursor = (int) $r->meta_id;
            }
            break;

        case 'options':
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_id LIMIT 200",
                $cursor,
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_site_transient_') . '%',
                $wpdb->esc_like('stsuite_') . '%'
            ));
            foreach ($rows as $r) {
                $value = (string) $r->option_value;
                // Valor numérico suelto solo en opciones con nombre de imagen (site_icon...).
                $mode = preg_match('/icon|logo|image|thumbnail|attachment|media|background|avatar/i', (string) $r->option_name) ? 'meta' : 'option';
                $add(stsuite_media_extract($value, $mode));
                $cursor = (int) $r->option_id;
            }
            break;

        case 'termmeta':
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id LIMIT 500",
                $cursor
            ));
            foreach ($rows as $r) {
                $add(stsuite_media_extract((string) $r->meta_value, 'meta'));
                $cursor = (int) $r->meta_id;
            }
            break;

        case 'usermeta':
            // Solo metadatos de usuario con aspecto de imagen (avatares locales).
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d AND (meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s) ORDER BY umeta_id LIMIT 500",
                $cursor,
                '%' . $wpdb->esc_like('avatar') . '%',
                '%' . $wpdb->esc_like('image') . '%',
                '%' . $wpdb->esc_like('photo') . '%'
            ));
            foreach ($rows as $r) {
                $add(stsuite_media_extract((string) $r->meta_value, 'meta'));
                $cursor = (int) $r->umeta_id;
            }
            break;

        case 'classify':
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_date_gmt, post_mime_type, post_parent FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' ORDER BY ID LIMIT 200",
                $cursor
            ));
            if ($rows) {
                update_meta_cache('post', wp_list_pluck($rows, 'ID'));
            }
            foreach ($rows as $r) {
                $cursor = (int) $r->ID;
                $s['checked']++;
                $id   = (int) $r->ID;
                $file = (string) get_post_meta($id, '_wp_attached_file', true);
                $meta = wp_get_attachment_metadata($id);

                $used = isset($s['ids'][$id])
                    || ((int) $r->post_parent > 0 && isset($s['gallery_parents'][(int) $r->post_parent]))
                    || ($file !== '' && isset($s['keys'][stsuite_media_key($file)]));
                if (!$used && is_array($meta) && !empty($meta['original_image']) && $file !== '') {
                    $orig = trailingslashit(dirname($file)) . $meta['original_image'];
                    $used = isset($s['keys'][stsuite_media_key($orig)]);
                }
                if ($used) continue;

                $s['unused'][] = [
                    'id'     => $id,
                    'title'  => (string) $r->post_title,
                    'file'   => $file,
                    'mime'   => (string) $r->post_mime_type,
                    'date'   => (string) $r->post_date_gmt,
                    'parent' => (int) $r->post_parent,
                    'bytes'  => stsuite_media_attachment_bytes($file, $meta),
                ];
            }
            break;

        case 'orphans':
            // Un lote = una carpeta AAAA/MM. El cursor es su índice en la lista.
            if (!isset($s['month_dirs'])) {
                $s['month_dirs'] = stsuite_media_month_dirs();
            }
            $dir = $s['month_dirs'][$cursor] ?? null;
            if ($dir !== null) {
                $found = stsuite_media_orphans_in($dir);
                foreach ($found['orphans'] as $orphan) $s['orphans'][] = $orphan;
                foreach ($found['backups'] as $backup) $s['backups'][] = $backup;
                $cursor++;
                $rows = [1]; // hay más trabajo mientras queden carpetas
            } else {
                $rows = [];
            }
            break;

        default:
            $rows = [];
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    $s['cursor'] = $cursor;
    return empty($rows);
}

/**
 * Bytes que ocupa un adjunto: archivo principal, tamaños intermedios y
 * original previo al escalado.
 */
function stsuite_media_attachment_bytes(string $file, $meta): int {
    $base = stsuite_media_upload_basedir();
    if ($file === '' || $base === '') return 0;

    $dir   = trailingslashit($base . '/' . dirname($file));
    $files = [basename($file)];
    if (is_array($meta)) {
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (!empty($size['file'])) $files[] = (string) $size['file'];
        }
        if (!empty($meta['original_image'])) $files[] = (string) $meta['original_image'];
    }

    $bytes = 0;
    foreach (array_unique($files) as $f) {
        $path = $dir . basename($f);
        if (is_file($path)) $bytes += (int) filesize($path);
    }
    return $bytes;
}

/** Carpeta base de uploads del sitio actual (normalizada, sin barra final). */
function stsuite_media_upload_basedir(): string {
    $up = wp_upload_dir(null, false);
    return (empty($up['error']) && !empty($up['basedir'])) ? wp_normalize_path(untrailingslashit($up['basedir'])) : '';
}

/**
 * Carpetas AAAA/MM de uploads (relativas). Solo esas: las carpetas de otros
 * plugins (cachés, formularios, copias) no son medios y no se tocan.
 */
function stsuite_media_month_dirs(): array {
    $base = stsuite_media_upload_basedir();
    if ($base === '' || !is_dir($base)) return [];

    $dirs = [];
    foreach ((array) glob($base . '/[12][0-9][0-9][0-9]', GLOB_ONLYDIR) as $year) {
        foreach ((array) glob($year . '/[01][0-9]', GLOB_ONLYDIR) as $month) {
            if (!is_link($month)) $dirs[] = basename($year) . '/' . basename($month);
        }
    }
    sort($dirs);
    return $dirs;
}

/**
 * Archivos de una carpeta AAAA/MM que no pertenecen a ningún adjunto.
 *
 * Se consideran propios de un adjunto: su archivo, sus tamaños, el original
 * escalado, las copias de edición (backup sizes) y los derivados de plugins de
 * optimización con el mismo nombre (foto.jpg.webp, foto-300x200.webp...).
 *
 * Las copias del original que guardan los optimizadores (LiteSpeed Cache:
 * foto.bk.jpg) se devuelven aparte en 'backups': no son basura sin dueño, sino
 * lo que permite "restaurar el original" desde ese plugin, que es donde deben
 * eliminarse.
 *
 * @return array{orphans:array<int,array{file:string,bytes:int,mtime:int}>,backups:array<int,array{file:string,bytes:int,mtime:int}>}
 */
function stsuite_media_orphans_in(string $rel_dir): array {
    global $wpdb;
    $base = stsuite_media_upload_basedir();
    $abs  = $base . '/' . $rel_dir;
    if ($base === '' || !is_dir($abs)) return ['orphans' => [], 'backups' => []];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Adjuntos de una carpeta concreta; no hay API que filtre por ruta.
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
        $wpdb->esc_like($rel_dir . '/') . '%'
    ));

    $known = [];  // nombres exactos
    $bases = [];  // nombres sin extensión
    $roots = [];  // imagen base de cada adjunto, sin tamaño ni -scaled (para las copias .bk)
    if ($ids) {
        update_meta_cache('post', array_map('intval', $ids));
    }
    foreach ($ids as $id) {
        $id   = (int) $id;
        $file = (string) get_post_meta($id, '_wp_attached_file', true);
        $meta = wp_get_attachment_metadata($id);
        $names = [basename($file)];
        if (is_array($meta)) {
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!empty($size['file'])) $names[] = (string) $size['file'];
            }
            if (!empty($meta['original_image'])) $names[] = (string) $meta['original_image'];
        }
        foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $backup) {
            if (is_array($backup) && !empty($backup['file'])) $names[] = (string) $backup['file'];
        }
        $roots[basename(stsuite_media_key($file))] = true;
        foreach ($names as $n) {
            $n = strtolower(basename($n));
            $known[$n] = true;
            $bases[preg_replace('/\.[a-z0-9]{2,5}$/', '', $n)] = true;
        }
    }

    $orphans = [];
    $backups = [];
    foreach ((array) glob($abs . '/*') as $path) {
        if (!is_string($path) || !is_file($path) || is_link($path)) continue;
        $name = strtolower(basename($path));
        if ($name === '' || $name[0] === '.' || $name === 'index.php' || $name === 'index.html') continue;

        $no_ext = preg_replace('/\.[a-z0-9]{2,5}$/', '', $name);
        if (isset($known[$name]) || isset($known[$no_ext]) || isset($bases[$no_ext])) {
            continue; // archivo del adjunto o derivado (foto.jpg.webp / foto.webp)
        }
        $entry = [
            'file'  => $rel_dir . '/' . basename($path),
            'bytes' => (int) filesize($path),
            'mtime' => (int) filemtime($path),
        ];
        // Copia de optimización de un archivo conocido: foto-300x200.bk.jpg.
        // Se asocia por la imagen base, porque puede ser la copia de una miniatura
        // de un tamaño que ya no existe (foto-1280x900.bk.png).
        if (preg_match('/^(.+)\.bk\.([a-z0-9]{2,5})$/', $name, $bk) && isset($roots[basename(stsuite_media_key($bk[1] . '.' . $bk[2]))])) {
            $backups[] = $entry;
            continue;
        }
        $orphans[] = $entry;
    }
    return ['orphans' => $orphans, 'backups' => $backups];
}

/**
 * Resultados guardados del último escaneo.
 */
function stsuite_media_results(): array {
    $r = get_option(STSUITE_MEDIA_OPTION, []);
    return is_array($r) ? $r : [];
}

/**
 * Ajax: iniciar el escaneo.
 */
add_action('wp_ajax_stsuite_media_start', function () {
    if (!current_user_can(stsuite_media_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_media', 'nonce');

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Límite superior de IDs de adjunto para descartar números que no pueden serlo.
    $max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'");

    update_option(STSUITE_MEDIA_OPTION, [
        'status'          => 'running',
        'started_at'      => time(),
        'checked_at'      => 0,
        'phase'           => 'posts',
        'cursor'          => 0,
        'max_id'          => $max_id,
        'ids'             => [],
        'keys'            => [],
        'gallery_parents' => [],
        'checked'         => 0,
        'unused'          => [],
        'orphans'         => [],
        'backups'         => [],
    ], false);

    wp_send_json_success(['total' => count(stsuite_media_phases()), 'phase' => 'posts', 'first' => 1]);
});

/**
 * Ajax: avanzar el escaneo hasta agotar el tiempo de esta petición.
 */
add_action('wp_ajax_stsuite_media_step', function () {
    if (!current_user_can(stsuite_media_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_media', 'nonce');

    $s = stsuite_media_results();
    if (($s['status'] ?? '') !== 'running') {
        wp_send_json_error(['message' => __('No scan in progress.', 'sysadmin-total-suite')], 400);
    }

    stsuite_raise_limits();
    $phases   = stsuite_media_phases();
    $deadline = microtime(true) + STSUITE_MEDIA_STEP_SECONDS;

    while ($s['status'] === 'running' && microtime(true) < $deadline) {
        if (stsuite_media_run_phase($s)) {
            $next = array_search($s['phase'], $phases, true) + 1;
            if (isset($phases[$next])) {
                $s['phase']  = $phases[$next];
                $s['cursor'] = 0;
            } else {
                $s['status']     = 'done';
                $s['checked_at'] = time();
                // Ya no hacen falta: se descartan para no inflar la opción.
                unset($s['ids'], $s['keys'], $s['gallery_parents'], $s['month_dirs'], $s['cursor'], $s['phase']);
            }
        }
    }
    update_option(STSUITE_MEDIA_OPTION, $s, false);

    $done = $s['status'] === 'done';
    wp_send_json_success([
        'done'      => $done,
        'total'     => count($phases),
        // Paso actual (1..N) mientras se trabaja; N al terminar.
        'processed' => $done ? count($phases) : (int) array_search($s['phase'], $phases, true) + 1,
    ]);
});

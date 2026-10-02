<?php
// includes/media-quarantine.php — Cuarentena reversible de medios no usados.
//
// Enviar a cuarentena MUEVE los archivos de un adjunto (todos sus tamaños, el
// original escalado, sus copias de edición y los derivados/copias de plugins de
// optimización) a uploads/sysadmin-total-suite-quarantine/AAAA/MM/ y marca el
// adjunto con el metadato _stsuite_quarantine, ocultándolo de la biblioteca.
// El registro del adjunto NO se borra, así que restaurar lo deja exactamente
// como estaba. Solo "Vaciar la cuarentena" borra de verdad, con la API de
// WordPress (wp_delete_attachment).
if (!defined('ABSPATH')) exit;

if (!defined('STSUITE_QUARANTINE_META')) {
    define('STSUITE_QUARANTINE_META', '_stsuite_quarantine');
}
if (!defined('STSUITE_QUARANTINE_DIR')) {
    define('STSUITE_QUARANTINE_DIR', 'sysadmin-total-suite-quarantine');
}

/**
 * Nombre de la carpeta de cuarentena: prefijo + clave aleatoria por sitio.
 *
 * El .htaccess que protege la carpeta no se aplica en todos los servidores
 * (nginx no lo lee y LiteSpeed sirve los archivos estáticos sin aplicarlo:
 * comprobado en producción), así que la protección real es que la ruta no se
 * pueda adivinar.
 */
function stsuite_quarantine_dirname(): string {
    $key = (string) get_option('stsuite_quarantine_key', '');
    if (!preg_match('/^[a-z0-9]{20}$/', $key)) {
        $key = strtolower(wp_generate_password(20, false, false));
        update_option('stsuite_quarantine_key', $key, false);
    }
    return STSUITE_QUARANTINE_DIR . '-' . $key;
}

/**
 * Valida el nombre de carpeta guardado en un elemento en cuarentena. Los
 * enviados antes de la clave aleatoria usan el nombre sin sufijo.
 */
function stsuite_quarantine_valid_dirname($name): string {
    $name = (string) $name;
    return preg_match('/^' . preg_quote(STSUITE_QUARANTINE_DIR, '/') . '(-[a-z0-9]{20})?$/', $name) ? $name : STSUITE_QUARANTINE_DIR;
}

/**
 * Ruta absoluta de una carpeta de cuarentena. Con $create, la crea protegida.
 */
function stsuite_quarantine_dir(string $name = '', bool $create = true): string {
    $name = $name !== '' ? stsuite_quarantine_valid_dirname($name) : stsuite_quarantine_dirname();
    if ($create) {
        $dir = stsuite_plugin_dir_in_uploads($name);
        return $dir ? untrailingslashit(wp_normalize_path($dir['dir'])) : '';
    }
    $base = stsuite_media_upload_basedir();
    return $base !== '' ? $base . '/' . $name : '';
}

/**
 * Nombres de archivo (sin ruta) que pertenecen a un adjunto y existen en su carpeta.
 *
 * @return array{dir:string,files:string[]}|null null si el adjunto no está en una carpeta AAAA/MM.
 */
function stsuite_quarantine_collect(int $id): ?array {
    $file = (string) get_post_meta($id, '_wp_attached_file', true);
    $dir  = dirname($file);
    // Solo carpetas AAAA/MM: es lo que se ha analizado y lo único que se toca.
    if (!preg_match('#^\d{4}/\d{2}$#', $dir)) return null;

    $base = stsuite_media_upload_basedir();
    $abs  = $base . '/' . $dir;
    if ($base === '' || !is_dir($abs)) return null;

    $names = [basename($file)];
    $meta  = wp_get_attachment_metadata($id);
    if (is_array($meta)) {
        foreach ((array) ($meta['sizes'] ?? []) as $size) {
            if (!empty($size['file'])) $names[] = (string) $size['file'];
        }
        if (!empty($meta['original_image'])) $names[] = (string) $meta['original_image'];
    }
    foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $backup) {
        if (is_array($backup) && !empty($backup['file'])) $names[] = (string) $backup['file'];
    }

    // Derivados y copias de optimización con el mismo nombre.
    $all = [];
    foreach (array_unique(array_map('basename', $names)) as $n) {
        $no_ext = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $n);
        $ext    = substr($n, strlen((string) $no_ext));
        foreach ([$n, $no_ext . '.bk' . $ext, $n . '.webp', $n . '.avif', $no_ext . '.webp', $no_ext . '.avif'] as $candidate) {
            $all[$candidate] = true;
        }
    }

    $files = [];
    foreach (array_keys($all) as $n) {
        if ($n === '' || strpos($n, '/') !== false || $n[0] === '.') continue;
        $path = $abs . '/' . $n;
        if (is_file($path) && !is_link($path)) $files[] = $n;
    }
    return ['dir' => $dir, 'files' => $files];
}

/**
 * Comprobación en vivo: ¿hay alguna referencia al adjunto ahora mismo?
 * Se hace justo antes de mover y al mostrar la cuarentena, para no confiar
 * solo en un escaneo que puede haberse quedado antiguo. Es deliberadamente
 * amplia (LIKE): un falso positivo solo impide enviarlo a cuarentena.
 */
function stsuite_quarantine_is_referenced(int $id): bool {
    global $wpdb;
    $file = (string) get_post_meta($id, '_wp_attached_file', true);
    $key  = $file !== '' ? stsuite_media_key($file) : '';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Comprobación en tiempo real; cachearla anularía su propósito.
    $like_img = '%' . $wpdb->esc_like('wp-image-' . $id) . '%';
    $found = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id', 'thumbnail_id') AND meta_value = %s",
        (string) $id
    ));
    if (!$found && $key !== '') {
        // La ruta tal cual y con las barras escapadas de JSON (Elementor, bloques).
        $like_key = '%' . $wpdb->esc_like($key) . '%';
        $like_esc = '%' . $wpdb->esc_like(str_replace('/', '\\/', $key)) . '%';
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('attachment', 'revision') AND post_status <> 'auto-draft' AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)",
            $like_key,
            $like_esc,
            $like_img
        ));
        if (!$found) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type <> 'attachment' AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)",
                $like_key,
                $like_esc
            ));
        }
        if (!$found) {
            $found = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)",
                $wpdb->esc_like('stsuite_') . '%',
                $like_key,
                $like_esc
            ));
        }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    return $found > 0;
}

/**
 * Mueve un archivo creando la carpeta de destino. No sobrescribe nunca.
 */
function stsuite_quarantine_move(string $from, string $to): bool {
    if (!is_file($from) || file_exists($to)) return false;
    if (!wp_mkdir_p(dirname($to))) return false;

    $fs = stsuite_filesystem();
    if ($fs) {
        $fs->move($from, $to, false);
    } else {
        // Reserva cuando WP_Filesystem no está disponible; el resultado se comprueba justo debajo.
        @rename($from, $to); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
    }
    clearstatcache(true, $from);
    clearstatcache(true, $to);
    return is_file($to) && !file_exists($from);
}

/**
 * Envía un adjunto a cuarentena.
 *
 * @return string 'ok' | 'referenced' | 'not_allowed' | 'not_supported' | 'error'
 */
function stsuite_quarantine_add(int $id): string {
    if (get_post_type($id) !== 'attachment') return 'error';
    if (!current_user_can('delete_post', $id)) return 'not_allowed';
    if (get_post_meta($id, STSUITE_QUARANTINE_META, true)) return 'ok';
    if (stsuite_quarantine_is_referenced($id)) return 'referenced';

    $set   = stsuite_quarantine_collect($id);
    $qname = stsuite_quarantine_dirname();
    $q     = stsuite_quarantine_dir($qname);
    if ($set === null || $q === '') return 'not_supported';

    $base  = stsuite_media_upload_basedir();
    $moved = [];
    $bytes = 0;
    foreach ($set['files'] as $name) {
        $src = $base . '/' . $set['dir'] . '/' . $name;
        $size = (int) filesize($src);
        if (!stsuite_quarantine_move($src, $q . '/' . $set['dir'] . '/' . $name)) {
            // Deshacer lo ya movido: un adjunto queda entero en un sitio o en el otro.
            foreach ($moved as $back) {
                stsuite_quarantine_move($q . '/' . $set['dir'] . '/' . $back, $base . '/' . $set['dir'] . '/' . $back);
            }
            return 'error';
        }
        $moved[] = $name;
        $bytes  += $size;
    }

    update_post_meta($id, STSUITE_QUARANTINE_META, [
        'at'    => time(),
        'by'    => get_current_user_id(),
        'qdir'  => $qname,
        'dir'   => $set['dir'],
        'files' => $moved,
        'bytes' => $bytes,
    ]);
    return 'ok';
}

/**
 * Restaura un adjunto de la cuarentena.
 *
 * @return string 'ok' | 'conflict' | 'not_found'
 */
function stsuite_quarantine_restore(int $id): string {
    $data = get_post_meta($id, STSUITE_QUARANTINE_META, true);
    if (!is_array($data) || empty($data['dir']) || !preg_match('#^\d{4}/\d{2}$#', (string) $data['dir'])) return 'not_found';

    $base = stsuite_media_upload_basedir();
    $q    = stsuite_quarantine_dir((string) ($data['qdir'] ?? STSUITE_QUARANTINE_DIR), false);
    $left = [];
    foreach ((array) $data['files'] as $name) {
        $name = basename((string) $name);
        $src  = $q . '/' . $data['dir'] . '/' . $name;
        $dst  = $base . '/' . $data['dir'] . '/' . $name;
        if (!is_file($src)) continue;                // ya no está en cuarentena
        if (!stsuite_quarantine_move($src, $dst)) $left[] = $name; // p. ej. existe uno con el mismo nombre
    }

    if ($left) {
        $data['files'] = $left;
        update_post_meta($id, STSUITE_QUARANTINE_META, $data);
        return 'conflict';
    }
    delete_post_meta($id, STSUITE_QUARANTINE_META);
    return 'ok';
}

/**
 * Borra definitivamente un adjunto en cuarentena (registro y archivos).
 */
function stsuite_quarantine_delete(int $id): bool {
    $data = get_post_meta($id, STSUITE_QUARANTINE_META, true);
    if (!is_array($data) || !current_user_can('delete_post', $id)) return false;

    $q   = stsuite_quarantine_dir((string) ($data['qdir'] ?? STSUITE_QUARANTINE_DIR), false);
    $dir = preg_match('#^\d{4}/\d{2}$#', (string) ($data['dir'] ?? '')) ? (string) $data['dir'] : '';

    // API de WordPress: borra el registro, sus metadatos y lanza los hooks de
    // otros plugins (p. ej. LiteSpeed limpia sus tablas). Sus archivos ya no
    // están en la ruta original, así que después se borran los de la cuarentena.
    if (!wp_delete_attachment($id, true)) return false;

    if ($q !== '' && $dir !== '') {
        foreach ((array) $data['files'] as $name) {
            $path = $q . '/' . $dir . '/' . basename((string) $name);
            if (is_file($path)) wp_delete_file($path);
        }
    }
    return true;
}

/**
 * Adjuntos en cuarentena.
 *
 * @return array<int,array{id:int,title:string,file:string,at:int,bytes:int,count:int}>
 */
function stsuite_quarantine_list(): array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Listado de la cuarentena; se lee en cada carga de la pantalla.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", STSUITE_QUARANTINE_META));
    $out = [];
    foreach (array_map('intval', $ids) as $id) {
        $data = get_post_meta($id, STSUITE_QUARANTINE_META, true);
        if (!is_array($data)) continue;
        $out[] = [
            'id'    => $id,
            'title' => get_the_title($id),
            'file'  => (string) get_post_meta($id, '_wp_attached_file', true),
            'at'    => (int) ($data['at'] ?? 0),
            'bytes' => (int) ($data['bytes'] ?? 0),
            'count' => count((array) ($data['files'] ?? [])),
        ];
    }
    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

/**
 * Elimina la carpeta de cuarentena del nombre antiguo (sin clave aleatoria)
 * cuando ya no guarda ningún medio: solo sus ficheros de protección y
 * subcarpetas vacías. Se llama tras restaurar o vaciar.
 */
function stsuite_quarantine_cleanup_legacy(): void {
    $legacy = stsuite_quarantine_dir(STSUITE_QUARANTINE_DIR, false);
    if ($legacy === '' || !is_dir($legacy)) return;
    foreach (stsuite_quarantine_list() as $item) {
        $data = get_post_meta($item['id'], STSUITE_QUARANTINE_META, true);
        if (is_array($data) && stsuite_quarantine_valid_dirname($data['qdir'] ?? '') === STSUITE_QUARANTINE_DIR) return; // aún se usa
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($legacy, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && !in_array($f->getFilename(), ['.htaccess', 'index.php'], true)) return;
    }
    stsuite_delete_dir($legacy);
}

/**
 * Ocultar los adjuntos en cuarentena de la biblioteca de medios (rejilla y lista).
 */
function stsuite_quarantine_exclude(array $meta_query): array {
    $meta_query[] = ['key' => STSUITE_QUARANTINE_META, 'compare' => 'NOT EXISTS'];
    return $meta_query;
}

add_filter('ajax_query_attachments_args', function ($args) {
    $args['meta_query'] = stsuite_quarantine_exclude((array) ($args['meta_query'] ?? [])); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Solo en la biblioteca de medios del admin.
    return $args;
});

add_action('pre_get_posts', function ($query) {
    global $pagenow;
    if (!is_admin() || $pagenow !== 'upload.php' || !$query->is_main_query()) return;
    $query->set('meta_query', stsuite_quarantine_exclude((array) $query->get('meta_query')));
});

/**
 * Lee y sanea la lista de IDs enviada por un formulario.
 */
function stsuite_quarantine_posted_ids(): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- El nonce se comprueba en cada manejador antes de llamar a esta función.
    $ids = isset($_POST['stsuite_ids']) && is_array($_POST['stsuite_ids']) ? array_map('absint', wp_unslash($_POST['stsuite_ids'])) : [];
    return array_values(array_unique(array_filter($ids)));
}

/**
 * Redirige a la pantalla con el resumen de la operación.
 */
function stsuite_quarantine_redirect(string $op, array $counts): void {
    $args = ['page' => 'sysadmin-total-suite-media', 'stsuite_op' => $op];
    foreach ($counts as $k => $v) {
        if ($v > 0) $args['stsuite_' . $k] = (int) $v;
    }
    wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
    exit;
}

// =============================
// Acciones (admin-post)
// =============================
add_action('admin_post_stsuite_media_quarantine', function () {
    if (!current_user_can(stsuite_media_capability())) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_media_quarantine');
    stsuite_raise_limits();

    // Solo se aceptan IDs que el último escaneo dio por no usados.
    $scan    = stsuite_media_results();
    $allowed = array_flip(array_map('intval', array_column((array) ($scan['unused'] ?? []), 'id')));

    $counts = ['done' => 0, 'referenced' => 0, 'failed' => 0];
    $quarantined = [];
    foreach (stsuite_quarantine_posted_ids() as $id) {
        if (!isset($allowed[$id])) {
            $counts['failed']++;
            continue;
        }
        $res = stsuite_quarantine_add($id);
        if ($res === 'ok') {
            $counts['done']++;
            $quarantined[$id] = true;
        } elseif ($res === 'referenced') {
            $counts['referenced']++;
        } else {
            $counts['failed']++;
        }
    }

    // Quitar del informe lo que ya está en cuarentena.
    if ($quarantined && !empty($scan['unused'])) {
        $scan['unused'] = array_values(array_filter($scan['unused'], fn($m) => !isset($quarantined[(int) $m['id']])));
        update_option(STSUITE_MEDIA_OPTION, $scan, false);
    }
    stsuite_quarantine_redirect('quarantine', $counts);
});

add_action('admin_post_stsuite_media_restore', function () {
    if (!current_user_can(stsuite_media_capability())) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_media_restore');
    stsuite_raise_limits();

    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce comprobado arriba.
    $ids = !empty($_POST['stsuite_all']) ? array_column(stsuite_quarantine_list(), 'id') : stsuite_quarantine_posted_ids();

    $counts   = ['done' => 0, 'conflict' => 0];
    $restored = [];
    foreach ($ids as $id) {
        $res = stsuite_quarantine_restore((int) $id);
        if ($res === 'ok') {
            $counts['done']++;
            $restored[] = (int) $id;
        } elseif ($res === 'conflict') {
            $counts['conflict']++;
        }
    }

    // Lo restaurado vuelve a la lista de no usados si sigue sin referencias,
    // para que no desaparezca del informe hasta el siguiente escaneo.
    $scan = stsuite_media_results();
    if ($restored && ($scan['status'] ?? '') === 'done') {
        $listed = array_flip(array_map('intval', array_column((array) $scan['unused'], 'id')));
        foreach ($restored as $id) {
            if (isset($listed[$id]) || stsuite_quarantine_is_referenced($id)) continue;
            $post = get_post($id);
            if (!$post) continue;
            $file = (string) get_post_meta($id, '_wp_attached_file', true);
            $scan['unused'][] = [
                'id'     => $id,
                'title'  => (string) $post->post_title,
                'file'   => $file,
                'mime'   => (string) $post->post_mime_type,
                'date'   => (string) $post->post_date_gmt,
                'parent' => (int) $post->post_parent,
                'bytes'  => stsuite_media_attachment_bytes($file, wp_get_attachment_metadata($id)),
            ];
        }
        update_option(STSUITE_MEDIA_OPTION, $scan, false);
    }
    stsuite_quarantine_cleanup_legacy();
    stsuite_quarantine_redirect('restore', $counts);
});

add_action('admin_post_stsuite_media_purge', function () {
    if (!current_user_can(stsuite_media_capability())) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_media_purge');
    stsuite_raise_limits();

    $counts = ['done' => 0, 'failed' => 0];
    foreach (stsuite_quarantine_list() as $item) {
        if (stsuite_quarantine_delete($item['id'])) $counts['done']++;
        else $counts['failed']++;
    }
    stsuite_quarantine_cleanup_legacy();
    stsuite_quarantine_redirect('purge', $counts);
});

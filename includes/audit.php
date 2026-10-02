<?php
// includes/audit.php — Auditoría de plugins: actualizaciones pendientes, estado
// en WordPress.org (cerrados / abandonados) y vulnerabilidades conocidas.
if (!defined('ABSPATH')) exit;

if (!defined('STSUITE_AUDIT_OPTION')) {
    define('STSUITE_AUDIT_OPTION', 'stsuite_audit_results');
}
if (!defined('STSUITE_AUDIT_SETTINGS')) {
    define('STSUITE_AUDIT_SETTINGS', 'stsuite_audit_settings');
}

/** Plugins procesados por cada petición AJAX del escaneo. */
if (!defined('STSUITE_AUDIT_BATCH')) {
    define('STSUITE_AUDIT_BATCH', 3);
}

/** Años sin actualizar a partir de los cuales un plugin se considera abandonado. */
if (!defined('STSUITE_AUDIT_STALE_YEARS')) {
    define('STSUITE_AUDIT_STALE_YEARS', 2);
}

/**
 * Capacidad requerida. En multisite los plugins son de toda la red: el
 * administrador de un subsitio (manage_options) no debe ver las
 * vulnerabilidades de la red ni decidir el envío de datos a un tercero.
 */
function stsuite_audit_capability(): string {
    return is_multisite() ? 'manage_network_plugins' : 'manage_options';
}

/**
 * Ajustes de la sección. La consulta de vulnerabilidades contacta con un
 * servicio externo (WPVulnerability), por eso está DESACTIVADA por defecto y
 * requiere que el administrador la active expresamente (directriz 7 de
 * WordPress.org: sin contacto con terceros sin consentimiento).
 */
function stsuite_audit_settings(): array {
    $s = get_option(STSUITE_AUDIT_SETTINGS, []);
    if (!is_array($s)) $s = [];

    return ['vuln_enabled' => !empty($s['vuln_enabled'])];
}

/**
 * Slug de WordPress.org de un plugin a partir de su fichero principal.
 * Se prefiere el que devuelve la API de actualizaciones, que es el real; si no,
 * se deduce del directorio (o del nombre del fichero si es un plugin suelto).
 */
function stsuite_audit_plugin_slug(string $file, $updates): string {
    foreach (['response', 'no_update'] as $bucket) {
        if (is_object($updates) && isset($updates->{$bucket}[$file]->slug)) {
            return sanitize_title((string) $updates->{$bucket}[$file]->slug);
        }
    }
    $dir = dirname($file);
    return sanitize_title($dir !== '.' ? $dir : basename($file, '.php'));
}

/**
 * Inventario local de plugins instalados con su actualización pendiente.
 * Usa los datos de actualizaciones que WordPress ya mantiene (transient
 * update_plugins): no se hace ninguna petición adicional.
 *
 * @return array<string,array> file => datos
 */
function stsuite_audit_local_plugins(): array {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $updates = get_site_transient('update_plugins');
    $items   = [];

    foreach (get_plugins() as $file => $data) {
        $new_version = '';
        if (is_object($updates) && isset($updates->response[$file]->new_version)) {
            $new_version = (string) $updates->response[$file]->new_version;
        }

        $items[$file] = [
            'name'        => (string) ($data['Name'] ?? $file),
            'version'     => (string) ($data['Version'] ?? ''),
            'slug'        => stsuite_audit_plugin_slug($file, $updates),
            'active'      => is_plugin_active($file) || (is_multisite() && is_plugin_active_for_network($file)),
            'new_version' => $new_version,
            'wporg'       => null,
            'vulns'       => null,
        ];
    }

    return $items;
}

/**
 * Inventario local de temas instalados con su actualización pendiente
 * (transient update_themes, que WordPress ya mantiene).
 *
 * @return array<string,array> slug => datos
 */
function stsuite_audit_local_themes(): array {
    $updates = get_site_transient('update_themes');
    $active  = get_stylesheet();
    $parent  = get_template();
    $items   = [];

    foreach (wp_get_themes() as $slug => $theme) {
        $slug = (string) $slug;
        $new_version = '';
        if (is_object($updates) && isset($updates->response[$slug]['new_version'])) {
            $new_version = (string) $updates->response[$slug]['new_version'];
        }
        $items[$slug] = [
            'name'        => (string) $theme->get('Name'),
            'version'     => (string) $theme->get('Version'),
            'slug'        => sanitize_title($slug),
            'state'       => $slug === $active ? 'active' : ($slug === $parent ? 'parent' : 'inactive'),
            'new_version' => $new_version,
            'wporg'       => null,
            'vulns'       => null,
        ];
    }
    return $items;
}

/**
 * Versión del core y actualización disponible (transient update_core).
 */
function stsuite_audit_local_core(): array {
    $version = (string) get_bloginfo('version');
    $new     = '';
    $updates = get_site_transient('update_core');
    if (is_object($updates) && !empty($updates->updates)) {
        foreach ((array) $updates->updates as $offer) {
            if (isset($offer->response, $offer->current) && $offer->response === 'upgrade' && version_compare((string) $offer->current, $version, '>')) {
                $new = (string) $offer->current;
                break;
            }
        }
    }
    return ['version' => $version, 'new_version' => $new, 'vulns' => null];
}

/**
 * Consulta la ficha de un plugin o tema en la API de WordPress.org.
 * Se piden solo los campos necesarios para que la respuesta sea mínima.
 *
 * @param string $type 'plugin' | 'theme'.
 * @return array{status:string,last_updated?:int,tested?:string,latest?:string,closed_date?:string,closed_reason?:string,message?:string}
 */
function stsuite_audit_fetch_wporg(string $slug, string $type = 'plugin'): array {
    $fields = [];
    foreach (['sections', 'description', 'reviews', 'banners', 'icons', 'screenshots', 'screenshot_url', 'contributors', 'versions', 'ratings', 'rating', 'tags', 'donate_link', 'compatibility', 'downloadlink', 'active_installs', 'downloaded'] as $f) {
        $fields[$f] = 0;
    }
    $api = $type === 'theme' ? 'themes' : 'plugins';
    $url = 'https://api.wordpress.org/' . $api . '/info/1.2/?' . http_build_query([
        'action'  => $type === 'theme' ? 'theme_information' : 'plugin_information',
        'request' => ['slug' => $slug, 'fields' => $fields],
    ]);

    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => $response->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return ['status' => 'error', 'message' => 'HTTP ' . $code];
    }

    // La API responde 404 tanto para "no existe" como para "cerrado"; el campo
    // error es el que los distingue.
    if (isset($data['error'])) {
        if ($data['error'] === 'closed') {
            return [
                'status'        => 'closed',
                'closed_date'   => sanitize_text_field((string) ($data['closed_date'] ?? '')),
                'closed_reason' => sanitize_text_field((string) ($data['reason_text'] ?? '')),
            ];
        }
        return ($code === 404) ? ['status' => 'not_found'] : ['status' => 'error', 'message' => sanitize_text_field((string) $data['error'])];
    }

    // Los temas dan la fecha con hora en last_updated_time.
    $raw_date     = (string) ($data['last_updated_time'] ?? ($data['last_updated'] ?? ''));
    $last_updated = $raw_date !== '' ? strtotime($raw_date) : false;

    return [
        'status'       => 'ok',
        'last_updated' => $last_updated ? (int) $last_updated : 0,
        'tested'       => sanitize_text_field((string) ($data['tested'] ?? '')),
        'latest'       => sanitize_text_field((string) ($data['version'] ?? '')),
    ];
}

/**
 * Normaliza la gravedad de una vulnerabilidad (CVSS) a critical/high/medium/low.
 * El campo impact puede venir vacío (array []) cuando no hay puntuación.
 */
function stsuite_audit_severity($impact): array {
    $map = ['c' => 'critical', 'h' => 'high', 'm' => 'medium', 'l' => 'low', 'n' => 'none'];
    $severity = '';
    $score    = '';

    if (is_array($impact)) {
        if (!empty($impact['cvss']['severity'])) {
            $severity = $map[strtolower((string) $impact['cvss']['severity'])] ?? '';
            $score    = (string) ($impact['cvss']['score'] ?? '');
        } elseif (!empty($impact['cvss3']['severity'])) {
            $severity = strtolower((string) $impact['cvss3']['severity']);
            $score    = (string) ($impact['cvss3']['score'] ?? '');
        }
    }

    if (!in_array($severity, $map, true)) $severity = '';
    $score = preg_match('/^\d{1,2}(\.\d)?$/', $score) ? $score : '';

    return ['severity' => $severity, 'score' => $score];
}

/**
 * Indica si una vulnerabilidad afecta a la versión instalada.
 * Mismo criterio que el plugin oficial de WPVulnerability: rango con operador
 * mínimo y/o máximo evaluado con version_compare().
 */
function stsuite_audit_affects(array $vuln, string $version): bool {
    if ($version === '') return false;

    $has_min = $vuln['min_operator'] !== '' && $vuln['min_version'] !== '';
    $has_max = $vuln['max_operator'] !== '' && $vuln['max_version'] !== '';

    if (!$has_min && !$has_max) {
        // Sin rango: solo cuenta si la fuente la marca como sin parche.
        return !empty($vuln['unfixed']);
    }
    if ($has_min && !version_compare($version, $vuln['min_version'], $vuln['min_operator'])) {
        return false;
    }
    if ($has_max && !version_compare($version, $vuln['max_version'], $vuln['max_operator'])) {
        return false;
    }
    return true;
}

/**
 * Consulta las vulnerabilidades conocidas de un plugin, tema o del core en
 * WPVulnerability (https://www.wpvulnerability.com/), un servicio gratuito y
 * abierto.
 *
 * Para plugins y temas solo se envía el slug: la versión instalada NO sale del
 * sitio, la comparación se hace aquí. El core solo se puede consultar por
 * versión (/core/X.Y.Z/ devuelve las que afectan a esa versión exacta), así que
 * en ese caso se envía la versión de WordPress; está declarado en el texto de
 * consentimiento y en el readme. Se devuelven únicamente las vulnerabilidades
 * que afectan a la versión instalada, ya saneadas.
 *
 * @param string $type 'plugin' | 'theme' | 'core' (en 'core', $slug se ignora).
 * @return array{status:string,items?:array,message?:string}
 */
function stsuite_audit_fetch_vulns(string $slug, string $version, string $type = 'plugin'): array {
    if ($type === 'core') {
        $core = preg_replace('/[^0-9.]/', '', $version);
        if ($core === '') return ['status' => 'error', 'message' => 'version'];
        $url = 'https://www.wpvulnerability.net/core/' . $core . '/';
    } else {
        $url = 'https://www.wpvulnerability.net/' . ($type === 'theme' ? 'theme' : 'plugin') . '/' . rawurlencode($slug) . '/';
    }

    $response = wp_remote_get($url, ['timeout' => 8]);
    if (is_wp_error($response)) {
        return ['status' => 'error', 'message' => $response->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($body) || !empty($body['error'])) {
        return ['status' => 'error', 'message' => 'HTTP ' . $code];
    }

    $list = $body['data']['vulnerability'] ?? null;
    if (!is_array($list)) {
        return ['status' => 'ok', 'items' => []]; // sin datos para este slug
    }

    $operators = ['lt', 'le', 'gt', 'ge', 'eq'];
    $items     = [];

    foreach ($list as $v) {
        if (!is_array($v)) continue;
        $op = (isset($v['operator']) && is_array($v['operator'])) ? $v['operator'] : [];

        $vuln = [
            'min_version'  => preg_replace('/[^0-9a-zA-Z.\-]/', '', (string) ($op['min_version'] ?? '')),
            'min_operator' => in_array($op['min_operator'] ?? '', $operators, true) ? $op['min_operator'] : '',
            'max_version'  => preg_replace('/[^0-9a-zA-Z.\-]/', '', (string) ($op['max_version'] ?? '')),
            'max_operator' => in_array($op['max_operator'] ?? '', $operators, true) ? $op['max_operator'] : '',
            'unfixed'      => !empty($op['unfixed']), // la API lo envía como "0" / "1"
        ];

        // En el core la API ya filtra por la versión consultada.
        if ($type !== 'core' && !stsuite_audit_affects($vuln, $version)) continue;

        // Referencias (CVE, Wordfence, WPScan, Patchstack...): solo enlaces http(s).
        $sources = [];
        foreach ((array) ($v['source'] ?? []) as $src) {
            if (!is_array($src)) continue;
            $link = esc_url_raw((string) ($src['link'] ?? ''), ['http', 'https']);
            if ($link === '') continue;
            $sources[] = [
                'id'   => sanitize_text_field((string) ($src['id'] ?? '')),
                'link' => $link,
            ];
        }

        // Título: el nombre más largo de las fuentes (sin contar el identificador
        // CVE), que suele ser el que describe el tipo de fallo. Los nombres llegan
        // con entidades HTML (&lt;) que se decodifican aquí y se escapan al mostrarlos.
        $title = '';
        foreach ((array) ($v['source'] ?? []) as $src) {
            $name = (is_array($src) && isset($src['name'])) ? (string) $src['name'] : '';
            if ($name !== '' && stripos($name, 'CVE-') !== 0 && strlen($name) > strlen($title)) {
                $title = $name;
            }
        }
        // Sin nombre descriptivo (habitual en el core, cuyas fuentes son solo CVE):
        // la descripción de la primera fuente, sin el prefijo de idioma "[en]".
        if ($title === '') {
            foreach ((array) ($v['source'] ?? []) as $src) {
                $desc = (is_array($src) && !empty($src['description'])) ? trim(preg_replace('/^\[[a-z]{2}\]\s*/i', '', (string) $src['description'])) : '';
                if ($desc !== '') {
                    $title = function_exists('mb_strimwidth') ? mb_strimwidth($desc, 0, 160, '…', 'UTF-8') : substr($desc, 0, 160);
                    break;
                }
            }
        }
        if ($title === '') $title = (string) ($v['name'] ?? '');
        $title = preg_replace('/\s+/', ' ', html_entity_decode($title, ENT_QUOTES, 'UTF-8'));

        $items[] = array_merge($vuln, stsuite_audit_severity($v['impact'] ?? null), [
            'title'   => sanitize_text_field($title),
            'sources' => array_slice($sources, 0, 5),
        ]);
    }

    // Más graves primero.
    $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'none' => 4, '' => 5];
    usort($items, fn($a, $b) => ($rank[$a['severity']] ?? 5) <=> ($rank[$b['severity']] ?? 5));

    return ['status' => 'ok', 'items' => $items];
}

/**
 * Resultados guardados del último escaneo.
 */
function stsuite_audit_results(): array {
    $r = get_option(STSUITE_AUDIT_OPTION, []);
    return is_array($r) ? $r : [];
}

/**
 * Indica si un plugin lleva más de STSUITE_AUDIT_STALE_YEARS sin actualizarse.
 */
function stsuite_audit_is_stale(array $item): bool {
    $ts = $item['wporg']['last_updated'] ?? 0;
    return $ts > 0 && $ts < (time() - STSUITE_AUDIT_STALE_YEARS * YEAR_IN_SECONDS);
}

/**
 * Ajax: iniciar un escaneo. Prepara el inventario y la cola de plugins.
 */
add_action('wp_ajax_stsuite_audit_start', function () {
    if (!current_user_can(stsuite_audit_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_audit', 'nonce');

    // Refresca los datos de actualizaciones de WordPress (respeta su propio
    // intervalo; es la misma consulta que hace el core en el escritorio).
    if (function_exists('wp_update_plugins')) {
        wp_update_plugins();
    }
    if (function_exists('wp_update_themes')) {
        wp_update_themes();
    }

    $items  = stsuite_audit_local_plugins();
    $themes = stsuite_audit_local_themes();

    // Cola: 'core', 'theme:<slug>' y los ficheros de plugin.
    $queue = array_merge(['core'], array_map(fn($slug) => 'theme:' . $slug, array_keys($themes)), array_keys($items));

    update_option(STSUITE_AUDIT_OPTION, [
        'status'       => 'running',
        'started_at'   => time(),
        'checked_at'   => 0,
        'vuln_enabled' => stsuite_audit_settings()['vuln_enabled'],
        'queue'        => $queue,
        'items'        => $items,
        'themes'       => $themes,
        'core'         => stsuite_audit_local_core(),
    ], false);

    wp_send_json_success(['total' => count($queue)]);
});

/**
 * Ajax: procesar el siguiente lote de la cola.
 */
add_action('wp_ajax_stsuite_audit_step', function () {
    if (!current_user_can(stsuite_audit_capability())) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_audit', 'nonce');

    $results = stsuite_audit_results();
    if (($results['status'] ?? '') !== 'running' || !isset($results['queue'], $results['items'])) {
        wp_send_json_error(['message' => __('No scan in progress.', 'sysadmin-total-suite')], 400);
    }

    stsuite_raise_limits();

    $total = count($results['items']) + count((array) ($results['themes'] ?? [])) + 1;
    $vulns = !empty($results['vuln_enabled']);
    $batch = array_splice($results['queue'], 0, STSUITE_AUDIT_BATCH);
    foreach ($batch as $key) {
        if ($key === 'core') {
            if ($vulns && isset($results['core'])) {
                $results['core']['vulns'] = stsuite_audit_fetch_vulns('', $results['core']['version'], 'core');
            }
        } elseif (strpos($key, 'theme:') === 0) {
            $slug = substr($key, 6);
            if (!isset($results['themes'][$slug])) continue;
            $results['themes'][$slug]['wporg'] = stsuite_audit_fetch_wporg($results['themes'][$slug]['slug'], 'theme');
            if ($vulns) {
                $results['themes'][$slug]['vulns'] = stsuite_audit_fetch_vulns($results['themes'][$slug]['slug'], $results['themes'][$slug]['version'], 'theme');
            }
        } elseif (isset($results['items'][$key])) {
            $results['items'][$key]['wporg'] = stsuite_audit_fetch_wporg($results['items'][$key]['slug']);
            if ($vulns) {
                $results['items'][$key]['vulns'] = stsuite_audit_fetch_vulns($results['items'][$key]['slug'], $results['items'][$key]['version']);
            }
        }
    }

    $done = empty($results['queue']);
    if ($done) {
        $results['status']     = 'done';
        $results['checked_at'] = time();
        unset($results['queue']);
    }
    update_option(STSUITE_AUDIT_OPTION, $results, false);

    wp_send_json_success([
        'done'      => $done,
        'total'     => $total,
        'processed' => $done ? $total : $total - count($results['queue']),
    ]);
});

/**
 * Guardar ajustes (consentimiento para la consulta de vulnerabilidades).
 */
add_action('admin_post_stsuite_save_audit', function () {
    if (!current_user_can(stsuite_audit_capability())) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_audit_settings_nonce');

    $enabled = !empty($_POST['stsuite_audit_vuln_enabled']);
    update_option(STSUITE_AUDIT_SETTINGS, ['vuln_enabled' => $enabled], false);

    // Al retirar el consentimiento se descartan también los datos obtenidos.
    if (!$enabled) {
        $results = stsuite_audit_results();
        if ($results) {
            foreach (['items', 'themes'] as $group) {
                foreach (array_keys((array) ($results[$group] ?? [])) as $key) {
                    $results[$group][$key]['vulns'] = null;
                }
            }
            if (isset($results['core'])) $results['core']['vulns'] = null;
            $results['vuln_enabled'] = false;
            update_option(STSUITE_AUDIT_OPTION, $results, false);
        }
    }

    wp_safe_redirect(admin_url('admin.php?page=sysadmin-total-suite-audit&saved=1'));
    exit;
});

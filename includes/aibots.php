<?php
// includes/aibots.php — Bloqueo de bots de IA (robots.txt + 403 por User-Agent).
if (!defined('ABSPATH')) exit;

if (!defined('WPS_AIBOTS_OPTION')) {
    define('WPS_AIBOTS_OPTION', 'wps_aibots_settings');
}

/**
 * Lista de bots de IA conocidos.
 * token => [etiqueta, bloqueable_por_403]
 * Los marcados como no bloqueables son tokens que solo se usan en robots.txt
 * (no viajan como User-Agent real, p. ej. Google-Extended / Applebot-Extended).
 */
function wps_aibots_list(): array {
    return [
        'GPTBot'              => ['OpenAI GPTBot', true],
        'OAI-SearchBot'       => ['OpenAI SearchBot', true],
        'ChatGPT-User'        => ['ChatGPT-User', true],
        'ClaudeBot'           => ['Anthropic ClaudeBot', true],
        'anthropic-ai'        => ['Anthropic (anthropic-ai)', true],
        'Claude-Web'          => ['Claude-Web', true],
        'PerplexityBot'       => ['PerplexityBot', true],
        'CCBot'               => ['Common Crawl (CCBot)', true],
        'Amazonbot'           => ['Amazonbot', true],
        'Bytespider'          => ['ByteDance Bytespider', true],
        'meta-externalagent'  => ['Meta External Agent', true],
        'FacebookBot'         => ['FacebookBot', true],
        'Diffbot'             => ['Diffbot', true],
        'ImagesiftBot'        => ['ImagesiftBot', true],
        'Omgilibot'           => ['Omgilibot', true],
        'cohere-ai'           => ['cohere-ai', true],
        'YouBot'              => ['YouBot', true],
        'DuckAssistBot'       => ['DuckAssistBot', true],
        'Google-Extended'     => ['Google-Extended (solo robots.txt)', false],
        'Applebot-Extended'   => ['Applebot-Extended (solo robots.txt)', false],
    ];
}

/** Ajustes actuales (con valores por defecto). */
function wps_aibots_settings(): array {
    $s = get_option(WPS_AIBOTS_OPTION, []);
    if (!is_array($s)) $s = [];

    $all = array_keys(wps_aibots_list());
    // 'bots' = tokens seleccionados. Si nunca se ha guardado, por defecto: todos.
    $bots = (isset($s['bots']) && is_array($s['bots']))
        ? array_values(array_intersect($all, $s['bots']))
        : $all;

    return [
        'robots' => !empty($s['robots']),
        'block'  => !empty($s['block']),
        'bots'   => $bots,
    ];
}

/**
 * robots.txt: opt-out declarativo para los bots que lo respetan.
 * Solo funciona con el robots.txt VIRTUAL de WordPress (si existe un
 * robots.txt físico en la raíz, WordPress no aplica este filtro).
 */
add_filter('robots_txt', function ($output, $public) {
    $s = wps_aibots_settings();
    if (!$s['robots'] || empty($s['bots'])) return $output;

    $block = "\n# Bots de IA bloqueados por WP Profiler & Security\n";
    foreach ($s['bots'] as $ua) {
        $block .= "User-agent: {$ua}\n";
    }
    $block .= "Disallow: /\n";

    return $output . $block;
}, 10, 2);

/**
 * Señal de opt-out por cabecera en el front (además de robots.txt).
 */
add_action('send_headers', function () {
    if (is_admin()) return;
    $s = wps_aibots_settings();
    if ($s['robots'] || $s['block']) {
        header('X-Robots-Tag: noai, noimageai', false);
    }
});

/**
 * Bloqueo real por User-Agent: devuelve 403 a los bots de IA en el front.
 * El UA es falsificable, así que esto complementa (no sustituye) a robots.txt.
 */
add_action('init', function () {
    if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) return;

    $s = wps_aibots_settings();
    if (!$s['block'] || empty($s['bots'])) return;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua === '') return;

    $list = wps_aibots_list();
    foreach ($s['bots'] as $token) {
        if (empty($list[$token][1])) continue; // solo UAs reales bloqueables
        if (stripos($ua, $token) !== false) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // no cachear el 403
            nocache_headers();
            status_header(403);
            header('X-Robots-Tag: noai, noimageai', true);
            wp_die('403 Forbidden', '403 Forbidden', ['response' => 403]);
        }
    }
}, 1);

/**
 * Guardar ajustes de la sección.
 */
add_action('admin_post_wps_save_aibots', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes', 403);
    }
    check_admin_referer('wps_aibots_nonce');

    $posted = (isset($_POST['wps_aibots_bots']) && is_array($_POST['wps_aibots_bots']))
        ? array_map('sanitize_text_field', wp_unslash($_POST['wps_aibots_bots']))
        : [];
    $valid_bots = array_values(array_intersect(array_keys(wps_aibots_list()), $posted));

    update_option(WPS_AIBOTS_OPTION, [
        'robots' => isset($_POST['wps_aibots_robots']) ? 1 : 0,
        'block'  => isset($_POST['wps_aibots_block']) ? 1 : 0,
        'bots'   => $valid_bots,
    ]);

    wp_redirect(admin_url('admin.php?page=wp-profiler-aibots&saved=1'));
    exit;
});

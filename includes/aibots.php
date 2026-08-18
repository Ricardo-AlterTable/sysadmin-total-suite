<?php
// includes/aibots.php — Bloqueo de bots de IA (robots.txt + 403 por User-Agent).
if (!defined('ABSPATH')) exit;

if (!defined('STSUITE_AIBOTS_OPTION')) {
    define('STSUITE_AIBOTS_OPTION', 'stsuite_aibots_settings');
}

/**
 * Lista de bots de IA conocidos.
 * token => [etiqueta, bloqueable_por_403]
 * Los marcados como no bloqueables son tokens que solo se usan en robots.txt
 * (no viajan como User-Agent real, p. ej. Google-Extended / Applebot-Extended).
 */
function stsuite_aibots_list(): array {
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

/**
 * Ajustes actuales. 'blocked' = tokens bloqueados. Por defecto ninguno
 * (todos los bots quedan permitidos). Un bot bloqueado se añade a robots.txt
 * y, si su User-Agent es real, también se bloquea con 403.
 */
function stsuite_aibots_settings(): array {
    $s = get_option(STSUITE_AIBOTS_OPTION, []);
    if (!is_array($s)) $s = [];

    $all = array_keys(stsuite_aibots_list());
    $blocked = (isset($s['blocked']) && is_array($s['blocked']))
        ? array_values(array_intersect($all, $s['blocked']))
        : [];

    return ['blocked' => $blocked];
}

/**
 * robots.txt: opt-out declarativo para los bots que lo respetan.
 * Solo funciona con el robots.txt VIRTUAL de WordPress (si existe un
 * robots.txt físico en la raíz, WordPress no aplica este filtro).
 */
add_filter('robots_txt', function ($output, $public) {
    $blocked = stsuite_aibots_settings()['blocked'];
    if (empty($blocked)) return $output;

    $block = "\n# AI bots blocked by Sysadmin Total Suite\n";
    foreach ($blocked as $ua) {
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
    if (!empty(stsuite_aibots_settings()['blocked'])) {
        header('X-Robots-Tag: noai, noimageai', false);
    }
});

/**
 * Bloqueo real por User-Agent: devuelve 403 a los bots de IA en el front.
 * El UA es falsificable, así que esto complementa (no sustituye) a robots.txt.
 */
add_action('init', function () {
    if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) return;

    $blocked = stsuite_aibots_settings()['blocked'];
    if (empty($blocked)) return;

    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    if ($ua === '') return;

    $list = stsuite_aibots_list();
    foreach ($blocked as $token) {
        if (empty($list[$token][1])) continue; // solo UAs reales bloqueables
        if (stripos($ua, $token) !== false) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true); // no cachear el 403
            nocache_headers();
            status_header(403);
            header('X-Robots-Tag: noai, noimageai', true);
            wp_die(esc_html__('403 Forbidden', 'sysadmin-total-suite'), esc_html__('403 Forbidden', 'sysadmin-total-suite'), ['response' => 403]);
        }
    }
}, 1);

/**
 * Guardar ajustes de la sección.
 */
add_action('admin_post_stsuite_save_aibots', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
    }
    check_admin_referer('stsuite_aibots_nonce');

    $posted = (isset($_POST['stsuite_aibots_blocked']) && is_array($_POST['stsuite_aibots_blocked']))
        ? array_map('sanitize_text_field', wp_unslash($_POST['stsuite_aibots_blocked']))
        : [];
    $blocked = array_values(array_intersect(array_keys(stsuite_aibots_list()), $posted));

    update_option(STSUITE_AIBOTS_OPTION, ['blocked' => $blocked]);

    wp_safe_redirect(admin_url('admin.php?page=sysadmin-total-suite-aibots&saved=1'));
    exit;
});

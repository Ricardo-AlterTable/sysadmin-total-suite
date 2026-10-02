<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can(stsuite_audit_capability())) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_settings = stsuite_audit_settings();
$stsuite_results  = stsuite_audit_results();
$stsuite_done     = ($stsuite_results['status'] ?? '') === 'done' && !empty($stsuite_results['items']);
$stsuite_items    = $stsuite_done ? (array) $stsuite_results['items'] : [];
$stsuite_themes   = $stsuite_done ? (array) ($stsuite_results['themes'] ?? []) : [];
$stsuite_core     = $stsuite_done ? ($stsuite_results['core'] ?? null) : null;
$stsuite_scanned_vulns = !empty($stsuite_results['vuln_enabled']);

// Versiones instaladas AHORA, para avisar si algo cambió desde el escaneo.
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$stsuite_installed        = get_plugins();
$stsuite_installed_themes = wp_get_themes();

$stsuite_severity_labels = [
    'critical' => __('Critical', 'sysadmin-total-suite'),
    'high'     => __('High', 'sysadmin-total-suite'),
    'medium'   => __('Medium', 'sysadmin-total-suite'),
    'low'      => __('Low', 'sysadmin-total-suite'),
    'none'     => __('None', 'sysadmin-total-suite'),
    ''         => __('Unrated', 'sysadmin-total-suite'),
];

/**
 * Resumen y orden de un grupo (plugins o temas): vulnerables, cerrados,
 * abandonados, con actualización y resto.
 */
$stsuite_rank = function (array &$stsuite_list) {
    $stsuite_sum = ['total' => 0, 'updates' => 0, 'vulnerable' => 0, 'closed' => 0, 'stale' => 0, 'not_found' => 0];
    foreach ($stsuite_list as $stsuite_key => $stsuite_item) {
        $stsuite_vcount = count($stsuite_item['vulns']['items'] ?? []);
        $stsuite_status = $stsuite_item['wporg']['status'] ?? '';
        $stsuite_stale  = stsuite_audit_is_stale($stsuite_item);
        $stsuite_sum['total']++;
        if ($stsuite_item['new_version'] !== '') $stsuite_sum['updates']++;
        if ($stsuite_vcount > 0) $stsuite_sum['vulnerable']++;
        if ($stsuite_status === 'closed') $stsuite_sum['closed']++;
        if ($stsuite_status === 'not_found') $stsuite_sum['not_found']++;
        if ($stsuite_stale) $stsuite_sum['stale']++;
        $stsuite_list[$stsuite_key]['_rank'] = $stsuite_vcount > 0 ? 0 : ($stsuite_status === 'closed' ? 1 : ($stsuite_stale ? 2 : ($stsuite_item['new_version'] !== '' ? 3 : 4)));
    }
    uasort($stsuite_list, function ($stsuite_a, $stsuite_b) {
        return [$stsuite_a['_rank'], strtolower($stsuite_a['name'])] <=> [$stsuite_b['_rank'], strtolower($stsuite_b['name'])];
    });
    return $stsuite_sum;
};
$stsuite_summary       = $stsuite_rank($stsuite_items);
$stsuite_theme_summary = $stsuite_rank($stsuite_themes);
$stsuite_core_vulns    = is_array($stsuite_core) ? count($stsuite_core['vulns']['items'] ?? []) : 0;

/** Celda "Actualización". */
$stsuite_render_update = function (array $stsuite_item) {
    if ($stsuite_item['new_version'] !== '') {
        echo '<span class="stsuite-badge warn">→ ' . esc_html($stsuite_item['new_version']) . '</span>';
    } elseif (($stsuite_item['wporg']['status'] ?? '') === 'ok') {
        echo '<span class="stsuite-ok-text">' . esc_html__('Up to date', 'sysadmin-total-suite') . '</span>';
    } else {
        echo '<span class="stsuite-muted">—</span>';
    }
};

/** Celda "WordPress.org". */
$stsuite_render_wporg = function (array $stsuite_item) {
    $stsuite_wporg = (array) ($stsuite_item['wporg'] ?? []);
    switch ($stsuite_wporg['status'] ?? '') {
        case 'ok':
            if (!empty($stsuite_wporg['last_updated'])) {
                /* translators: %s: human-readable time difference, e.g. "3 months". */
                printf(esc_html__('Updated %s ago', 'sysadmin-total-suite'), esc_html(human_time_diff((int) $stsuite_wporg['last_updated'])));
                if (stsuite_audit_is_stale($stsuite_item)) {
                    echo ' <span class="stsuite-badge warn">' . esc_html__('Possibly abandoned', 'sysadmin-total-suite') . '</span>';
                }
            }
            if (!empty($stsuite_wporg['tested'])) {
                /* translators: %s: WordPress version. */
                echo '<br><span class="stsuite-muted">' . esc_html(sprintf(__('Tested up to %s', 'sysadmin-total-suite'), $stsuite_wporg['tested'])) . '</span>';
            }
            break;
        case 'closed':
            echo '<span class="stsuite-badge bad">' . esc_html__('Closed', 'sysadmin-total-suite') . '</span>';
            echo '<br><span class="stsuite-muted">' . esc_html(trim(($stsuite_wporg['closed_date'] ?? '') . ' · ' . ($stsuite_wporg['closed_reason'] ?? ''), ' ·')) . '</span>';
            break;
        case 'not_found':
            echo '<span class="stsuite-muted">' . esc_html__('Not in the directory (premium or custom)', 'sysadmin-total-suite') . '</span>';
            break;
        case 'error':
            echo '<span class="stsuite-num-warn">' . esc_html__('Could not be checked', 'sysadmin-total-suite') . '</span>';
            break;
        default:
            echo '<span class="stsuite-muted">—</span>';
    }
};

/** Celda "Vulnerabilidades conocidas". */
$stsuite_render_vulns = function ($stsuite_vulns) use ($stsuite_scanned_vulns, $stsuite_severity_labels) {
    if (!$stsuite_scanned_vulns) {
        echo '<span class="stsuite-muted">' . esc_html__('Not checked', 'sysadmin-total-suite') . '</span>';
        return;
    }
    if (($stsuite_vulns['status'] ?? '') !== 'ok') {
        echo '<span class="stsuite-num-warn">' . esc_html__('Could not be checked', 'sysadmin-total-suite') . '</span>';
        return;
    }
    if (empty($stsuite_vulns['items'])) {
        echo '<span class="stsuite-ok-text">' . esc_html__('None known', 'sysadmin-total-suite') . '</span>';
        return;
    }
    echo '<ul class="stsuite-vuln-list">';
    foreach ($stsuite_vulns['items'] as $stsuite_v) {
        $stsuite_sev = $stsuite_v['severity'] !== '' ? $stsuite_v['severity'] : 'unrated';
        echo '<li><span class="stsuite-sev stsuite-sev--' . esc_attr($stsuite_sev) . '">' . esc_html($stsuite_severity_labels[$stsuite_v['severity']] ?? $stsuite_severity_labels['']);
        if ($stsuite_v['score'] !== '') echo ' ' . esc_html($stsuite_v['score']);
        echo '</span> ' . esc_html($stsuite_v['title']);
        if (!empty($stsuite_v['unfixed'])) {
            echo ' <span class="stsuite-tag stsuite-tag--danger">' . esc_html__('No fix available', 'sysadmin-total-suite') . '</span>';
        }
        if (!empty($stsuite_v['sources'])) {
            echo '<br>';
            foreach ($stsuite_v['sources'] as $stsuite_src) {
                $stsuite_label = ($stsuite_src['id'] !== '' && strpos($stsuite_src['id'], 'CVE-') === 0) ? $stsuite_src['id'] : (string) wp_parse_url($stsuite_src['link'], PHP_URL_HOST);
                echo '<a href="' . esc_url($stsuite_src['link']) . '" target="_blank" rel="noopener noreferrer" class="stsuite-vuln-src">' . esc_html($stsuite_label) . '</a>';
            }
        }
        echo '</li>';
    }
    echo '</ul>';
};

/** Insignias de resumen de un grupo. */
$stsuite_render_badges = function (array $stsuite_sum) use ($stsuite_scanned_vulns) {
    echo '<span class="stsuite-badge ' . ($stsuite_sum['updates'] ? 'warn' : 'ok') . '">';
    /* translators: %d: number of items. */
    echo esc_html(sprintf(_n('%d with an update', '%d with updates', $stsuite_sum['updates'], 'sysadmin-total-suite'), $stsuite_sum['updates'])) . '</span>';
    if ($stsuite_scanned_vulns) {
        echo '<span class="stsuite-badge ' . ($stsuite_sum['vulnerable'] ? 'bad' : 'ok') . '">';
        /* translators: %d: number of items. */
        echo esc_html(sprintf(_n('%d vulnerable', '%d vulnerable', $stsuite_sum['vulnerable'], 'sysadmin-total-suite'), $stsuite_sum['vulnerable'])) . '</span>';
    }
    echo '<span class="stsuite-badge ' . ($stsuite_sum['closed'] ? 'bad' : 'ok') . '">';
    /* translators: %d: number of items. */
    echo esc_html(sprintf(_n('%d closed', '%d closed', $stsuite_sum['closed'], 'sysadmin-total-suite'), $stsuite_sum['closed'])) . '</span>';
    echo '<span class="stsuite-badge ' . ($stsuite_sum['stale'] ? 'warn' : 'ok') . '">';
    /* translators: %d: number of items. */
    echo esc_html(sprintf(_n('%d possibly abandoned', '%d possibly abandoned', $stsuite_sum['stale'], 'sysadmin-total-suite'), $stsuite_sum['stale'])) . '</span>';
    if ($stsuite_sum['not_found']) {
        echo '<span class="stsuite-badge warn">';
        /* translators: %d: number of items. */
        echo esc_html(sprintf(_n('%d not in the directory', '%d not in the directory', $stsuite_sum['not_found'], 'sysadmin-total-suite'), $stsuite_sum['not_found'])) . '</span>';
    }
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Plugin, theme and core audit', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Checks WordPress, the installed plugins and the installed themes for pending updates, items closed or abandoned on WordPress.org and, optionally, publicly known vulnerabilities.', 'sysadmin-total-suite'); ?></p>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para avisos; no modifica estado. */ if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sysadmin-total-suite'); ?></p></div>
    <?php endif; ?>

    <!-- Consentimiento para el servicio externo de vulnerabilidades -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Vulnerability database', 'sysadmin-total-suite'); ?></h2>
        <?php if ($stsuite_settings['needs_reconsent']): ?>
            <p class="stsuite-status stsuite-status--warn"><?php esc_html_e('The vulnerability check now also covers themes and the WordPress core, so it sends more data than when you enabled it. It is paused until you review the text below and enable it again.', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('stsuite_audit_settings_nonce'); ?>
            <input type="hidden" name="action" value="stsuite_save_audit">
            <p>
                <label>
                    <input type="checkbox" name="stsuite_audit_vuln_enabled" value="1" <?php checked($stsuite_settings['vuln_enabled']); ?>>
                    <?php esc_html_e('Check WordPress, plugins and themes against the WPVulnerability database', 'sysadmin-total-suite'); ?>
                </label>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: link to the WPVulnerability website, 2: link to its privacy policy. */
                    esc_html__('When enabled, each scan sends to %1$s, a free external service, the slug of every installed plugin and theme (not their versions: that comparison is done on your site) and your WordPress version, because the service can only look up the core by version. Your site address is never sent. See its %2$s.', 'sysadmin-total-suite'),
                    '<a href="https://www.wpvulnerability.com/" target="_blank" rel="noopener noreferrer">WPVulnerability</a>',
                    '<a href="https://www.wpvulnerability.com/privacy/" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'sysadmin-total-suite') . '</a>'
                );
                ?>
            </p>
            <p><button type="submit" class="button button-secondary"><?php esc_html_e('Save', 'sysadmin-total-suite'); ?></button></p>
        </form>
    </div>

    <div class="stsuite-actions">
        <button class="button button-primary stsuite-audit-scan" data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_audit')); ?>"><?php esc_html_e('Scan now', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteAuditProgress" class="stsuite-muted" aria-live="polite"></span>
    </div>

    <?php if (!$stsuite_done): ?>
        <p><?php /* translators: %s: the button label in bold. */ printf(esc_html__('No scan available. Run %s to get results.', 'sysadmin-total-suite'), '<strong>' . esc_html__('Scan now', 'sysadmin-total-suite') . '</strong>'); ?></p>
    <?php else: ?>

        <p class="stsuite-kv">
            <?php
            printf(
                /* translators: %s: date and time of the last scan. */
                esc_html__('Last scan: %s', 'sysadmin-total-suite'),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $stsuite_results['checked_at']))
            );
            ?>
        </p>

        <?php if ($stsuite_summary['updates'] || $stsuite_theme_summary['updates'] || $stsuite_summary['vulnerable'] || $stsuite_theme_summary['vulnerable'] || $stsuite_core_vulns || !empty($stsuite_core['new_version'])): ?>
            <p class="stsuite-kv">
                <?php
                printf(
                    /* translators: %s: link to the WordPress updates screen. */
                    esc_html__('Install pending updates from %s.', 'sysadmin-total-suite'),
                    '<a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html__('Dashboard → Updates', 'sysadmin-total-suite') . '</a>'
                );
                ?>
            </p>
        <?php endif; ?>

        <?php if (is_array($stsuite_core)): ?>
            <!-- Core -->
            <div class="stsuite-card">
                <h2><?php esc_html_e('WordPress core', 'sysadmin-total-suite'); ?></h2>
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Version', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Update', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Known vulnerabilities', 'sysadmin-total-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong>WordPress <?php echo esc_html($stsuite_core['version']); ?></strong>
                                <?php if (get_bloginfo('version') !== $stsuite_core['version']): ?>
                                    <br><span class="stsuite-num-warn"><?php /* translators: %s: currently installed version. */ printf(esc_html__('Now %s: scan again to refresh.', 'sysadmin-total-suite'), esc_html(get_bloginfo('version'))); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($stsuite_core['new_version'])): ?>
                                    <span class="stsuite-badge warn">→ <?php echo esc_html($stsuite_core['new_version']); ?></span>
                                <?php else: ?>
                                    <span class="stsuite-ok-text"><?php esc_html_e('Up to date', 'sysadmin-total-suite'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php $stsuite_render_vulns($stsuite_core['vulns'] ?? null); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Plugins -->
        <div class="stsuite-card">
            <h2><?php esc_html_e('Plugins', 'sysadmin-total-suite'); ?> · <?php echo (int) $stsuite_summary['total']; ?></h2>
            <p class="stsuite-kv"><?php $stsuite_render_badges($stsuite_summary); ?></p>
            <table class="stsuite-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Plugin', 'sysadmin-total-suite'); ?></th>
                        <th><?php esc_html_e('Update', 'sysadmin-total-suite'); ?></th>
                        <th><?php esc_html_e('WordPress.org', 'sysadmin-total-suite'); ?></th>
                        <th><?php esc_html_e('Known vulnerabilities', 'sysadmin-total-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stsuite_items as $stsuite_file => $stsuite_item): ?>
                        <?php $stsuite_now_ver = $stsuite_installed[$stsuite_file]['Version'] ?? null; ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($stsuite_item['name']); ?></strong>
                                <br><span class="stsuite-muted"><?php echo esc_html($stsuite_item['version']); ?></span>
                                <?php if ($stsuite_item['active']): ?>
                                    <span class="stsuite-tag stsuite-tag--ok"><?php esc_html_e('Active', 'sysadmin-total-suite'); ?></span>
                                <?php else: ?>
                                    <span class="stsuite-tag stsuite-tag--muted"><?php esc_html_e('Inactive', 'sysadmin-total-suite'); ?></span>
                                <?php endif; ?>
                                <?php if ($stsuite_now_ver === null): ?>
                                    <br><span class="stsuite-num-warn"><?php esc_html_e('Removed since the last scan.', 'sysadmin-total-suite'); ?></span>
                                <?php elseif ($stsuite_now_ver !== $stsuite_item['version']): ?>
                                    <br><span class="stsuite-num-warn"><?php /* translators: %s: currently installed version. */ printf(esc_html__('Now %s: scan again to refresh.', 'sysadmin-total-suite'), esc_html($stsuite_now_ver)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php $stsuite_render_update($stsuite_item); ?></td>
                            <td><?php $stsuite_render_wporg($stsuite_item); ?></td>
                            <td><?php $stsuite_render_vulns($stsuite_item['vulns'] ?? null); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($stsuite_themes)): ?>
            <!-- Temas -->
            <div class="stsuite-card">
                <h2><?php esc_html_e('Themes', 'sysadmin-total-suite'); ?> · <?php echo (int) $stsuite_theme_summary['total']; ?></h2>
                <p class="stsuite-kv"><?php $stsuite_render_badges($stsuite_theme_summary); ?></p>
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Theme', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Update', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('WordPress.org', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Known vulnerabilities', 'sysadmin-total-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stsuite_themes as $stsuite_slug => $stsuite_item): ?>
                            <?php $stsuite_now_theme = $stsuite_installed_themes[$stsuite_slug] ?? null; ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($stsuite_item['name']); ?></strong>
                                    <br><span class="stsuite-muted"><?php echo esc_html($stsuite_item['version']); ?></span>
                                    <?php if ($stsuite_item['state'] === 'active'): ?>
                                        <span class="stsuite-tag stsuite-tag--ok"><?php esc_html_e('Active', 'sysadmin-total-suite'); ?></span>
                                    <?php elseif ($stsuite_item['state'] === 'parent'): ?>
                                        <span class="stsuite-tag stsuite-tag--ok"><?php esc_html_e('Parent theme', 'sysadmin-total-suite'); ?></span>
                                    <?php else: ?>
                                        <span class="stsuite-tag stsuite-tag--muted"><?php esc_html_e('Inactive', 'sysadmin-total-suite'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($stsuite_now_theme === null): ?>
                                        <br><span class="stsuite-num-warn"><?php esc_html_e('Removed since the last scan.', 'sysadmin-total-suite'); ?></span>
                                    <?php elseif ((string) $stsuite_now_theme->get('Version') !== $stsuite_item['version']): ?>
                                        <br><span class="stsuite-num-warn"><?php /* translators: %s: currently installed version. */ printf(esc_html__('Now %s: scan again to refresh.', 'sysadmin-total-suite'), esc_html((string) $stsuite_now_theme->get('Version'))); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php $stsuite_render_update($stsuite_item); ?></td>
                                <td><?php $stsuite_render_wporg($stsuite_item); ?></td>
                                <td><?php $stsuite_render_vulns($stsuite_item['vulns'] ?? null); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e('Inactive themes are not loaded, but their files are still on the server: if you do not need them, delete them from Appearance → Themes (keep one default theme as a fallback).', 'sysadmin-total-suite'); ?></p>
            </div>
        <?php endif; ?>

        <p class="description">
            <?php
            printf(
                /* translators: %d: number of years. */
                esc_html__('"Possibly abandoned" means no release on WordPress.org in more than %d years. Items that are not in the directory cannot be checked for updates or closure. The absence of known vulnerabilities does not guarantee that something is secure.', 'sysadmin-total-suite'),
                (int) STSUITE_AUDIT_STALE_YEARS
            );
            ?>
        </p>
    <?php endif; ?>
</div>

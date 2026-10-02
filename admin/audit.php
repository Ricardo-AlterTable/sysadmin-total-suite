<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can(stsuite_audit_capability())) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_settings = stsuite_audit_settings();
$stsuite_results  = stsuite_audit_results();
$stsuite_items    = (($stsuite_results['status'] ?? '') === 'done' && !empty($stsuite_results['items'])) ? $stsuite_results['items'] : [];
$stsuite_scanned_vulns = !empty($stsuite_results['vuln_enabled']);

// Versiones instaladas AHORA, para avisar si algo cambió desde el escaneo.
if (!function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$stsuite_installed = get_plugins();

$stsuite_severity_labels = [
    'critical' => __('Critical', 'sysadmin-total-suite'),
    'high'     => __('High', 'sysadmin-total-suite'),
    'medium'   => __('Medium', 'sysadmin-total-suite'),
    'low'      => __('Low', 'sysadmin-total-suite'),
    'none'     => __('None', 'sysadmin-total-suite'),
    ''         => __('Unrated', 'sysadmin-total-suite'),
];

// Resumen y orden: vulnerables, cerrados, abandonados, con actualización, resto.
$stsuite_summary = ['total' => 0, 'updates' => 0, 'vulnerable' => 0, 'closed' => 0, 'stale' => 0, 'not_found' => 0];
foreach ($stsuite_items as $stsuite_file => $stsuite_item) {
    $stsuite_vcount = count($stsuite_item['vulns']['items'] ?? []);
    $stsuite_status = $stsuite_item['wporg']['status'] ?? '';
    $stsuite_stale  = stsuite_audit_is_stale($stsuite_item);

    $stsuite_summary['total']++;
    if ($stsuite_item['new_version'] !== '') $stsuite_summary['updates']++;
    if ($stsuite_vcount > 0) $stsuite_summary['vulnerable']++;
    if ($stsuite_status === 'closed') $stsuite_summary['closed']++;
    if ($stsuite_status === 'not_found') $stsuite_summary['not_found']++;
    if ($stsuite_stale) $stsuite_summary['stale']++;

    $stsuite_items[$stsuite_file]['_rank'] = $stsuite_vcount > 0 ? 0 : ($stsuite_status === 'closed' ? 1 : ($stsuite_stale ? 2 : ($stsuite_item['new_version'] !== '' ? 3 : 4)));
}
uasort($stsuite_items, function ($stsuite_a, $stsuite_b) {
    return [$stsuite_a['_rank'], strtolower($stsuite_a['name'])] <=> [$stsuite_b['_rank'], strtolower($stsuite_b['name'])];
});
?>
<div class="wrap">
    <h1><?php esc_html_e('Plugin audit', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Checks the installed plugins for pending updates, plugins closed or abandoned on WordPress.org and, optionally, publicly known vulnerabilities.', 'sysadmin-total-suite'); ?></p>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para avisos; no modifica estado. */ if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sysadmin-total-suite'); ?></p></div>
    <?php endif; ?>

    <!-- Consentimiento para el servicio externo de vulnerabilidades -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Vulnerability database', 'sysadmin-total-suite'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('stsuite_audit_settings_nonce'); ?>
            <input type="hidden" name="action" value="stsuite_save_audit">
            <p>
                <label>
                    <input type="checkbox" name="stsuite_audit_vuln_enabled" value="1" <?php checked($stsuite_settings['vuln_enabled']); ?>>
                    <?php esc_html_e('Check installed plugins against the WPVulnerability database', 'sysadmin-total-suite'); ?>
                </label>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: 1: link to the WPVulnerability website, 2: link to its privacy policy. */
                    esc_html__('When enabled, each scan sends the slug of every installed plugin (not its version, nor your site address) to %1$s, a free external service, to look up known vulnerabilities. The version comparison is done on your site. See its %2$s.', 'sysadmin-total-suite'),
                    '<a href="https://www.wpvulnerability.com/" target="_blank" rel="noopener noreferrer">WPVulnerability</a>',
                    '<a href="https://www.wpvulnerability.com/privacy/" target="_blank" rel="noopener noreferrer">' . esc_html__('privacy policy', 'sysadmin-total-suite') . '</a>'
                );
                ?>
            </p>
            <p><button type="submit" class="button button-secondary"><?php esc_html_e('Save', 'sysadmin-total-suite'); ?></button></p>
        </form>
    </div>

    <div class="stsuite-actions">
        <button class="button button-primary stsuite-audit-scan" data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_audit')); ?>"><?php esc_html_e('Scan plugins', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteAuditProgress" class="stsuite-muted" aria-live="polite"></span>
    </div>

    <?php if (empty($stsuite_items)): ?>
        <p><?php /* translators: %s: the "Scan plugins" button label in bold. */ printf(esc_html__('No scan available. Run %s to get results.', 'sysadmin-total-suite'), '<strong>' . esc_html__('Scan plugins', 'sysadmin-total-suite') . '</strong>'); ?></p>
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

        <p class="stsuite-kv">
            <?php esc_html_e('Plugins:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_summary['total']; ?></strong>
            <span class="stsuite-badge <?php echo $stsuite_summary['updates'] ? 'warn' : 'ok'; ?>">
                <?php /* translators: %d: number of plugins. */ echo esc_html(sprintf(_n('%d with an update', '%d with updates', $stsuite_summary['updates'], 'sysadmin-total-suite'), $stsuite_summary['updates'])); ?>
            </span>
            <?php if ($stsuite_scanned_vulns): ?>
                <span class="stsuite-badge <?php echo $stsuite_summary['vulnerable'] ? 'bad' : 'ok'; ?>">
                    <?php /* translators: %d: number of plugins. */ echo esc_html(sprintf(_n('%d vulnerable', '%d vulnerable', $stsuite_summary['vulnerable'], 'sysadmin-total-suite'), $stsuite_summary['vulnerable'])); ?>
                </span>
            <?php endif; ?>
            <span class="stsuite-badge <?php echo $stsuite_summary['closed'] ? 'bad' : 'ok'; ?>">
                <?php /* translators: %d: number of plugins. */ echo esc_html(sprintf(_n('%d closed', '%d closed', $stsuite_summary['closed'], 'sysadmin-total-suite'), $stsuite_summary['closed'])); ?>
            </span>
            <span class="stsuite-badge <?php echo $stsuite_summary['stale'] ? 'warn' : 'ok'; ?>">
                <?php /* translators: %d: number of plugins. */ echo esc_html(sprintf(_n('%d possibly abandoned', '%d possibly abandoned', $stsuite_summary['stale'], 'sysadmin-total-suite'), $stsuite_summary['stale'])); ?>
            </span>
            <?php if ($stsuite_summary['not_found']): ?>
                <span class="stsuite-badge warn">
                    <?php /* translators: %d: number of plugins. */ echo esc_html(sprintf(_n('%d not in the directory', '%d not in the directory', $stsuite_summary['not_found'], 'sysadmin-total-suite'), $stsuite_summary['not_found'])); ?>
                </span>
            <?php endif; ?>
        </p>

        <?php if ($stsuite_summary['updates'] || $stsuite_summary['vulnerable']): ?>
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
                    <?php
                    $stsuite_wporg   = (array) ($stsuite_item['wporg'] ?? []);
                    $stsuite_now_ver = $stsuite_installed[$stsuite_file]['Version'] ?? null;
                    ?>
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
                                <br><span class="stsuite-num-warn">
                                    <?php /* translators: %s: currently installed version. */ printf(esc_html__('Now %s: scan again to refresh.', 'sysadmin-total-suite'), esc_html($stsuite_now_ver)); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($stsuite_item['new_version'] !== ''): ?>
                                <span class="stsuite-badge warn">→ <?php echo esc_html($stsuite_item['new_version']); ?></span>
                            <?php elseif (($stsuite_wporg['status'] ?? '') === 'ok'): ?>
                                <span class="stsuite-ok-text"><?php esc_html_e('Up to date', 'sysadmin-total-suite'); ?></span>
                            <?php else: ?>
                                <span class="stsuite-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php switch ($stsuite_wporg['status'] ?? ''):
                                case 'ok': ?>
                                    <?php if (!empty($stsuite_wporg['last_updated'])): ?>
                                        <?php /* translators: %s: human-readable time difference, e.g. "3 months". */ printf(esc_html__('Updated %s ago', 'sysadmin-total-suite'), esc_html(human_time_diff((int) $stsuite_wporg['last_updated']))); ?>
                                        <?php if (stsuite_audit_is_stale($stsuite_item)): ?>
                                            <span class="stsuite-badge warn"><?php esc_html_e('Possibly abandoned', 'sysadmin-total-suite'); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($stsuite_wporg['tested'])): ?>
                                        <br><span class="stsuite-muted"><?php /* translators: %s: WordPress version. */ printf(esc_html__('Tested up to %s', 'sysadmin-total-suite'), esc_html($stsuite_wporg['tested'])); ?></span>
                                    <?php endif; ?>
                                    <?php break;
                                case 'closed': ?>
                                    <span class="stsuite-badge bad"><?php esc_html_e('Closed', 'sysadmin-total-suite'); ?></span>
                                    <br><span class="stsuite-muted">
                                        <?php echo esc_html(trim(($stsuite_wporg['closed_date'] ?? '') . ' · ' . ($stsuite_wporg['closed_reason'] ?? ''), ' ·')); ?>
                                    </span>
                                    <?php break;
                                case 'not_found': ?>
                                    <span class="stsuite-muted"><?php esc_html_e('Not in the directory (premium or custom plugin)', 'sysadmin-total-suite'); ?></span>
                                    <?php break;
                                case 'error': ?>
                                    <span class="stsuite-num-warn"><?php esc_html_e('Could not be checked', 'sysadmin-total-suite'); ?></span>
                                    <?php break;
                                default: ?>
                                    <span class="stsuite-muted">—</span>
                            <?php endswitch; ?>
                        </td>

                        <td>
                            <?php if (!$stsuite_scanned_vulns): ?>
                                <span class="stsuite-muted"><?php esc_html_e('Not checked', 'sysadmin-total-suite'); ?></span>
                            <?php elseif (($stsuite_item['vulns']['status'] ?? '') !== 'ok'): ?>
                                <span class="stsuite-num-warn"><?php esc_html_e('Could not be checked', 'sysadmin-total-suite'); ?></span>
                            <?php elseif (empty($stsuite_item['vulns']['items'])): ?>
                                <span class="stsuite-ok-text"><?php esc_html_e('None known', 'sysadmin-total-suite'); ?></span>
                            <?php else: ?>
                                <ul class="stsuite-vuln-list">
                                    <?php foreach ($stsuite_item['vulns']['items'] as $stsuite_v): ?>
                                        <li>
                                            <span class="stsuite-sev stsuite-sev--<?php echo esc_attr($stsuite_v['severity'] !== '' ? $stsuite_v['severity'] : 'unrated'); ?>">
                                                <?php echo esc_html($stsuite_severity_labels[$stsuite_v['severity']] ?? $stsuite_severity_labels['']); ?><?php if ($stsuite_v['score'] !== ''): ?> <?php echo esc_html($stsuite_v['score']); ?><?php endif; ?>
                                            </span>
                                            <?php echo esc_html($stsuite_v['title']); ?>
                                            <?php if ($stsuite_v['unfixed']): ?>
                                                <span class="stsuite-tag stsuite-tag--danger"><?php esc_html_e('No fix available', 'sysadmin-total-suite'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($stsuite_v['sources'])): ?>
                                                <br>
                                                <?php foreach ($stsuite_v['sources'] as $stsuite_src): ?>
                                                    <a href="<?php echo esc_url($stsuite_src['link']); ?>" target="_blank" rel="noopener noreferrer" class="stsuite-vuln-src"><?php echo esc_html($stsuite_src['id'] !== '' && strpos($stsuite_src['id'], 'CVE-') === 0 ? $stsuite_src['id'] : (string) wp_parse_url($stsuite_src['link'], PHP_URL_HOST)); ?></a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description">
            <?php
            printf(
                /* translators: %d: number of years. */
                esc_html__('"Possibly abandoned" means no release on WordPress.org in more than %d years. Plugins that are not in the directory cannot be checked for updates or closure. The absence of known vulnerabilities does not guarantee that a plugin is secure.', 'sysadmin-total-suite'),
                (int) STSUITE_AUDIT_STALE_YEARS
            );
            ?>
        </p>
    <?php endif; ?>
</div>

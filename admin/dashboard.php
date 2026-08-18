<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_analysis = get_transient('stsuite_last_analysis');

?>
<div class="wrap">
    <h1><?php esc_html_e('Core integrity', 'sysadmin-total-suite'); ?></h1>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['cache_purged']) && $_GET['cache_purged'] === '1'): ?>
        <div id="message" class="updated notice is-dismissible">
            <p><?php esc_html_e('Plugin cache purged. The next analysis will start from scratch.', 'sysadmin-total-suite'); ?></p>
        </div>
    <?php endif; ?>

    <div class="stsuite-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('stsuite_run_analysis_nonce'); ?>
            <input type="hidden" name="action" value="stsuite_run_analysis">
            <button type="submit" class="button button-primary"><?php esc_html_e('Analyze now', 'sysadmin-total-suite'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('stsuite_purge_cache_nonce', 'stsuite_purge_cache'); ?>
            <input type="hidden" name="action" value="stsuite_purge_cache">
            <button type="submit" class="button button-secondary"><?php esc_html_e('Purge cache', 'sysadmin-total-suite'); ?></button>
        </form>
    </div>

    <?php if ($stsuite_analysis): ?>
        <?php
        // Clasificar errores en modificados / faltantes / extras
        $stsuite_modificados = [];
        $stsuite_faltantes = [];
        $stsuite_extras = [];

        foreach ($stsuite_analysis['errors'] as $stsuite_err) {
            if (strpos($stsuite_err, 'Modified:') === 0) {
                $stsuite_modificados[] = preg_replace('/^Modified:\s*/', '', $stsuite_err);
            } elseif (strpos($stsuite_err, 'Missing:') === 0) {
                $stsuite_faltantes[] = preg_replace('/^Missing:\s*/', '', $stsuite_err);
            } elseif (strpos($stsuite_err, 'Extra:') === 0) {
                $stsuite_extras[] = preg_replace('/^Extra:\s*/', '', $stsuite_err);
            }
        }

        // Los "problemas del core" son solo modificados/faltantes; los extras
        // (archivos no reconocidos) se informan por separado.
        $stsuite_core_issues = count($stsuite_modificados) + count($stsuite_faltantes);
        ?>

        <h2><?php esc_html_e('Result', 'sysadmin-total-suite'); ?></h2>
        <?php if ($stsuite_core_issues > 0): ?>
            <p class="stsuite-status stsuite-status--bad"><?php esc_html_e('⚠ Issues were detected in the WordPress core', 'sysadmin-total-suite'); ?></p>
        <?php else: ?>
            <p class="stsuite-status stsuite-status--ok"><?php esc_html_e('✔ No issues were detected in the WordPress core', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>
        <?php if (!empty($stsuite_extras)): ?>
            <p class="stsuite-status stsuite-status--warn"><?php esc_html_e('⚠ Files not recognized by WordPress were detected', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>

        <?php if ($stsuite_core_issues > 0): ?>
            <p class="stsuite-kv">
                <?php
                printf(
                    /* translators: %s: link to the WordPress core reinstall screen. */
                    esc_html__('To repair the core, use %s, which is WordPress\'s own mechanism and replaces the original files safely.', 'sysadmin-total-suite'),
                    '<a href="' . esc_url(admin_url('update-core.php')) . '">' . esc_html__('Dashboard → Updates → Reinstall now', 'sysadmin-total-suite') . '</a>'
                );
                ?>
            </p>
        <?php endif; ?>

        <?php

        // Pagination for modified/missing files
        if (!empty($stsuite_modificados) || !empty($stsuite_faltantes)) {
            $stsuite_all_files = array_merge(
                array_map(function($stsuite_f) { return ['type' => 'Modified', 'path' => $stsuite_f]; }, $stsuite_modificados),
                array_map(function($stsuite_f) { return ['type' => 'Missing', 'path' => $stsuite_f]; }, $stsuite_faltantes)
            );

            $stsuite_per_page = isset($_GET['per_page']) && in_array((string) $_GET['per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['per_page'])) : 20;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $stsuite_current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $stsuite_total_items = count($stsuite_all_files);

            if ($stsuite_per_page === 'all') {
                $stsuite_paged_files = $stsuite_all_files;
                $stsuite_total_pages = 1;
            } else {
                $stsuite_per_page_int = intval($stsuite_per_page);
                $stsuite_total_pages = ceil($stsuite_total_items / $stsuite_per_page_int);
                $stsuite_current_page = min($stsuite_current_page, $stsuite_total_pages);
                $stsuite_paged_files = array_slice($stsuite_all_files, ($stsuite_current_page - 1) * $stsuite_per_page_int, $stsuite_per_page_int);
            }
        }

        // Pagination for extra files
        if (!empty($stsuite_extras)) {
            $stsuite_extras_per_page = isset($_GET['extras_per_page']) && in_array((string) $_GET['extras_per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['extras_per_page'])) : 20;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $stsuite_extras_current_page = isset($_GET['extras_paged']) ? max(1, intval($_GET['extras_paged'])) : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $stsuite_extras_total_items = count($stsuite_extras);

            if ($stsuite_extras_per_page === 'all') {
                $stsuite_paged_extras = $stsuite_extras;
                $stsuite_extras_total_pages = 1;
            } else {
                $stsuite_extras_per_page_int = intval($stsuite_extras_per_page);
                $stsuite_extras_total_pages = ceil($stsuite_extras_total_items / $stsuite_extras_per_page_int);
                $stsuite_extras_current_page = min($stsuite_extras_current_page, $stsuite_extras_total_pages);
                $stsuite_paged_extras = array_slice($stsuite_extras, ($stsuite_extras_current_page - 1) * $stsuite_extras_per_page_int, $stsuite_extras_per_page_int);
            }
        }
        ?>

        <?php if (!empty($stsuite_paged_files)): ?>
            <h2><?php esc_html_e('Modified / missing files', 'sysadmin-total-suite'); ?></h2>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($stsuite_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($stsuite_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($stsuite_per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($stsuite_per_page, 'all'); ?>><?php esc_html_e('All', 'sysadmin-total-suite'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'sysadmin-total-suite'); ?>">
                    </form>
                </div>
                <?php if ($stsuite_per_page !== 'all' && $stsuite_total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php /* translators: %s: number of items. */ printf(esc_html(_n('%s item', '%s items', $stsuite_total_items, 'sysadmin-total-suite')), esc_html(number_format_i18n($stsuite_total_items))); ?></span>
                    <span class="pagination-links">
                        <?php echo wp_kses_post(paginate_links(['total' => $stsuite_total_pages, 'current' => $stsuite_current_page])); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="stsuite-list">
                <?php foreach ($stsuite_paged_files as $stsuite_file): ?>
                    <li>
                        <?php if ($stsuite_file['type'] === 'Modified'): ?>
                            <span class="stsuite-tag stsuite-tag--danger"><?php esc_html_e('Modified', 'sysadmin-total-suite'); ?></span>
                            <code class="stsuite-file-path"><?php echo esc_html($stsuite_file['path']); ?></code>
                            <button class="button show-diff" data-path="<?php echo esc_attr($stsuite_file['path']); ?>"><?php esc_html_e('Show changes', 'sysadmin-total-suite'); ?></button>
                        <?php else: ?>
                            <span class="stsuite-tag stsuite-tag--warn"><?php esc_html_e('Missing', 'sysadmin-total-suite'); ?></span>
                            <code class="stsuite-file-path"><?php echo esc_html($stsuite_file['path']); ?></code>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif (!empty($stsuite_modificados) || !empty($stsuite_faltantes)): ?>
             <h2><?php esc_html_e('Modified / missing files', 'sysadmin-total-suite'); ?></h2>
             <p><?php esc_html_e('No modified or missing files were detected.', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>

        <?php if (!empty($stsuite_extras)): ?>
            <h2><?php esc_html_e('Extra files', 'sysadmin-total-suite'); ?></h2>
            <button class="button button-secondary view-extras"><?php esc_html_e('View detected extra files', 'sysadmin-total-suite'); ?></button>
        <?php endif; ?>

    <?php else: ?>
        <p><?php /* translators: %s: the "Analyze now" button label in bold. */ printf(esc_html__('No analysis available. Run %s to get results.', 'sysadmin-total-suite'), '<strong>' . esc_html__('Analyze now', 'sysadmin-total-suite') . '</strong>'); ?></p>
    <?php endif; ?>

</div>

<!-- Modal: diferencias del archivo -->
<div id="stsuiteDiffModal" class="stsuite-modal-overlay" style="display:none;">
    <div class="stsuite-modal">
        <span class="stsuite-close">&times;</span>
        <h2><?php esc_html_e('File differences', 'sysadmin-total-suite'); ?></h2>
        <pre id="stsuiteDiffContent"></pre>
    </div>
</div>

<!-- Modal: archivos extra detectados -->
<div id="stsuiteExtrasModal" class="stsuite-modal-overlay" style="display:none;">
    <div class="stsuite-modal">
        <span class="stsuite-close">&times;</span>
        <h2><?php esc_html_e('Extra files detected', 'sysadmin-total-suite'); ?></h2>
        <p><?php esc_html_e('These files are not part of the official WordPress core. Review them and remove any you do not recognise using your hosting file manager or FTP client.', 'sysadmin-total-suite'); ?></p>

        <?php if (!empty($stsuite_paged_extras)): ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación; no modifica estado. */ echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <input type="hidden" name="open_extras_modal" value="true" />
                        <select name="extras_per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($stsuite_extras_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($stsuite_extras_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($stsuite_extras_per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($stsuite_extras_per_page, 'all'); ?>><?php esc_html_e('All', 'sysadmin-total-suite'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'sysadmin-total-suite'); ?>">
                    </form>
                </div>
                <?php if ($stsuite_extras_per_page !== 'all' && $stsuite_extras_total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php /* translators: %s: number of items. */ printf(esc_html(_n('%s item', '%s items', $stsuite_extras_total_items, 'sysadmin-total-suite')), esc_html(number_format_i18n($stsuite_extras_total_items))); ?></span>
                    <span class="pagination-links">
                        <?php
                        echo wp_kses_post(paginate_links([
                            'base'    => add_query_arg(['extras_paged' => '%#%', 'open_extras_modal' => 'true']),
                            'format'  => '',
                            'total'   => $stsuite_extras_total_pages,
                            'current' => $stsuite_extras_current_page,
                        ]));
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="stsuiteExtrasList">
                <?php foreach ($stsuite_paged_extras as $stsuite_file): ?>
                    <li><code class="stsuite-file-path"><?php echo esc_html($stsuite_file); ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <button class="button button-secondary" id="stsuite-extras-modal-close"><?php esc_html_e('Close', 'sysadmin-total-suite'); ?></button>
    </div>
</div>

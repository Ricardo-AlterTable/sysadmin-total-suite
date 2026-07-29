<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
}

$analysis = get_transient('wps_last_analysis');

?>
<div class="wrap">
    <h1><?php esc_html_e('Core integrity', 'site-integrity-profiler'); ?></h1>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['cache_purged']) && $_GET['cache_purged'] === '1'): ?>
        <div id="message" class="updated notice is-dismissible">
            <p><?php esc_html_e('Plugin cache purged. The next analysis will start from scratch.', 'site-integrity-profiler'); ?></p>
        </div>
    <?php endif; ?>

    <div class="wps-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wps_run_analysis_nonce'); ?>
            <input type="hidden" name="action" value="wps_run_analysis">
            <button type="submit" class="button button-primary"><?php esc_html_e('Analyze now', 'site-integrity-profiler'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wps_purge_cache_nonce', 'wps_purge_cache'); ?>
            <input type="hidden" name="action" value="wps_purge_cache">
            <button type="submit" class="button button-secondary"><?php esc_html_e('Purge cache', 'site-integrity-profiler'); ?></button>
        </form>
    </div>

    <?php if ($analysis): ?>
        <?php
        // Clasificar errores en modificados / faltantes / extras
        $modificados = [];
        $faltantes = [];
        $extras = [];

        foreach ($analysis['errors'] as $err) {
            if (strpos($err, 'Modified:') === 0) {
                $modificados[] = preg_replace('/^Modified:\s*/', '', $err);
            } elseif (strpos($err, 'Missing:') === 0) {
                $faltantes[] = preg_replace('/^Missing:\s*/', '', $err);
            } elseif (strpos($err, 'Extra:') === 0) {
                $extras[] = preg_replace('/^Extra:\s*/', '', $err);
            }
        }

        // Los "problemas del core" son solo modificados/faltantes; los extras
        // (archivos no reconocidos) se informan por separado.
        $core_issues = count($modificados) + count($faltantes);
        ?>

        <h2><?php esc_html_e('Result', 'site-integrity-profiler'); ?></h2>
        <?php if ($core_issues > 0): ?>
            <p class="wps-status wps-status--bad"><?php esc_html_e('⚠ Issues were detected in the WordPress core', 'site-integrity-profiler'); ?></p>
        <?php else: ?>
            <p class="wps-status wps-status--ok"><?php esc_html_e('✔ No issues were detected in the WordPress core', 'site-integrity-profiler'); ?></p>
        <?php endif; ?>
        <?php if (!empty($extras)): ?>
            <p class="wps-status wps-status--warn"><?php esc_html_e('⚠ Files not recognized by WordPress were detected', 'site-integrity-profiler'); ?></p>
        <?php endif; ?>

        <?php

        // Pagination for modified/missing files
        if (!empty($modificados) || !empty($faltantes)) {
            $all_files = array_merge(
                array_map(function($f) { return ['type' => 'Modified', 'path' => $f]; }, $modificados),
                array_map(function($f) { return ['type' => 'Missing', 'path' => $f]; }, $faltantes)
            );

            $per_page = isset($_GET['per_page']) && in_array((string) $_GET['per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['per_page'])) : 20;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $total_items = count($all_files);

            if ($per_page === 'all') {
                $paged_files = $all_files;
                $total_pages = 1;
            } else {
                $per_page_int = intval($per_page);
                $total_pages = ceil($total_items / $per_page_int);
                $current_page = min($current_page, $total_pages);
                $paged_files = array_slice($all_files, ($current_page - 1) * $per_page_int, $per_page_int);
            }
        }

        // Pagination for extra files
        if (!empty($extras)) {
            $extras_per_page = isset($_GET['extras_per_page']) && in_array((string) $_GET['extras_per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['extras_per_page'])) : 20;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $extras_current_page = isset($_GET['extras_paged']) ? max(1, intval($_GET['extras_paged'])) : 1;  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado.
            $extras_total_items = count($extras);

            if ($extras_per_page === 'all') {
                $paged_extras = $extras;
                $extras_total_pages = 1;
            } else {
                $extras_per_page_int = intval($extras_per_page);
                $extras_total_pages = ceil($extras_total_items / $extras_per_page_int);
                $extras_current_page = min($extras_current_page, $extras_total_pages);
                $paged_extras = array_slice($extras, ($extras_current_page - 1) * $extras_per_page_int, $extras_per_page_int);
            }
        }
        ?>

        <?php if (!empty($paged_files)): ?>
            <h2><?php esc_html_e('Modified / missing files', 'site-integrity-profiler'); ?></h2>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($per_page, 'all'); ?>><?php esc_html_e('All', 'site-integrity-profiler'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'site-integrity-profiler'); ?>">
                    </form>
                </div>
                <?php if ($per_page !== 'all' && $total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php /* translators: %s: number of items. */ printf(esc_html(_n('%s item', '%s items', $total_items, 'site-integrity-profiler')), esc_html(number_format_i18n($total_items))); ?></span>
                    <span class="pagination-links">
                        <?php echo wp_kses_post(paginate_links(['total' => $total_pages, 'current' => $current_page])); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wps-list">
                <?php foreach ($paged_files as $file): ?>
                    <li>
                        <?php if ($file['type'] === 'Modified'): ?>
                            <span class="wps-tag wps-tag--danger"><?php esc_html_e('Modified', 'site-integrity-profiler'); ?></span>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button show-diff" data-path="<?php echo esc_attr($file['path']); ?>"><?php esc_html_e('Show changes', 'site-integrity-profiler'); ?></button>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_file')); ?>"><?php esc_html_e('Restore', 'site-integrity-profiler'); ?></button>
                        <?php else: ?>
                            <span class="wps-tag wps-tag--warn"><?php esc_html_e('Missing', 'site-integrity-profiler'); ?></span>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_file')); ?>"><?php esc_html_e('Restore', 'site-integrity-profiler'); ?></button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button class="button button-secondary restore-all" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_all')); ?>"><?php esc_html_e('Restore all modified/missing', 'site-integrity-profiler'); ?></button>
        <?php elseif (!empty($modificados) || !empty($faltantes)): ?>
             <h2><?php esc_html_e('Modified / missing files', 'site-integrity-profiler'); ?></h2>
             <p><?php esc_html_e('No modified or missing files were detected.', 'site-integrity-profiler'); ?></p>
        <?php endif; ?>

        <?php if (!empty($extras)): ?>
            <h2><?php esc_html_e('Extra files', 'site-integrity-profiler'); ?></h2>
            <button class="button button-secondary view-extras"><?php esc_html_e('View detected extra files', 'site-integrity-profiler'); ?></button>
        <?php endif; ?>

    <?php else: ?>
        <p><?php /* translators: %s: the "Analyze now" button label in bold. */ printf(esc_html__('No analysis available. Run %s to get results.', 'site-integrity-profiler'), '<strong>' . esc_html__('Analyze now', 'site-integrity-profiler') . '</strong>'); ?></p>
    <?php endif; ?>

    <?php
    // ==========================================================
    // Copias de seguridad creadas por la restauración
    // ==========================================================
    $wps_backups     = wps_list_backups();
    $wps_backups_n   = count($wps_backups);
    $wps_backups_sz  = array_sum(array_column($wps_backups, 'size'));
    $wps_bk_nonce    = wp_create_nonce('wps_backups');
    $wps_datetime_fmt = get_option('date_format') . ' ' . get_option('time_format');
    ?>
    <div class="wps-card">
        <h2><?php esc_html_e('Backups', 'site-integrity-profiler'); ?></h2>

        <?php if (!$wps_backups_n): ?>
            <p class="wps-muted"><?php esc_html_e('There are no backups. They are created when you restore a core file and choose to keep a copy.', 'site-integrity-profiler'); ?></p>
        <?php else: ?>
            <p class="wps-kv">
                <?php
                printf(
                    /* translators: 1: number of backups, 2: total size. */
                    esc_html__('Saved backups: %1$s (%2$s in total)', 'site-integrity-profiler'),
                    '<strong>' . esc_html(number_format_i18n($wps_backups_n)) . '</strong>',
                    '<strong>' . esc_html(size_format($wps_backups_sz, 2)) . '</strong>'
                );
                ?>
            </p>

            <div class="wps-actions">
                <button class="button wps-btn-danger wps-delete-all-backups" data-nonce="<?php echo esc_attr($wps_bk_nonce); ?>">
                    <?php esc_html_e('Delete all backups', 'site-integrity-profiler'); ?>
                </button>
            </div>

            <?php foreach ($wps_backups as $bk): ?>
                <div class="wps-backup" data-store="<?php echo esc_attr($bk['store']); ?>" data-batch="<?php echo esc_attr($bk['batch']); ?>">
                    <div class="wps-backup-head">
                        <strong>
                            <?php
                            echo $bk['time']
                                ? esc_html(wp_date($wps_datetime_fmt, $bk['time']))
                                : esc_html($bk['batch']);
                            ?>
                        </strong>
                        <span class="wps-muted">
                            <?php
                            printf(
                                /* translators: 1: number of files, 2: size. */
                                esc_html(_n('%1$s file · %2$s', '%1$s files · %2$s', $bk['count'], 'site-integrity-profiler')),
                                esc_html(number_format_i18n($bk['count'])),
                                esc_html(size_format($bk['size'], 2))
                            );
                            ?>
                        </span>
                        <?php if ($bk['legacy']): ?>
                            <span class="wps-badge warn"><?php esc_html_e('Old location (web-accessible)', 'site-integrity-profiler'); ?></span>
                        <?php endif; ?>
                        <button class="button wps-btn-danger wps-delete-backup-batch" data-nonce="<?php echo esc_attr($wps_bk_nonce); ?>">
                            <?php esc_html_e('Delete this backup', 'site-integrity-profiler'); ?>
                        </button>
                    </div>

                    <table class="wps-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('File', 'site-integrity-profiler'); ?></th>
                                <th><?php esc_html_e('Size', 'site-integrity-profiler'); ?></th>
                                <th><?php esc_html_e('Actions', 'site-integrity-profiler'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bk['files'] as $f): ?>
                                <tr data-file="<?php echo esc_attr($f['rel']); ?>" data-target="<?php echo esc_attr($f['target']); ?>">
                                    <td><code class="wps-file-path"><?php echo esc_html($f['target']); ?></code></td>
                                    <td><?php echo esc_html(size_format($f['size'], 2)); ?></td>
                                    <td>
                                        <button class="button wps-restore-backup" data-nonce="<?php echo esc_attr($wps_bk_nonce); ?>">
                                            <?php esc_html_e('Restore', 'site-integrity-profiler'); ?>
                                        </button>
                                        <button class="button wps-btn-danger wps-delete-backup-file" data-nonce="<?php echo esc_attr($wps_bk_nonce); ?>">
                                            <?php esc_html_e('Delete', 'site-integrity-profiler'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for diff -->
<div id="wpsDiffModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2><?php esc_html_e('File differences', 'site-integrity-profiler'); ?></h2>
        <pre id="wpsDiffContent"></pre>
    </div>
</div>

<!-- Modal for extras -->
<div id="wpsExtrasModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2><?php esc_html_e('Detected extra files', 'site-integrity-profiler'); ?></h2>
        <p><?php esc_html_e('These files do not belong to the official WordPress core.', 'site-integrity-profiler'); ?></p>
        <p class="wps-status wps-status--bad"><?php /* translators: %s: the word "irreversible" in bold. */ printf(esc_html__('⚠ Deleting a file is %s: it is permanently removed and cannot be undone.', 'site-integrity-profiler'), '<strong>' . esc_html__('irreversible', 'site-integrity-profiler') . '</strong>'); ?></p>

        <?php if (!empty($paged_extras)): ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <input type="hidden" name="open_extras_modal" value="true" />
                        <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetros de solo lectura que se reinyectan en la paginación; no modifican estado. */
                        foreach ($_GET as $key => $value) {
                            if (is_scalar($value) && !in_array($key, ['page', 'open_extras_modal', 'extras_per_page', 'extras_paged'], true)) {
                                echo '<input type="hidden" name="' . esc_attr(sanitize_key($key)) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '" />';
                            }
                        } ?>
                        <select name="extras_per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($extras_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($extras_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($extras_per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($extras_per_page, 'all'); ?>><?php esc_html_e('All', 'site-integrity-profiler'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'site-integrity-profiler'); ?>">
                    </form>
                </div>
                <?php if ($extras_per_page !== 'all' && $extras_total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php /* translators: %s: number of items. */ printf(esc_html(_n('%s item', '%s items', $extras_total_items, 'site-integrity-profiler')), esc_html(number_format_i18n($extras_total_items))); ?></span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = [
                            'base' => add_query_arg(['extras_paged' => '%#%', 'open_extras_modal' => 'true']),
                            'format' => '',
                            'total' => $extras_total_pages,
                            'current' => $extras_current_page,
                        ];
                        echo wp_kses_post(paginate_links($pagination_args));
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wpsExtrasList">
                <?php foreach ($paged_extras as $file): ?>
                    <li>
                        <code class="wps-file-path"><?php echo esc_html($file); ?></code>
                        <button class="button wps-delete-extra" data-path="<?php echo esc_attr($file); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_delete_extra')); ?>"><?php esc_html_e('Delete', 'site-integrity-profiler'); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button class="button button-secondary wps-delete-all-extras" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_delete_all_extras')); ?>"><?php esc_html_e('Delete all extra files', 'site-integrity-profiler'); ?></button>
        <?php endif; ?>
        <button class="button button-secondary" id="wps-extras-modal-close"><?php esc_html_e('Close', 'site-integrity-profiler'); ?></button>
    </div>
</div>
<script>
// Los clics de diff / restaurar / cerrar los gestiona admin.js.
// Aquí solo abrimos el modal de extras automáticamente cuando venimos de su paginación.
document.addEventListener('DOMContentLoaded', function () {
    var params = new URLSearchParams(window.location.search);
    if (params.get('open_extras_modal') === 'true') {
        var extrasModal = document.getElementById('wpsExtrasModal');
        if (extrasModal) extrasModal.style.display = 'flex';
    }
});
</script>

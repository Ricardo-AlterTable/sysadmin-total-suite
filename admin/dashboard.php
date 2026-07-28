<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
}

$analysis = get_transient('wps_last_analysis');

?>
<div class="wrap">
    <h1><?php esc_html_e('Core integrity', 'wp-profiler-security'); ?></h1>

    <?php if (isset($_GET['cache_purged']) && $_GET['cache_purged'] === '1'): ?>
        <div id="message" class="updated notice is-dismissible">
            <p><?php esc_html_e('Plugin cache purged. The next analysis will start from scratch.', 'wp-profiler-security'); ?></p>
        </div>
    <?php endif; ?>

    <div class="wps-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wps_run_analysis_nonce'); ?>
            <input type="hidden" name="action" value="wps_run_analysis">
            <button type="submit" class="button button-primary"><?php esc_html_e('Analyze now', 'wp-profiler-security'); ?></button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wps_purge_cache_nonce', 'wps_purge_cache'); ?>
            <input type="hidden" name="action" value="wps_purge_cache">
            <button type="submit" class="button button-secondary"><?php esc_html_e('Purge cache', 'wp-profiler-security'); ?></button>
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

        <h2><?php esc_html_e('Result', 'wp-profiler-security'); ?></h2>
        <?php if ($core_issues > 0): ?>
            <p class="wps-status wps-status--bad"><?php esc_html_e('⚠ Issues were detected in the WordPress core', 'wp-profiler-security'); ?></p>
        <?php else: ?>
            <p class="wps-status wps-status--ok"><?php esc_html_e('✔ No issues were detected in the WordPress core', 'wp-profiler-security'); ?></p>
        <?php endif; ?>
        <?php if (!empty($extras)): ?>
            <p class="wps-status wps-status--warn"><?php esc_html_e('⚠ Files not recognized by WordPress were detected', 'wp-profiler-security'); ?></p>
        <?php endif; ?>

        <?php

        // Pagination for modified/missing files
        if (!empty($modificados) || !empty($faltantes)) {
            $all_files = array_merge(
                array_map(function($f) { return ['type' => 'Modified', 'path' => $f]; }, $modificados),
                array_map(function($f) { return ['type' => 'Missing', 'path' => $f]; }, $faltantes)
            );

            $per_page = isset($_GET['per_page']) && in_array((string) $_GET['per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['per_page'])) : 20;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
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
            $extras_per_page = isset($_GET['extras_per_page']) && in_array((string) $_GET['extras_per_page'], ['20', '50', '100', 'all'], true) ? sanitize_text_field(wp_unslash($_GET['extras_per_page'])) : 20;
            $extras_current_page = isset($_GET['extras_paged']) ? max(1, intval($_GET['extras_paged'])) : 1;
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
            <h2><?php esc_html_e('Modified / missing files', 'wp-profiler-security'); ?></h2>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($per_page, 'all'); ?>><?php esc_html_e('All', 'wp-profiler-security'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'wp-profiler-security'); ?>">
                    </form>
                </div>
                <?php if ($per_page !== 'all' && $total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(esc_html(_n('%s item', '%s items', $total_items, 'wp-profiler-security')), esc_html(number_format_i18n($total_items))); ?></span>
                    <span class="pagination-links">
                        <?php echo paginate_links(['total' => $total_pages, 'current' => $current_page]); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wps-list">
                <?php foreach ($paged_files as $file): ?>
                    <li>
                        <?php if ($file['type'] === 'Modified'): ?>
                            <span class="wps-tag wps-tag--danger"><?php esc_html_e('Modified', 'wp-profiler-security'); ?></span>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button show-diff" data-path="<?php echo esc_attr($file['path']); ?>"><?php esc_html_e('Show changes', 'wp-profiler-security'); ?></button>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_file')); ?>"><?php esc_html_e('Restore', 'wp-profiler-security'); ?></button>
                        <?php else: ?>
                            <span class="wps-tag wps-tag--warn"><?php esc_html_e('Missing', 'wp-profiler-security'); ?></span>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_file')); ?>"><?php esc_html_e('Restore', 'wp-profiler-security'); ?></button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button class="button button-secondary restore-all" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_restore_all')); ?>"><?php esc_html_e('Restore all modified/missing', 'wp-profiler-security'); ?></button>
        <?php elseif (!empty($modificados) || !empty($faltantes)): ?>
             <h2><?php esc_html_e('Modified / missing files', 'wp-profiler-security'); ?></h2>
             <p><?php esc_html_e('No modified or missing files were detected.', 'wp-profiler-security'); ?></p>
        <?php endif; ?>

        <?php if (!empty($extras)): ?>
            <h2><?php esc_html_e('Extra files', 'wp-profiler-security'); ?></h2>
            <button class="button button-secondary view-extras"><?php esc_html_e('View detected extra files', 'wp-profiler-security'); ?></button>
        <?php endif; ?>

    <?php else: ?>
        <p><?php printf(esc_html__('No analysis available. Run %s to get results.', 'wp-profiler-security'), '<strong>' . esc_html__('Analyze now', 'wp-profiler-security') . '</strong>'); ?></p>
    <?php endif; ?>
</div>

<!-- Modal for diff -->
<div id="wpsDiffModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2><?php esc_html_e('File differences', 'wp-profiler-security'); ?></h2>
        <pre id="wpsDiffContent"></pre>
    </div>
</div>

<!-- Modal for extras -->
<div id="wpsExtrasModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2><?php esc_html_e('Detected extra files', 'wp-profiler-security'); ?></h2>
        <p><?php esc_html_e('These files do not belong to the official WordPress core.', 'wp-profiler-security'); ?></p>
        <p class="wps-status wps-status--bad"><?php printf(esc_html__('⚠ Deleting a file is %s: it is permanently removed and cannot be undone.', 'wp-profiler-security'), '<strong>' . esc_html__('irreversible', 'wp-profiler-security') . '</strong>'); ?></p>

        <?php if (!empty($paged_extras)): ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr(sanitize_key(wp_unslash($_REQUEST['page'] ?? ''))); ?>" />
                        <input type="hidden" name="open_extras_modal" value="true" />
                        <?php foreach ($_GET as $key => $value) {
                            if (is_scalar($value) && !in_array($key, ['page', 'open_extras_modal', 'extras_per_page', 'extras_paged'], true)) {
                                echo '<input type="hidden" name="' . esc_attr(sanitize_key($key)) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '" />';
                            }
                        } ?>
                        <select name="extras_per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($extras_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($extras_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($extras_per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($extras_per_page, 'all'); ?>><?php esc_html_e('All', 'wp-profiler-security'); ?></option>
                        </select>
                        <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'wp-profiler-security'); ?>">
                    </form>
                </div>
                <?php if ($extras_per_page !== 'all' && $extras_total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(esc_html(_n('%s item', '%s items', $extras_total_items, 'wp-profiler-security')), esc_html(number_format_i18n($extras_total_items))); ?></span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = [
                            'base' => add_query_arg(['extras_paged' => '%#%', 'open_extras_modal' => 'true']),
                            'format' => '',
                            'total' => $extras_total_pages,
                            'current' => $extras_current_page,
                        ];
                        echo paginate_links($pagination_args);
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wpsExtrasList">
                <?php foreach ($paged_extras as $file): ?>
                    <li>
                        <code class="wps-file-path"><?php echo esc_html($file); ?></code>
                        <button class="button wps-delete-extra" data-path="<?php echo esc_attr($file); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_delete_extra')); ?>"><?php esc_html_e('Delete', 'wp-profiler-security'); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button class="button button-secondary wps-delete-all-extras" data-nonce="<?php echo esc_attr(wp_create_nonce('wps_delete_all_extras')); ?>"><?php esc_html_e('Delete all extra files', 'wp-profiler-security'); ?></button>
        <?php endif; ?>
        <button class="button button-secondary" id="wps-extras-modal-close"><?php esc_html_e('Close', 'wp-profiler-security'); ?></button>
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

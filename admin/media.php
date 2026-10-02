<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can(stsuite_media_capability())) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_results = stsuite_media_results();
$stsuite_done    = ($stsuite_results['status'] ?? '') === 'done';
$stsuite_limit   = 300; // filas máximas por tabla

$stsuite_unused  = $stsuite_done ? (array) $stsuite_results['unused'] : [];
$stsuite_orphans = $stsuite_done ? (array) $stsuite_results['orphans'] : [];
$stsuite_backups = $stsuite_done ? (array) ($stsuite_results['backups'] ?? []) : [];

// Mayores primero: es donde más espacio se gana.
usort($stsuite_unused, fn($stsuite_a, $stsuite_b) => $stsuite_b['bytes'] <=> $stsuite_a['bytes']);
usort($stsuite_orphans, fn($stsuite_a, $stsuite_b) => $stsuite_b['bytes'] <=> $stsuite_a['bytes']);

$stsuite_unused_bytes = (int) array_sum(array_column($stsuite_unused, 'bytes'));
$stsuite_orphan_bytes = (int) array_sum(array_column($stsuite_orphans, 'bytes'));
$stsuite_backup_bytes = (int) array_sum(array_column($stsuite_backups, 'bytes'));
$stsuite_date_format  = get_option('date_format');
$stsuite_quarantine   = stsuite_quarantine_list();
$stsuite_q_bytes      = (int) array_sum(array_column($stsuite_quarantine, 'bytes'));

// Resultado de la última operación (parámetros de solo lectura tras el redirect).
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Avisos tras una redirección; no modifican estado.
$stsuite_op  = isset($_GET['stsuite_op']) ? sanitize_key(wp_unslash($_GET['stsuite_op'])) : '';
$stsuite_num = function ($stsuite_key) {
    return isset($_GET['stsuite_' . $stsuite_key]) ? absint($_GET['stsuite_' . $stsuite_key]) : 0;
};
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap">
    <h1><?php esc_html_e('Unused media', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Finds media library items that do not seem to be used anywhere on the site, and files in the uploads folders that do not belong to any media item. The scan itself changes nothing; unused items can then be moved to a quarantine from which they can be restored.', 'sysadmin-total-suite'); ?></p>

    <?php if ($stsuite_op === 'quarantine'): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s media item moved to quarantine.', '%s media items moved to quarantine.', $stsuite_num('done'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('done')))); ?>
            <?php if ($stsuite_num('referenced')): ?>
                <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s was skipped because it is now referenced somewhere.', '%s were skipped because they are now referenced somewhere.', $stsuite_num('referenced'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('referenced')))); ?>
            <?php endif; ?>
            <?php if ($stsuite_num('failed')): ?>
                <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s could not be moved and was left untouched.', '%s could not be moved and were left untouched.', $stsuite_num('failed'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('failed')))); ?>
            <?php endif; ?>
        </p></div>
    <?php elseif ($stsuite_op === 'restore'): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s media item restored.', '%s media items restored.', $stsuite_num('done'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('done')))); ?>
            <?php if ($stsuite_num('conflict')): ?>
                <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s could not be fully restored because a file with the same name already exists; it stays in quarantine.', '%s could not be fully restored because files with the same name already exist; they stay in quarantine.', $stsuite_num('conflict'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('conflict')))); ?>
            <?php endif; ?>
        </p></div>
    <?php elseif ($stsuite_op === 'purge'): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s media item permanently deleted.', '%s media items permanently deleted.', $stsuite_num('done'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('done')))); ?>
            <?php if ($stsuite_num('failed')): ?>
                <?php /* translators: %s: number of media items. */ echo esc_html(sprintf(_n('%s could not be deleted.', '%s could not be deleted.', $stsuite_num('failed'), 'sysadmin-total-suite'), number_format_i18n($stsuite_num('failed')))); ?>
            <?php endif; ?>
        </p></div>
    <?php endif; ?>

    <?php if (!empty($stsuite_quarantine)): ?>
        <!-- Cuarentena -->
        <div class="stsuite-card" id="stsuite-quarantine">
            <h2><?php esc_html_e('Quarantine', 'sysadmin-total-suite'); ?> · <?php echo esc_html(size_format($stsuite_q_bytes, 1) ?: '0 B'); ?></h2>
            <p><?php esc_html_e('These media items are hidden from the media library and their files have been moved to a protected folder. If something on the site now shows a broken image, restore the item: it will be exactly as it was.', 'sysadmin-total-suite'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('stsuite_media_restore'); ?>
                <input type="hidden" name="action" value="stsuite_media_restore">
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th class="stsuite-check"><input type="checkbox" class="stsuite-check-all" aria-label="<?php esc_attr_e('Select all', 'sysadmin-total-suite'); ?>"></th>
                            <th><?php esc_html_e('Media item', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('In quarantine since', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Status', 'sysadmin-total-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stsuite_quarantine, 0, $stsuite_limit) as $stsuite_q): ?>
                            <tr>
                                <td class="stsuite-check"><input type="checkbox" name="stsuite_ids[]" value="<?php echo esc_attr((string) $stsuite_q['id']); ?>"></td>
                                <td>
                                    <strong><?php echo esc_html($stsuite_q['title'] !== '' ? $stsuite_q['title'] : basename($stsuite_q['file'])); ?></strong>
                                    <br><code class="stsuite-file-path"><?php echo esc_html($stsuite_q['file']); ?></code>
                                    <br><span class="stsuite-muted"><?php /* translators: %s: number of files. */ echo esc_html(sprintf(_n('%s file', '%s files', $stsuite_q['count'], 'sysadmin-total-suite'), number_format_i18n($stsuite_q['count']))); ?></span>
                                </td>
                                <td><?php echo esc_html($stsuite_q['at'] ? wp_date($stsuite_date_format . ' ' . get_option('time_format'), $stsuite_q['at']) : '—'); ?></td>
                                <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_q['bytes'], 1) ?: '0 B'); ?></td>
                                <td>
                                    <?php if (stsuite_quarantine_is_referenced($stsuite_q['id'])): ?>
                                        <span class="stsuite-badge bad"><?php esc_html_e('Referenced again: restore it', 'sysadmin-total-suite'); ?></span>
                                    <?php else: ?>
                                        <span class="stsuite-muted"><?php esc_html_e('No references found', 'sysadmin-total-suite'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="stsuite-actions" style="margin-top:14px;">
                    <button type="submit" class="button"><?php esc_html_e('Restore selected', 'sysadmin-total-suite'); ?></button>
                    <button type="submit" class="button" name="stsuite_all" value="1"><?php esc_html_e('Restore all', 'sysadmin-total-suite'); ?></button>
                </div>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stsuite-confirm-form" data-confirm="purgeQuarantine1" data-confirm2="purgeQuarantine2">
                <?php wp_nonce_field('stsuite_media_purge'); ?>
                <input type="hidden" name="action" value="stsuite_media_purge">
                <p class="description"><?php esc_html_e('Emptying the quarantine permanently deletes these media items and their files, using WordPress\'s own deletion. It cannot be undone: make sure the site has been working correctly for a while first.', 'sysadmin-total-suite'); ?></p>
                <button type="submit" class="button stsuite-btn-danger"><?php esc_html_e('Empty quarantine (delete permanently)', 'sysadmin-total-suite'); ?></button>
            </form>
        </div>
    <?php endif; ?>

    <div class="stsuite-actions">
        <button class="button button-primary stsuite-media-scan" data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_media')); ?>"><?php esc_html_e('Scan media', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteMediaProgress" class="stsuite-muted" aria-live="polite"></span>
    </div>

    <?php if (!$stsuite_done): ?>
        <p><?php /* translators: %s: the button label in bold. */ printf(esc_html__('No scan available. Run %s to get results.', 'sysadmin-total-suite'), '<strong>' . esc_html__('Scan media', 'sysadmin-total-suite') . '</strong>'); ?></p>
    <?php else: ?>

        <p class="stsuite-kv">
            <?php
            printf(
                /* translators: %s: date and time of the last scan. */
                esc_html__('Last scan: %s', 'sysadmin-total-suite'),
                esc_html(wp_date($stsuite_date_format . ' ' . get_option('time_format'), (int) $stsuite_results['checked_at']))
            );
            ?>
        </p>

        <div class="stsuite-card">
            <h2><?php esc_html_e('Summary', 'sysadmin-total-suite'); ?></h2>
            <p class="stsuite-kv">
                <?php esc_html_e('Media items checked:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(number_format_i18n((int) $stsuite_results['checked'])); ?></strong>
                · <?php esc_html_e('Apparently unused:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(number_format_i18n(count($stsuite_unused))); ?></strong> (<?php echo esc_html(size_format($stsuite_unused_bytes, 1) ?: '0 B'); ?>)
                · <?php esc_html_e('Orphan files:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(number_format_i18n(count($stsuite_orphans))); ?></strong> (<?php echo esc_html(size_format($stsuite_orphan_bytes, 1) ?: '0 B'); ?>)
                <?php if (!empty($stsuite_backups)): ?>
                    · <?php esc_html_e('Optimization backups:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(number_format_i18n(count($stsuite_backups))); ?></strong> (<?php echo esc_html(size_format($stsuite_backup_bytes, 1)); ?>)
                <?php endif; ?>
            </p>
            <p class="stsuite-status stsuite-status--warn">
                <?php esc_html_e('⚠ This is an estimate. An item is reported only when no reference to it was found in posts, pages, custom fields, page builders, widgets, theme settings or term and user data. Images used only from theme files, external sites, emails or newsletters cannot be detected. Review every item before deleting it.', 'sysadmin-total-suite'); ?>
            </p>
        </div>

        <!-- Adjuntos sin referencias -->
        <div class="stsuite-card">
            <h2><?php esc_html_e('Media items without references', 'sysadmin-total-suite'); ?></h2>
            <?php if (empty($stsuite_unused)): ?>
                <p class="stsuite-ok-text"><?php esc_html_e('No unused media items were found.', 'sysadmin-total-suite'); ?></p>
            <?php else: ?>
                <p class="description"><?php esc_html_e('Select the items you are sure about and move them to the quarantine: they disappear from the media library and their files are moved aside, but they can be restored at any time. Each item is checked again for references before being moved. Sizes include every generated thumbnail.', 'sysadmin-total-suite'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="stsuite-confirm-form" data-confirm="confirmQuarantine" data-require-selection="1">
                <?php wp_nonce_field('stsuite_media_quarantine'); ?>
                <input type="hidden" name="action" value="stsuite_media_quarantine">
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th class="stsuite-check"><input type="checkbox" class="stsuite-check-all" aria-label="<?php esc_attr_e('Select all', 'sysadmin-total-suite'); ?>"></th>
                            <th></th>
                            <th><?php esc_html_e('Media item', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Uploaded', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Actions', 'sysadmin-total-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stsuite_unused, 0, $stsuite_limit) as $stsuite_m): ?>
                            <tr>
                                <td class="stsuite-check"><input type="checkbox" name="stsuite_ids[]" value="<?php echo esc_attr((string) $stsuite_m['id']); ?>"></td>
                                <td class="stsuite-media-thumb"><?php echo wp_get_attachment_image((int) $stsuite_m['id'], [60, 60], true); ?></td>
                                <td>
                                    <strong><?php echo esc_html($stsuite_m['title'] !== '' ? $stsuite_m['title'] : basename($stsuite_m['file'])); ?></strong>
                                    <br><code class="stsuite-file-path"><?php echo esc_html($stsuite_m['file']); ?></code>
                                    <br><span class="stsuite-muted"><?php echo esc_html($stsuite_m['mime']); ?></span>
                                    <?php if ($stsuite_m['parent'] > 0 && get_post($stsuite_m['parent'])): ?>
                                        <br><span class="stsuite-muted">
                                            <?php
                                            printf(
                                                /* translators: %s: title of the post the media item was uploaded to. */
                                                esc_html__('Uploaded to: %s', 'sysadmin-total-suite'),
                                                esc_html(get_the_title($stsuite_m['parent']))
                                            );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($stsuite_m['date'] !== '' ? wp_date($stsuite_date_format, strtotime($stsuite_m['date'] . ' UTC')) : '—'); ?></td>
                                <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_m['bytes'], 1) ?: '0 B'); ?></td>
                                <td>
                                    <?php $stsuite_edit = get_edit_post_link((int) $stsuite_m['id']); ?>
                                    <?php if ($stsuite_edit): ?>
                                        <a href="<?php echo esc_url($stsuite_edit); ?>"><?php esc_html_e('Open in library', 'sysadmin-total-suite'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="stsuite-actions" style="margin-top:14px;">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Move selected to quarantine', 'sysadmin-total-suite'); ?></button>
                </div>
                </form>
                <?php if (count($stsuite_unused) > $stsuite_limit): ?>
                    <p class="description">
                        <?php /* translators: 1: rows shown, 2: total rows. */ printf(esc_html__('Showing the %1$s largest of %2$s.', 'sysadmin-total-suite'), esc_html(number_format_i18n($stsuite_limit)), esc_html(number_format_i18n(count($stsuite_unused)))); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($stsuite_backups)): ?>
            <!-- Copias de optimización -->
            <div class="stsuite-card">
                <h2><?php esc_html_e('Image optimization backups', 'sysadmin-total-suite'); ?> · <?php echo esc_html(size_format($stsuite_backup_bytes, 1)); ?></h2>
                <p><?php /* translators: %s: number of files. */ echo esc_html(sprintf(_n('%s copy of an original image (name.bk.ext) kept by an image optimization plugin such as LiteSpeed Cache.', '%s copies of original images (name.bk.ext) kept by an image optimization plugin such as LiteSpeed Cache.', count($stsuite_backups), 'sysadmin-total-suite'), number_format_i18n(count($stsuite_backups)))); ?></p>
                <p class="description"><?php esc_html_e('They are not orphans: they allow the plugin to restore the original images. If you no longer need that, remove them from the optimization plugin itself (in LiteSpeed Cache, in its Image Optimization tools), not by FTP, so that it keeps its records consistent.', 'sysadmin-total-suite'); ?></p>
            </div>
        <?php endif; ?>

        <!-- Archivos huérfanos -->
        <div class="stsuite-card">
            <h2><?php esc_html_e('Orphan files in uploads', 'sysadmin-total-suite'); ?></h2>
            <?php if (empty($stsuite_orphans)): ?>
                <p class="stsuite-ok-text"><?php esc_html_e('No orphan files were found in the year/month upload folders.', 'sysadmin-total-suite'); ?></p>
            <?php else: ?>
                <p class="description"><?php esc_html_e('Files inside the year/month upload folders that do not belong to any media item: usually thumbnails of sizes no longer registered, leftovers of deleted items or files uploaded by FTP. WebP/AVIF copies made by optimization plugins are not listed. They can only be removed by FTP or from your hosting file manager.', 'sysadmin-total-suite'); ?></p>
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('File', 'sysadmin-total-suite'); ?></th>
                            <th><?php esc_html_e('Modified', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($stsuite_orphans, 0, $stsuite_limit) as $stsuite_o): ?>
                            <tr>
                                <td><code class="stsuite-file-path"><?php echo esc_html($stsuite_o['file']); ?></code></td>
                                <td><?php echo esc_html(wp_date($stsuite_date_format, (int) $stsuite_o['mtime'])); ?></td>
                                <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_o['bytes'], 1) ?: '0 B'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($stsuite_orphans) > $stsuite_limit): ?>
                    <p class="description">
                        <?php /* translators: 1: rows shown, 2: total rows. */ printf(esc_html__('Showing the %1$s largest of %2$s.', 'sysadmin-total-suite'), esc_html(number_format_i18n($stsuite_limit)), esc_html(number_format_i18n(count($stsuite_orphans)))); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

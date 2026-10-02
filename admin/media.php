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

// Mayores primero: es donde más espacio se gana.
usort($stsuite_unused, fn($stsuite_a, $stsuite_b) => $stsuite_b['bytes'] <=> $stsuite_a['bytes']);
usort($stsuite_orphans, fn($stsuite_a, $stsuite_b) => $stsuite_b['bytes'] <=> $stsuite_a['bytes']);

$stsuite_unused_bytes = (int) array_sum(array_column($stsuite_unused, 'bytes'));
$stsuite_orphan_bytes = (int) array_sum(array_column($stsuite_orphans, 'bytes'));
$stsuite_date_format  = get_option('date_format');
?>
<div class="wrap">
    <h1><?php esc_html_e('Unused media', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Finds media library items that do not seem to be used anywhere on the site, and files in the uploads folders that do not belong to any media item. This is a read-only report: nothing is moved or deleted.', 'sysadmin-total-suite'); ?></p>

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
                <p class="description"><?php esc_html_e('To delete one, open it in the media library and use "Delete permanently". Its size includes every generated thumbnail.', 'sysadmin-total-suite'); ?></p>
                <table class="stsuite-table">
                    <thead>
                        <tr>
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
                <?php if (count($stsuite_unused) > $stsuite_limit): ?>
                    <p class="description">
                        <?php /* translators: 1: rows shown, 2: total rows. */ printf(esc_html__('Showing the %1$s largest of %2$s.', 'sysadmin-total-suite'), esc_html(number_format_i18n($stsuite_limit)), esc_html(number_format_i18n(count($stsuite_unused)))); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

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

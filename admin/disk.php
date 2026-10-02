<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can(stsuite_disk_capability())) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_results = stsuite_disk_results();
$stsuite_done    = ($stsuite_results['status'] ?? '') === 'done' && !empty($stsuite_results['units']);

$stsuite_group_labels = [
    'core'    => __('WordPress core', 'sysadmin-total-suite'),
    'uploads' => __('Uploads (media library)', 'sysadmin-total-suite'),
    'plugins' => __('Plugins', 'sysadmin-total-suite'),
    'themes'  => __('Themes', 'sysadmin-total-suite'),
    'content' => __('Other wp-content folders', 'sysadmin-total-suite'),
    'root'    => __('Other folders in the site root', 'sysadmin-total-suite'),
];

// Agregación por grupo. En uploads, los meses se agrupan bajo su año.
$stsuite_groups  = [];
$stsuite_total   = 0;
$stsuite_files   = 0;
$stsuite_partial = false;
$stsuite_unreadable = false;
if ($stsuite_done) {
    foreach ($stsuite_results['units'] as $stsuite_u) {
        $stsuite_g = $stsuite_u['group'];
        if (!isset($stsuite_groups[$stsuite_g])) {
            $stsuite_groups[$stsuite_g] = ['bytes' => 0, 'files' => 0, 'items' => []];
        }
        $stsuite_bytes = (int) ($stsuite_u['bytes'] ?? 0);
        $stsuite_count = (int) ($stsuite_u['files'] ?? 0);
        $stsuite_groups[$stsuite_g]['bytes'] += $stsuite_bytes;
        $stsuite_groups[$stsuite_g]['files'] += $stsuite_count;
        $stsuite_total += $stsuite_bytes;
        $stsuite_files += $stsuite_count;
        if (!empty($stsuite_u['partial'])) $stsuite_partial = true;
        if (!empty($stsuite_u['unreadable'])) $stsuite_unreadable = true;

        $stsuite_item_key = $stsuite_u['label'];
        $stsuite_child    = null;
        if ($stsuite_g === 'uploads' && preg_match('#^((?:19|20)\d{2})(?:/(.+))?$#', $stsuite_u['label'], $stsuite_m)) {
            $stsuite_item_key = $stsuite_m[1];
            $stsuite_child    = $stsuite_m[2] ?? __('Loose files', 'sysadmin-total-suite');
        }
        $stsuite_item = $stsuite_groups[$stsuite_g]['items'][$stsuite_item_key]
            ?? ['bytes' => 0, 'files' => 0, 'partial' => false, 'unreadable' => false, 'path' => $stsuite_child === null ? stsuite_disk_display_path($stsuite_u['path']) : '', 'children' => []];
        $stsuite_item['bytes'] += $stsuite_bytes;
        $stsuite_item['files'] += $stsuite_count;
        $stsuite_item['partial'] = $stsuite_item['partial'] || !empty($stsuite_u['partial']);
        $stsuite_item['unreadable'] = $stsuite_item['unreadable'] || !empty($stsuite_u['unreadable']);
        if ($stsuite_child !== null) {
            $stsuite_item['children'][$stsuite_child] = ['bytes' => $stsuite_bytes, 'files' => $stsuite_count];
        }
        $stsuite_groups[$stsuite_g]['items'][$stsuite_item_key] = $stsuite_item;
    }
    foreach ($stsuite_groups as $stsuite_g => $stsuite_data) {
        // Los años, del más reciente al más antiguo; el resto, por tamaño.
        uksort($stsuite_groups[$stsuite_g]['items'], function ($stsuite_a, $stsuite_b) use ($stsuite_data) {
            $stsuite_ya = preg_match('/^\d{4}$/', (string) $stsuite_a);
            $stsuite_yb = preg_match('/^\d{4}$/', (string) $stsuite_b);
            if ($stsuite_ya && $stsuite_yb) return strcmp((string) $stsuite_b, (string) $stsuite_a);
            if ($stsuite_ya !== $stsuite_yb) return $stsuite_yb <=> $stsuite_ya;
            return $stsuite_data['items'][$stsuite_b]['bytes'] <=> $stsuite_data['items'][$stsuite_a]['bytes'];
        });
    }
}

$stsuite_db       = $stsuite_results['db'] ?? null;
$stsuite_db_total = 0;
$stsuite_db_free  = 0;
if (is_array($stsuite_db)) {
    foreach ($stsuite_db['tables'] as $stsuite_t) {
        $stsuite_db_total += $stsuite_t['bytes'];
        $stsuite_db_free  += $stsuite_t['free'];
    }
}
$stsuite_space = $stsuite_results['disk'] ?? null;

/** Barra proporcional (0-100 %) para las tablas. */
$stsuite_bar = function ($stsuite_part, $stsuite_whole) {
    $stsuite_pct = $stsuite_whole > 0 ? min(100, round($stsuite_part * 100 / $stsuite_whole, 1)) : 0;
    return '<span class="stsuite-bar"><span style="width:' . esc_attr((string) $stsuite_pct) . '%"></span></span>';
};
?>
<div class="wrap">
    <h1><?php esc_html_e('Disk usage', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Measures the space used by the site files and the database. This is a read-only report: nothing is modified.', 'sysadmin-total-suite'); ?></p>

    <div class="stsuite-actions">
        <button class="button button-primary stsuite-disk-scan" data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_disk')); ?>"><?php esc_html_e('Measure now', 'sysadmin-total-suite'); ?></button>
        <span id="stsuiteDiskProgress" class="stsuite-muted" aria-live="polite"></span>
    </div>

    <?php if (!$stsuite_done): ?>
        <p><?php /* translators: %s: the "Measure now" button label in bold. */ printf(esc_html__('No measurement available. Run %s to get results.', 'sysadmin-total-suite'), '<strong>' . esc_html__('Measure now', 'sysadmin-total-suite') . '</strong>'); ?></p>
    <?php else: ?>

        <p class="stsuite-kv">
            <?php
            printf(
                /* translators: %s: date and time of the last measurement. */
                esc_html__('Last measurement: %s', 'sysadmin-total-suite'),
                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $stsuite_results['checked_at']))
            );
            ?>
        </p>

        <!-- Resumen -->
        <div class="stsuite-card">
            <h2><?php esc_html_e('Summary', 'sysadmin-total-suite'); ?></h2>
            <p class="stsuite-kv">
                <?php esc_html_e('Files:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(size_format($stsuite_total, 1)); ?></strong>
                <span class="stsuite-muted">(<?php /* translators: %s: number of files. */ echo esc_html(sprintf(_n('%s file', '%s files', $stsuite_files, 'sysadmin-total-suite'), number_format_i18n($stsuite_files))); ?>)</span>
                · <?php esc_html_e('Database:', 'sysadmin-total-suite'); ?> <strong><?php echo is_array($stsuite_db) ? esc_html(size_format($stsuite_db_total, 1)) : '—'; ?></strong>
                · <?php esc_html_e('Media library:', 'sysadmin-total-suite'); ?> <strong><?php /* translators: %s: number of items. */ echo esc_html(sprintf(_n('%s item', '%s items', (int) $stsuite_results['attachments'], 'sysadmin-total-suite'), number_format_i18n((int) $stsuite_results['attachments']))); ?></strong>
            </p>
            <?php if (is_array($stsuite_space)): ?>
                <?php $stsuite_used_pct = round(($stsuite_space['total'] - $stsuite_space['free']) * 100 / $stsuite_space['total']); ?>
                <p class="stsuite-kv">
                    <?php
                    printf(
                        /* translators: 1: free space, 2: total disk size, 3: percentage used. */
                        esc_html__('Server disk: %1$s free of %2$s (%3$s%% used)', 'sysadmin-total-suite'),
                        esc_html(size_format($stsuite_space['free'], 1)),
                        esc_html(size_format($stsuite_space['total'], 1)),
                        esc_html(number_format_i18n($stsuite_used_pct))
                    );
                    ?>
                    <span class="stsuite-badge <?php echo $stsuite_used_pct >= 90 ? 'bad' : ($stsuite_used_pct >= 75 ? 'warn' : 'ok'); ?>"><?php echo esc_html(number_format_i18n($stsuite_used_pct)); ?>%</span>
                </p>
                <p class="description"><?php esc_html_e('On shared hosting this figure may refer to the whole server, not to your plan quota.', 'sysadmin-total-suite'); ?></p>
            <?php endif; ?>

            <table class="stsuite-table">
                <tbody>
                    <?php foreach ($stsuite_group_labels as $stsuite_g => $stsuite_label): ?>
                        <?php if (empty($stsuite_groups[$stsuite_g])) continue; ?>
                        <tr>
                            <td><a href="#stsuite-disk-<?php echo esc_attr($stsuite_g); ?>"><?php echo esc_html($stsuite_label); ?></a></td>
                            <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_groups[$stsuite_g]['bytes'], 1)); ?></td>
                            <td class="stsuite-bar-cell"><?php echo $stsuite_bar($stsuite_groups[$stsuite_g]['bytes'], $stsuite_total); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado arriba con esc_attr. ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($stsuite_unreadable): ?>
                <p class="stsuite-num-warn"><?php esc_html_e('Some folders could not be read because of their permissions and are counted as 0: the real size may be larger.', 'sysadmin-total-suite'); ?></p>
            <?php endif; ?>
            <?php if ($stsuite_partial): ?>
                <p class="stsuite-num-warn">
                    <?php
                    printf(
                        /* translators: %d: seconds. */
                        esc_html__('Some folders took more than %d seconds and were only partially measured (marked with *): the real size is larger.', 'sysadmin-total-suite'),
                        (int) STSUITE_DISK_UNIT_SECONDS
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Detalle por grupo -->
        <?php foreach ($stsuite_group_labels as $stsuite_g => $stsuite_label): ?>
            <?php if (empty($stsuite_groups[$stsuite_g])) continue; ?>
            <div class="stsuite-card" id="stsuite-disk-<?php echo esc_attr($stsuite_g); ?>">
                <h2><?php echo esc_html($stsuite_label); ?> · <?php echo esc_html(size_format($stsuite_groups[$stsuite_g]['bytes'], 1)); ?></h2>
                <?php if ($stsuite_g === 'root'): ?>
                    <p class="description"><?php esc_html_e('Folders in the site root that are not part of WordPress: other applications, old copies or backups. Check whether you still need them.', 'sysadmin-total-suite'); ?></p>
                <?php elseif ($stsuite_g === 'content'): ?>
                    <p class="description"><?php esc_html_e('Cache, languages, upgrade leftovers and data from plugins (for example their backups) usually live here.', 'sysadmin-total-suite'); ?></p>
                <?php endif; ?>
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Folder', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Files', 'sysadmin-total-suite'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stsuite_groups[$stsuite_g]['items'] as $stsuite_name => $stsuite_item): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($stsuite_item['children'])): ?>
                                        <details>
                                            <summary><strong><?php echo esc_html((string) $stsuite_name); ?></strong></summary>
                                            <table class="stsuite-table stsuite-table--nested">
                                                <?php foreach ($stsuite_item['children'] as $stsuite_cname => $stsuite_child): ?>
                                                    <tr>
                                                        <td><?php echo esc_html((string) $stsuite_cname); ?></td>
                                                        <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_child['bytes'], 1)); ?></td>
                                                        <td class="stsuite-num"><?php echo esc_html(number_format_i18n($stsuite_child['files'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </table>
                                        </details>
                                    <?php else: ?>
                                        <?php echo esc_html((string) $stsuite_name); ?>
                                        <?php if ($stsuite_item['path'] !== ''): ?>
                                            <br><code class="stsuite-file-path"><?php echo esc_html($stsuite_item['path']); ?></code>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_item['bytes'], 1) ?: '0 B'); ?><?php echo $stsuite_item['partial'] ? ' *' : ''; ?><?php if ($stsuite_item['unreadable']): ?><br><span class="stsuite-num-warn"><?php esc_html_e('Not readable', 'sysadmin-total-suite'); ?></span><?php endif; ?></td>
                                <td class="stsuite-num"><?php echo esc_html(number_format_i18n($stsuite_item['files'])); ?></td>
                                <td class="stsuite-bar-cell"><?php echo $stsuite_bar($stsuite_item['bytes'], $stsuite_groups[$stsuite_g]['bytes']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado arriba con esc_attr. ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- Base de datos -->
        <div class="stsuite-card" id="stsuite-disk-db">
            <h2><?php esc_html_e('Database', 'sysadmin-total-suite'); ?><?php if (is_array($stsuite_db)): ?> · <?php echo esc_html(size_format($stsuite_db_total, 1)); ?><?php endif; ?></h2>
            <?php if (!is_array($stsuite_db)): ?>
                <p><?php esc_html_e('The table sizes could not be read (the database user may not have access to information_schema).', 'sysadmin-total-suite'); ?></p>
            <?php else: ?>
                <?php if ($stsuite_db_free > 1024 * 1024): ?>
                    <p class="stsuite-kv">
                        <?php
                        printf(
                            /* translators: %s: amount of space. */
                            esc_html__('Reclaimable space (overhead): %s. It is recovered by optimizing the tables from your hosting panel or phpMyAdmin.', 'sysadmin-total-suite'),
                            '<strong>' . esc_html(size_format($stsuite_db_free, 1)) . '</strong>'
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <table class="stsuite-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Table', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Rows (approx.)', 'sysadmin-total-suite'); ?></th>
                            <th class="stsuite-num"><?php esc_html_e('Overhead', 'sysadmin-total-suite'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stsuite_db['tables'] as $stsuite_t): ?>
                            <tr>
                                <td><code><?php echo esc_html($stsuite_t['name']); ?></code> <span class="stsuite-muted"><?php echo esc_html($stsuite_t['engine']); ?></span></td>
                                <td class="stsuite-num"><?php echo esc_html(size_format($stsuite_t['bytes'], 1) ?: '0 B'); ?></td>
                                <td class="stsuite-num"><?php echo esc_html(number_format_i18n($stsuite_t['rows'])); ?></td>
                                <td class="stsuite-num"><?php echo $stsuite_t['free'] > 0 ? esc_html(size_format($stsuite_t['free'], 1)) : '—'; ?></td>
                                <td class="stsuite-bar-cell"><?php echo $stsuite_bar($stsuite_t['bytes'], $stsuite_db_total); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML generado arriba con esc_attr. ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($stsuite_db['other_count'] > 0): ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: 1: number of tables, 2: their total size. */
                            esc_html(_n('The database also contains %1$s table from another application or prefix (%2$s), not included above.', 'The database also contains %1$s tables from other applications or prefixes (%2$s), not included above.', $stsuite_db['other_count'], 'sysadmin-total-suite')),
                            esc_html(number_format_i18n($stsuite_db['other_count'])),
                            esc_html(size_format($stsuite_db['other_bytes'], 1))
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <p class="description"><?php esc_html_e('Sizes are the sum of the file sizes; the space actually used on disk and your hosting quota (which may also include email, logs or other sites) can differ.', 'sysadmin-total-suite'); ?></p>
    <?php endif; ?>
</div>

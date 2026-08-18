<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_plugins   = stsuite_wpo_plugins_info();
$stsuite_autoload   = stsuite_wpo_autoload_stats();
$stsuite_transients = stsuite_wpo_transient_stats();
$stsuite_cron       = stsuite_wpo_cron_info();
$stsuite_cache      = stsuite_wpo_cache_info();
$stsuite_env        = stsuite_wpo_env_versions();
$stsuite_schedules  = wp_get_schedules();

// Umbrales orientativos.
$stsuite_autoload_warn = $stsuite_autoload['total_bytes'] > 1024 * 1024;      // > 1 MB autoload
$stsuite_plugins_warn  = $stsuite_plugins['active'] > 20;                     // muchos plugins activos
$stsuite_has_cache     = !empty($stsuite_cache['plugins']) || $stsuite_cache['page_cache_active'];
?>
<div class="wrap">
    <h1><?php esc_html_e('WPO', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Web Performance Optimization: a quick performance check of the site.', 'sysadmin-total-suite'); ?></p>

    <!-- 1) Plugins -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Plugins affecting load time', 'sysadmin-total-suite'); ?></h2>
        <p class="stsuite-kv"><?php esc_html_e('Installed:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_plugins['total']; ?></strong> ·
           <?php esc_html_e('Active:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_plugins['active']; ?></strong> ·
           <?php esc_html_e('Inactive:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_plugins['inactive']; ?></strong>
           <span class="stsuite-badge <?php echo $stsuite_plugins_warn ? 'warn' : 'ok'; ?>">
               <?php echo $stsuite_plugins_warn ? esc_html__('Many active plugins', 'sysadmin-total-suite') : esc_html__('Reasonable amount', 'sysadmin-total-suite'); ?>
           </span>
        </p>
        <p><?php /* translators: %s: the word "Active" in bold. */ printf(esc_html__('%s plugins load on every request; they are the ones impacting speed:', 'sysadmin-total-suite'), '<strong>' . esc_html__('Active', 'sysadmin-total-suite') . '</strong>'); ?></p>
        <table class="stsuite-table">
            <thead><tr><th><?php esc_html_e('Active plugin', 'sysadmin-total-suite'); ?></th><th><?php esc_html_e('Version', 'sysadmin-total-suite'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($stsuite_plugins['active_list'] as $stsuite_p): ?>
                    <tr>
                        <td><?php echo esc_html($stsuite_p['name']); ?></td>
                        <td><?php echo esc_html($stsuite_p['version'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 2) Basura en wp_options -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Autoload options and wp_options junk', 'sysadmin-total-suite'); ?></h2>
        <p class="stsuite-kv">
            <?php esc_html_e('Autoload options:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_autoload['count']; ?></strong> ·
            <?php esc_html_e('Total autoload size:', 'sysadmin-total-suite'); ?> <strong><?php echo esc_html(size_format($stsuite_autoload['total_bytes'], 2)); ?></strong>
            <span class="stsuite-badge <?php echo $stsuite_autoload_warn ? 'warn' : 'ok'; ?>">
                <?php echo $stsuite_autoload_warn ? esc_html__('High (>1 MB)', 'sysadmin-total-suite') : esc_html__('OK', 'sysadmin-total-suite'); ?>
            </span>
        </p>
        <p class="stsuite-kv">
            <?php esc_html_e('Transients with expiration:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_transients['total']; ?></strong> ·
            <?php esc_html_e('Expired (junk):', 'sysadmin-total-suite'); ?> <strong class="stsuite-num-warn"><?php echo (int) $stsuite_transients['expired']; ?></strong>
        </p>

        <button class="button button-secondary stsuite-clean-transients"
                data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_clean_transients')); ?>">
            <?php esc_html_e('Clean up expired transients', 'sysadmin-total-suite'); ?>
        </button>

        <?php if (!empty($stsuite_autoload['largest'])): ?>
            <p style="margin-top:15px;"><?php esc_html_e('Largest autoload options (check whether any belongs to an uninstalled plugin):', 'sysadmin-total-suite'); ?></p>
            <table class="stsuite-table">
                <thead><tr><th>option_name</th><th><?php esc_html_e('Size', 'sysadmin-total-suite'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($stsuite_autoload['largest'] as $stsuite_o): ?>
                        <tr>
                            <td><code><?php echo esc_html($stsuite_o['option_name']); ?></code></td>
                            <td><?php echo esc_html(size_format((int) $stsuite_o['sz'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="stsuite-muted" style="font-size:12px;"><?php esc_html_e('Automatic deletion of these options is not offered for safety: they could belong to active plugins. Remove them manually only if you recognize them as belonging to a removed plugin.', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 3) WP-Cron -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('WP-Cron', 'sysadmin-total-suite'); ?></h2>
        <p class="stsuite-kv">
            <?php esc_html_e('Mode:', 'sysadmin-total-suite'); ?>
            <?php if ($stsuite_cron['disabled']): ?>
                <strong><?php esc_html_e('Internal WP-Cron disabled', 'sysadmin-total-suite'); ?></strong> (<code>DISABLE_WP_CRON = true</code>)
                <span class="stsuite-badge warn"><?php esc_html_e('A real system cron must be calling wp-cron.php', 'sysadmin-total-suite'); ?></span>
            <?php else: ?>
                <strong><?php esc_html_e('Internal WP-Cron active', 'sysadmin-total-suite'); ?></strong>
                <span class="stsuite-badge ok"><?php esc_html_e('Triggered by visits', 'sysadmin-total-suite'); ?></span>
            <?php endif; ?>
        </p>
        <p class="stsuite-kv">
            <?php esc_html_e('Scheduled tasks:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_cron['total']; ?></strong> ·
            <?php esc_html_e('Overdue:', 'sysadmin-total-suite'); ?> <strong><?php echo (int) $stsuite_cron['overdue']; ?></strong>
            <?php if ($stsuite_cron['overdue'] > 0): ?><span class="stsuite-badge warn"><?php esc_html_e('There are overdue tasks', 'sysadmin-total-suite'); ?></span><?php endif; ?>
            · <?php esc_html_e('Orphaned:', 'sysadmin-total-suite'); ?> <strong class="stsuite-num-warn"><?php echo (int) $stsuite_cron['orphaned']; ?></strong>
            <?php if ($stsuite_cron['orphaned'] > 0): ?><span class="stsuite-badge warn"><?php esc_html_e('Junk from removed plugins', 'sysadmin-total-suite'); ?></span><?php endif; ?>
        </p>

        <?php if ($stsuite_cron['orphaned'] > 0): ?>
            <button class="button stsuite-btn-danger stsuite-clean-cron-all"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_clean_cron_all')); ?>">
                <?php esc_html_e('Clean up all orphan cron tasks', 'sysadmin-total-suite'); ?>
            </button>
        <?php endif; ?>

        <?php if (!empty($stsuite_cron['events'])): ?>
            <table class="stsuite-table">
                <thead><tr><th><?php esc_html_e('Hook', 'sysadmin-total-suite'); ?></th><th><?php esc_html_e('Next run', 'sysadmin-total-suite'); ?></th><th><?php esc_html_e('Recurrence', 'sysadmin-total-suite'); ?></th><th><?php esc_html_e('Status', 'sysadmin-total-suite'); ?></th><th><?php esc_html_e('Actions', 'sysadmin-total-suite'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($stsuite_cron['events'] as $stsuite_ev): ?>
                        <?php
                        $stsuite_when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $stsuite_ev['time']);
                        $stsuite_recur = $stsuite_ev['schedule']
                            ? (isset($stsuite_schedules[$stsuite_ev['schedule']]['display']) ? $stsuite_schedules[$stsuite_ev['schedule']]['display'] : $stsuite_ev['schedule'])
                            : __('Once', 'sysadmin-total-suite');
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($stsuite_ev['hook']); ?></code></td>
                            <td><?php echo esc_html($stsuite_when); ?></td>
                            <td><?php echo esc_html($stsuite_recur); ?></td>
                            <td>
                                <?php if ($stsuite_ev['orphan']): ?>
                                    <span class="stsuite-badge warn"><?php esc_html_e('Orphaned', 'sysadmin-total-suite'); ?></span>
                                <?php elseif ($stsuite_ev['overdue']): ?>
                                    <span class="stsuite-badge warn"><?php esc_html_e('Overdue', 'sysadmin-total-suite'); ?></span>
                                <?php else: ?>
                                    <span class="stsuite-badge ok"><?php esc_html_e('OK', 'sysadmin-total-suite'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($stsuite_ev['orphan']): ?>
                                    <button class="button stsuite-btn-danger stsuite-clean-cron-hook"
                                            data-hook="<?php echo esc_attr($stsuite_ev['hook']); ?>"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_clean_cron')); ?>"><?php esc_html_e('Delete', 'sysadmin-total-suite'); ?></button>
                                <?php else: ?>
                                    <span class="stsuite-user-nodelete">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php esc_html_e('There are no scheduled cron tasks.', 'sysadmin-total-suite'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 4) Caché -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Cache', 'sysadmin-total-suite'); ?></h2>
        <?php if ($stsuite_has_cache): ?>
            <p class="stsuite-kv"><span class="stsuite-badge ok"><?php esc_html_e('Cache active', 'sysadmin-total-suite'); ?></span></p>
        <?php else: ?>
            <p class="stsuite-kv"><span class="stsuite-badge bad"><?php esc_html_e('No cache plugin detected', 'sysadmin-total-suite'); ?></span></p>
        <?php endif; ?>
        <p class="stsuite-kv"><?php esc_html_e('Web server:', 'sysadmin-total-suite'); ?>
            <strong><?php echo $stsuite_cache['server_software'] ? esc_html($stsuite_cache['server_software']) : esc_html__('unknown', 'sysadmin-total-suite'); ?></strong>
            <?php if ($stsuite_cache['server_is_ls']): ?><span class="stsuite-badge ok">LiteSpeed</span><?php endif; ?>
        </p>
        <p class="stsuite-kv"><?php esc_html_e('Active cache plugin(s):', 'sysadmin-total-suite'); ?>
            <strong><?php echo $stsuite_cache['plugins'] ? esc_html(implode(', ', $stsuite_cache['plugins'])) : esc_html__('none', 'sysadmin-total-suite'); ?></strong>
        </p>

        <?php if ($stsuite_cache['page_method'] === 'litespeed'): ?>
            <p class="stsuite-kv"><?php esc_html_e('Page cache (LiteSpeed, server level):', 'sysadmin-total-suite'); ?>
                <?php if ($stsuite_cache['ls_cache_enabled'] === true): ?>
                    <strong class="stsuite-ok-text"><?php esc_html_e('enabled', 'sysadmin-total-suite'); ?></strong>
                <?php elseif ($stsuite_cache['ls_cache_enabled'] === false): ?>
                    <strong class="stsuite-bad-text"><?php esc_html_e('disabled', 'sysadmin-total-suite'); ?></strong>
                    <span class="stsuite-badge warn"><?php esc_html_e('Enable it in LiteSpeed Cache → Cache', 'sysadmin-total-suite'); ?></span>
                <?php else: ?>
                    <strong><?php echo $stsuite_cache['server_is_ls'] ? esc_html__('active (LiteSpeed server detected)', 'sysadmin-total-suite') : esc_html__('state not readable', 'sysadmin-total-suite'); ?></strong>
                <?php endif; ?>
            </p>
            <p class="stsuite-kv stsuite-muted" style="font-size:12px;">
                <?php esc_html_e('LiteSpeed caches on the server, which is why it does not use WP_CACHE or advanced-cache.php. You can confirm it with the x-litespeed-cache: hit header on the front end.', 'sysadmin-total-suite'); ?>
            </p>
        <?php else: ?>
            <p class="stsuite-kv"><?php esc_html_e('Page cache (WP_CACHE + advanced-cache.php):', 'sysadmin-total-suite'); ?>
                <strong><?php echo $stsuite_cache['page_cache_active'] ? esc_html__('enabled', 'sysadmin-total-suite') : esc_html__('not enabled', 'sysadmin-total-suite'); ?></strong>
            </p>
        <?php endif; ?>

        <p class="stsuite-kv"><?php esc_html_e('Object cache (object-cache.php):', 'sysadmin-total-suite'); ?>
            <strong><?php echo $stsuite_cache['object_cache'] ? esc_html__('present', 'sysadmin-total-suite') : esc_html__('not present', 'sysadmin-total-suite'); ?></strong>
        </p>
    </div>

    <!-- 5) Versiones del entorno -->
    <div class="stsuite-card">
        <h2><?php esc_html_e('Environment versions', 'sysadmin-total-suite'); ?></h2>
        <table class="stsuite-table">
            <tbody>
                <tr><td>PHP</td><td><strong><?php echo esc_html($stsuite_env['php']); ?></strong></td></tr>
                <tr><td><?php esc_html_e('Database', 'sysadmin-total-suite'); ?></td><td><strong><?php echo esc_html($stsuite_env['db_type'] . ' — ' . $stsuite_env['db_server_info']); ?></strong></td></tr>
                <tr><td>WordPress</td><td><strong><?php echo esc_html($stsuite_env['wp']); ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

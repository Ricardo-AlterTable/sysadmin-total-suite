<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
}

$plugins   = wps_wpo_plugins_info();
$autoload   = wps_wpo_autoload_stats();
$transients = wps_wpo_transient_stats();
$cron       = wps_wpo_cron_info();
$cache      = wps_wpo_cache_info();
$env        = wps_wpo_env_versions();
$schedules  = wp_get_schedules();

// Umbrales orientativos.
$autoload_warn = $autoload['total_bytes'] > 1024 * 1024;      // > 1 MB autoload
$plugins_warn  = $plugins['active'] > 20;                     // muchos plugins activos
$has_cache     = !empty($cache['plugins']) || $cache['page_cache_active'];
?>
<div class="wrap">
    <h1><?php esc_html_e('WPO', 'site-integrity-profiler'); ?></h1>
    <p><?php esc_html_e('Web Performance Optimization: a quick performance check of the site.', 'site-integrity-profiler'); ?></p>

    <!-- 1) Plugins -->
    <div class="wps-card">
        <h2><?php esc_html_e('Plugins affecting load time', 'site-integrity-profiler'); ?></h2>
        <p class="wps-kv"><?php esc_html_e('Installed:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $plugins['total']; ?></strong> ·
           <?php esc_html_e('Active:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $plugins['active']; ?></strong> ·
           <?php esc_html_e('Inactive:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $plugins['inactive']; ?></strong>
           <span class="wps-badge <?php echo $plugins_warn ? 'warn' : 'ok'; ?>">
               <?php echo $plugins_warn ? esc_html__('Many active plugins', 'site-integrity-profiler') : esc_html__('Reasonable amount', 'site-integrity-profiler'); ?>
           </span>
        </p>
        <p><?php /* translators: %s: the word "Active" in bold. */ printf(esc_html__('%s plugins load on every request; they are the ones impacting speed:', 'site-integrity-profiler'), '<strong>' . esc_html__('Active', 'site-integrity-profiler') . '</strong>'); ?></p>
        <table class="wps-table">
            <thead><tr><th><?php esc_html_e('Active plugin', 'site-integrity-profiler'); ?></th><th><?php esc_html_e('Version', 'site-integrity-profiler'); ?></th></tr></thead>
            <tbody>
                <?php foreach ($plugins['active_list'] as $p): ?>
                    <tr>
                        <td><?php echo esc_html($p['name']); ?></td>
                        <td><?php echo esc_html($p['version'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 2) Basura en wp_options -->
    <div class="wps-card">
        <h2><?php esc_html_e('Autoload options and wp_options junk', 'site-integrity-profiler'); ?></h2>
        <p class="wps-kv">
            <?php esc_html_e('Autoload options:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $autoload['count']; ?></strong> ·
            <?php esc_html_e('Total autoload size:', 'site-integrity-profiler'); ?> <strong><?php echo esc_html(size_format($autoload['total_bytes'], 2)); ?></strong>
            <span class="wps-badge <?php echo $autoload_warn ? 'warn' : 'ok'; ?>">
                <?php echo $autoload_warn ? esc_html__('High (>1 MB)', 'site-integrity-profiler') : esc_html__('OK', 'site-integrity-profiler'); ?>
            </span>
        </p>
        <p class="wps-kv">
            <?php esc_html_e('Transients with expiration:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $transients['total']; ?></strong> ·
            <?php esc_html_e('Expired (junk):', 'site-integrity-profiler'); ?> <strong class="wps-num-warn"><?php echo (int) $transients['expired']; ?></strong>
        </p>

        <button class="button button-secondary wps-clean-transients"
                data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_transients')); ?>">
            <?php esc_html_e('Clean up expired transients', 'site-integrity-profiler'); ?>
        </button>

        <?php if (!empty($autoload['largest'])): ?>
            <p style="margin-top:15px;"><?php esc_html_e('Largest autoload options (check whether any belongs to an uninstalled plugin):', 'site-integrity-profiler'); ?></p>
            <table class="wps-table">
                <thead><tr><th>option_name</th><th><?php esc_html_e('Size', 'site-integrity-profiler'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($autoload['largest'] as $o): ?>
                        <tr>
                            <td><code><?php echo esc_html($o['option_name']); ?></code></td>
                            <td><?php echo esc_html(size_format((int) $o['sz'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="wps-muted" style="font-size:12px;"><?php esc_html_e('Automatic deletion of these options is not offered for safety: they could belong to active plugins. Remove them manually only if you recognize them as belonging to a removed plugin.', 'site-integrity-profiler'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 3) WP-Cron -->
    <div class="wps-card">
        <h2><?php esc_html_e('WP-Cron', 'site-integrity-profiler'); ?></h2>
        <p class="wps-kv">
            <?php esc_html_e('Mode:', 'site-integrity-profiler'); ?>
            <?php if ($cron['disabled']): ?>
                <strong><?php esc_html_e('Internal WP-Cron disabled', 'site-integrity-profiler'); ?></strong> (<code>DISABLE_WP_CRON = true</code>)
                <span class="wps-badge warn"><?php esc_html_e('A real system cron must be calling wp-cron.php', 'site-integrity-profiler'); ?></span>
            <?php else: ?>
                <strong><?php esc_html_e('Internal WP-Cron active', 'site-integrity-profiler'); ?></strong>
                <span class="wps-badge ok"><?php esc_html_e('Triggered by visits', 'site-integrity-profiler'); ?></span>
            <?php endif; ?>
        </p>
        <p class="wps-kv">
            <?php esc_html_e('Scheduled tasks:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $cron['total']; ?></strong> ·
            <?php esc_html_e('Overdue:', 'site-integrity-profiler'); ?> <strong><?php echo (int) $cron['overdue']; ?></strong>
            <?php if ($cron['overdue'] > 0): ?><span class="wps-badge warn"><?php esc_html_e('There are overdue tasks', 'site-integrity-profiler'); ?></span><?php endif; ?>
            · <?php esc_html_e('Orphaned:', 'site-integrity-profiler'); ?> <strong class="wps-num-warn"><?php echo (int) $cron['orphaned']; ?></strong>
            <?php if ($cron['orphaned'] > 0): ?><span class="wps-badge warn"><?php esc_html_e('Junk from removed plugins', 'site-integrity-profiler'); ?></span><?php endif; ?>
        </p>

        <?php if ($cron['orphaned'] > 0): ?>
            <button class="button wps-btn-danger wps-clean-cron-all"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_cron_all')); ?>">
                <?php esc_html_e('Clean up all orphan cron tasks', 'site-integrity-profiler'); ?>
            </button>
        <?php endif; ?>

        <?php if (!empty($cron['events'])): ?>
            <table class="wps-table">
                <thead><tr><th><?php esc_html_e('Hook', 'site-integrity-profiler'); ?></th><th><?php esc_html_e('Next run', 'site-integrity-profiler'); ?></th><th><?php esc_html_e('Recurrence', 'site-integrity-profiler'); ?></th><th><?php esc_html_e('Status', 'site-integrity-profiler'); ?></th><th><?php esc_html_e('Actions', 'site-integrity-profiler'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($cron['events'] as $ev): ?>
                        <?php
                        $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ev['time']);
                        $recur = $ev['schedule']
                            ? (isset($schedules[$ev['schedule']]['display']) ? $schedules[$ev['schedule']]['display'] : $ev['schedule'])
                            : __('Once', 'site-integrity-profiler');
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($ev['hook']); ?></code></td>
                            <td><?php echo esc_html($when); ?></td>
                            <td><?php echo esc_html($recur); ?></td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <span class="wps-badge warn"><?php esc_html_e('Orphaned', 'site-integrity-profiler'); ?></span>
                                <?php elseif ($ev['overdue']): ?>
                                    <span class="wps-badge warn"><?php esc_html_e('Overdue', 'site-integrity-profiler'); ?></span>
                                <?php else: ?>
                                    <span class="wps-badge ok"><?php esc_html_e('OK', 'site-integrity-profiler'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <button class="button wps-btn-danger wps-clean-cron-hook"
                                            data-hook="<?php echo esc_attr($ev['hook']); ?>"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_cron')); ?>"><?php esc_html_e('Delete', 'site-integrity-profiler'); ?></button>
                                <?php else: ?>
                                    <span class="wps-user-nodelete">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php esc_html_e('There are no scheduled cron tasks.', 'site-integrity-profiler'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 4) Caché -->
    <div class="wps-card">
        <h2><?php esc_html_e('Cache', 'site-integrity-profiler'); ?></h2>
        <?php if ($has_cache): ?>
            <p class="wps-kv"><span class="wps-badge ok"><?php esc_html_e('Cache active', 'site-integrity-profiler'); ?></span></p>
        <?php else: ?>
            <p class="wps-kv"><span class="wps-badge bad"><?php esc_html_e('No cache plugin detected', 'site-integrity-profiler'); ?></span></p>
        <?php endif; ?>
        <p class="wps-kv"><?php esc_html_e('Web server:', 'site-integrity-profiler'); ?>
            <strong><?php echo $cache['server_software'] ? esc_html($cache['server_software']) : esc_html__('unknown', 'site-integrity-profiler'); ?></strong>
            <?php if ($cache['server_is_ls']): ?><span class="wps-badge ok">LiteSpeed</span><?php endif; ?>
        </p>
        <p class="wps-kv"><?php esc_html_e('Active cache plugin(s):', 'site-integrity-profiler'); ?>
            <strong><?php echo $cache['plugins'] ? esc_html(implode(', ', $cache['plugins'])) : esc_html__('none', 'site-integrity-profiler'); ?></strong>
        </p>

        <?php if ($cache['page_method'] === 'litespeed'): ?>
            <p class="wps-kv"><?php esc_html_e('Page cache (LiteSpeed, server level):', 'site-integrity-profiler'); ?>
                <?php if ($cache['ls_cache_enabled'] === true): ?>
                    <strong class="wps-ok-text"><?php esc_html_e('enabled', 'site-integrity-profiler'); ?></strong>
                <?php elseif ($cache['ls_cache_enabled'] === false): ?>
                    <strong class="wps-bad-text"><?php esc_html_e('disabled', 'site-integrity-profiler'); ?></strong>
                    <span class="wps-badge warn"><?php esc_html_e('Enable it in LiteSpeed Cache → Cache', 'site-integrity-profiler'); ?></span>
                <?php else: ?>
                    <strong><?php echo $cache['server_is_ls'] ? esc_html__('active (LiteSpeed server detected)', 'site-integrity-profiler') : esc_html__('state not readable', 'site-integrity-profiler'); ?></strong>
                <?php endif; ?>
            </p>
            <p class="wps-kv wps-muted" style="font-size:12px;">
                <?php esc_html_e('LiteSpeed caches on the server, which is why it does not use WP_CACHE or advanced-cache.php. You can confirm it with the x-litespeed-cache: hit header on the front end.', 'site-integrity-profiler'); ?>
            </p>
        <?php else: ?>
            <p class="wps-kv"><?php esc_html_e('Page cache (WP_CACHE + advanced-cache.php):', 'site-integrity-profiler'); ?>
                <strong><?php echo $cache['page_cache_active'] ? esc_html__('enabled', 'site-integrity-profiler') : esc_html__('not enabled', 'site-integrity-profiler'); ?></strong>
            </p>
        <?php endif; ?>

        <p class="wps-kv"><?php esc_html_e('Object cache (object-cache.php):', 'site-integrity-profiler'); ?>
            <strong><?php echo $cache['object_cache'] ? esc_html__('present', 'site-integrity-profiler') : esc_html__('not present', 'site-integrity-profiler'); ?></strong>
        </p>
    </div>

    <!-- 5) Versiones del entorno -->
    <div class="wps-card">
        <h2><?php esc_html_e('Environment versions', 'site-integrity-profiler'); ?></h2>
        <table class="wps-table">
            <tbody>
                <tr><td>PHP</td><td><strong><?php echo esc_html($env['php']); ?></strong></td></tr>
                <tr><td><?php esc_html_e('Database', 'site-integrity-profiler'); ?></td><td><strong><?php echo esc_html($env['db_type'] . ' — ' . $env['db_server_info']); ?></strong></td></tr>
                <tr><td>WordPress</td><td><strong><?php echo esc_html($env['wp']); ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
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
    <h1><?php esc_html_e('WPO', 'wp-profiler-security'); ?></h1>
    <p><?php esc_html_e('Web Performance Optimization: a quick performance check of the site.', 'wp-profiler-security'); ?></p>

    <!-- 1) Plugins -->
    <div class="wps-card">
        <h2><?php esc_html_e('Plugins affecting load time', 'wp-profiler-security'); ?></h2>
        <p class="wps-kv"><?php esc_html_e('Installed:', 'wp-profiler-security'); ?> <strong><?php echo (int) $plugins['total']; ?></strong> ·
           <?php esc_html_e('Active:', 'wp-profiler-security'); ?> <strong><?php echo (int) $plugins['active']; ?></strong> ·
           <?php esc_html_e('Inactive:', 'wp-profiler-security'); ?> <strong><?php echo (int) $plugins['inactive']; ?></strong>
           <span class="wps-badge <?php echo $plugins_warn ? 'warn' : 'ok'; ?>">
               <?php echo $plugins_warn ? esc_html__('Many active plugins', 'wp-profiler-security') : esc_html__('Reasonable amount', 'wp-profiler-security'); ?>
           </span>
        </p>
        <p><?php printf(esc_html__('%s plugins load on every request; they are the ones impacting speed:', 'wp-profiler-security'), '<strong>' . esc_html__('Active', 'wp-profiler-security') . '</strong>'); ?></p>
        <table class="wps-table">
            <thead><tr><th><?php esc_html_e('Active plugin', 'wp-profiler-security'); ?></th><th><?php esc_html_e('Version', 'wp-profiler-security'); ?></th></tr></thead>
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
        <h2><?php esc_html_e('Autoload options and wp_options junk', 'wp-profiler-security'); ?></h2>
        <p class="wps-kv">
            <?php esc_html_e('Autoload options:', 'wp-profiler-security'); ?> <strong><?php echo (int) $autoload['count']; ?></strong> ·
            <?php esc_html_e('Total autoload size:', 'wp-profiler-security'); ?> <strong><?php echo esc_html(size_format($autoload['total_bytes'], 2)); ?></strong>
            <span class="wps-badge <?php echo $autoload_warn ? 'warn' : 'ok'; ?>">
                <?php echo $autoload_warn ? esc_html__('High (>1 MB)', 'wp-profiler-security') : esc_html__('OK', 'wp-profiler-security'); ?>
            </span>
        </p>
        <p class="wps-kv">
            <?php esc_html_e('Transients with expiration:', 'wp-profiler-security'); ?> <strong><?php echo (int) $transients['total']; ?></strong> ·
            <?php esc_html_e('Expired (junk):', 'wp-profiler-security'); ?> <strong class="wps-num-warn"><?php echo (int) $transients['expired']; ?></strong>
        </p>

        <button class="button button-secondary wps-clean-transients"
                data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_transients')); ?>">
            <?php esc_html_e('Clean up expired transients', 'wp-profiler-security'); ?>
        </button>

        <?php if (!empty($autoload['largest'])): ?>
            <p style="margin-top:15px;"><?php esc_html_e('Largest autoload options (check whether any belongs to an uninstalled plugin):', 'wp-profiler-security'); ?></p>
            <table class="wps-table">
                <thead><tr><th>option_name</th><th><?php esc_html_e('Size', 'wp-profiler-security'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($autoload['largest'] as $o): ?>
                        <tr>
                            <td><code><?php echo esc_html($o['option_name']); ?></code></td>
                            <td><?php echo esc_html(size_format((int) $o['sz'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="wps-muted" style="font-size:12px;"><?php esc_html_e('Automatic deletion of these options is not offered for safety: they could belong to active plugins. Remove them manually only if you recognize them as belonging to a removed plugin.', 'wp-profiler-security'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 3) WP-Cron -->
    <div class="wps-card">
        <h2><?php esc_html_e('WP-Cron', 'wp-profiler-security'); ?></h2>
        <p class="wps-kv">
            <?php esc_html_e('Mode:', 'wp-profiler-security'); ?>
            <?php if ($cron['disabled']): ?>
                <strong><?php esc_html_e('Internal WP-Cron disabled', 'wp-profiler-security'); ?></strong> (<code>DISABLE_WP_CRON = true</code>)
                <span class="wps-badge warn"><?php esc_html_e('A real system cron must be calling wp-cron.php', 'wp-profiler-security'); ?></span>
            <?php else: ?>
                <strong><?php esc_html_e('Internal WP-Cron active', 'wp-profiler-security'); ?></strong>
                <span class="wps-badge ok"><?php esc_html_e('Triggered by visits', 'wp-profiler-security'); ?></span>
            <?php endif; ?>
        </p>
        <p class="wps-kv">
            <?php esc_html_e('Scheduled tasks:', 'wp-profiler-security'); ?> <strong><?php echo (int) $cron['total']; ?></strong> ·
            <?php esc_html_e('Overdue:', 'wp-profiler-security'); ?> <strong><?php echo (int) $cron['overdue']; ?></strong>
            <?php if ($cron['overdue'] > 0): ?><span class="wps-badge warn"><?php esc_html_e('There are overdue tasks', 'wp-profiler-security'); ?></span><?php endif; ?>
            · <?php esc_html_e('Orphaned:', 'wp-profiler-security'); ?> <strong class="wps-num-warn"><?php echo (int) $cron['orphaned']; ?></strong>
            <?php if ($cron['orphaned'] > 0): ?><span class="wps-badge warn"><?php esc_html_e('Junk from removed plugins', 'wp-profiler-security'); ?></span><?php endif; ?>
        </p>

        <?php if ($cron['orphaned'] > 0): ?>
            <button class="button wps-btn-danger wps-clean-cron-all"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_cron_all')); ?>">
                <?php esc_html_e('Clean up all orphan cron tasks', 'wp-profiler-security'); ?>
            </button>
        <?php endif; ?>

        <?php if (!empty($cron['events'])): ?>
            <table class="wps-table">
                <thead><tr><th><?php esc_html_e('Hook', 'wp-profiler-security'); ?></th><th><?php esc_html_e('Next run', 'wp-profiler-security'); ?></th><th><?php esc_html_e('Recurrence', 'wp-profiler-security'); ?></th><th><?php esc_html_e('Status', 'wp-profiler-security'); ?></th><th><?php esc_html_e('Actions', 'wp-profiler-security'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($cron['events'] as $ev): ?>
                        <?php
                        $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ev['time']);
                        $recur = $ev['schedule']
                            ? (isset($schedules[$ev['schedule']]['display']) ? $schedules[$ev['schedule']]['display'] : $ev['schedule'])
                            : __('Once', 'wp-profiler-security');
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($ev['hook']); ?></code></td>
                            <td><?php echo esc_html($when); ?></td>
                            <td><?php echo esc_html($recur); ?></td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <span class="wps-badge warn"><?php esc_html_e('Orphaned', 'wp-profiler-security'); ?></span>
                                <?php elseif ($ev['overdue']): ?>
                                    <span class="wps-badge warn"><?php esc_html_e('Overdue', 'wp-profiler-security'); ?></span>
                                <?php else: ?>
                                    <span class="wps-badge ok"><?php esc_html_e('OK', 'wp-profiler-security'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <button class="button wps-btn-danger wps-clean-cron-hook"
                                            data-hook="<?php echo esc_attr($ev['hook']); ?>"
                                            data-nonce="<?php echo esc_attr(wp_create_nonce('wps_clean_cron')); ?>"><?php esc_html_e('Delete', 'wp-profiler-security'); ?></button>
                                <?php else: ?>
                                    <span class="wps-user-nodelete">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p><?php esc_html_e('There are no scheduled cron tasks.', 'wp-profiler-security'); ?></p>
        <?php endif; ?>
    </div>

    <!-- 4) Caché -->
    <div class="wps-card">
        <h2><?php esc_html_e('Cache', 'wp-profiler-security'); ?></h2>
        <?php if ($has_cache): ?>
            <p class="wps-kv"><span class="wps-badge ok"><?php esc_html_e('Cache active', 'wp-profiler-security'); ?></span></p>
        <?php else: ?>
            <p class="wps-kv"><span class="wps-badge bad"><?php esc_html_e('No cache plugin detected', 'wp-profiler-security'); ?></span></p>
        <?php endif; ?>
        <p class="wps-kv"><?php esc_html_e('Web server:', 'wp-profiler-security'); ?>
            <strong><?php echo $cache['server_software'] ? esc_html($cache['server_software']) : esc_html__('unknown', 'wp-profiler-security'); ?></strong>
            <?php if ($cache['server_is_ls']): ?><span class="wps-badge ok">LiteSpeed</span><?php endif; ?>
        </p>
        <p class="wps-kv"><?php esc_html_e('Active cache plugin(s):', 'wp-profiler-security'); ?>
            <strong><?php echo $cache['plugins'] ? esc_html(implode(', ', $cache['plugins'])) : esc_html__('none', 'wp-profiler-security'); ?></strong>
        </p>

        <?php if ($cache['page_method'] === 'litespeed'): ?>
            <p class="wps-kv"><?php esc_html_e('Page cache (LiteSpeed, server level):', 'wp-profiler-security'); ?>
                <?php if ($cache['ls_cache_enabled'] === true): ?>
                    <strong class="wps-ok-text"><?php esc_html_e('enabled', 'wp-profiler-security'); ?></strong>
                <?php elseif ($cache['ls_cache_enabled'] === false): ?>
                    <strong class="wps-bad-text"><?php esc_html_e('disabled', 'wp-profiler-security'); ?></strong>
                    <span class="wps-badge warn"><?php esc_html_e('Enable it in LiteSpeed Cache → Cache', 'wp-profiler-security'); ?></span>
                <?php else: ?>
                    <strong><?php echo $cache['server_is_ls'] ? esc_html__('active (LiteSpeed server detected)', 'wp-profiler-security') : esc_html__('state not readable', 'wp-profiler-security'); ?></strong>
                <?php endif; ?>
            </p>
            <p class="wps-kv wps-muted" style="font-size:12px;">
                <?php esc_html_e('LiteSpeed caches on the server, which is why it does not use WP_CACHE or advanced-cache.php. You can confirm it with the x-litespeed-cache: hit header on the front end.', 'wp-profiler-security'); ?>
            </p>
        <?php else: ?>
            <p class="wps-kv"><?php esc_html_e('Page cache (WP_CACHE + advanced-cache.php):', 'wp-profiler-security'); ?>
                <strong><?php echo $cache['page_cache_active'] ? esc_html__('enabled', 'wp-profiler-security') : esc_html__('not enabled', 'wp-profiler-security'); ?></strong>
            </p>
        <?php endif; ?>

        <p class="wps-kv"><?php esc_html_e('Object cache (object-cache.php):', 'wp-profiler-security'); ?>
            <strong><?php echo $cache['object_cache'] ? esc_html__('present', 'wp-profiler-security') : esc_html__('not present', 'wp-profiler-security'); ?></strong>
        </p>
    </div>

    <!-- 5) Versiones del entorno -->
    <div class="wps-card">
        <h2><?php esc_html_e('Environment versions', 'wp-profiler-security'); ?></h2>
        <table class="wps-table">
            <tbody>
                <tr><td>PHP</td><td><strong><?php echo esc_html($env['php']); ?></strong></td></tr>
                <tr><td><?php esc_html_e('Database', 'wp-profiler-security'); ?></td><td><strong><?php echo esc_html($env['db_type'] . ' — ' . $env['db_server_info']); ?></strong></td></tr>
                <tr><td>WordPress</td><td><strong><?php echo esc_html($env['wp']); ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

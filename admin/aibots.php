<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'wp-profiler-security'), 403);
}

$settings        = wps_aibots_settings();
$blocked         = $settings['blocked'];
$bots            = wps_aibots_list();
$physical_robots = file_exists(ABSPATH . 'robots.txt');
?>
<div class="wrap">
    <h1><?php esc_html_e('AI bot blocking', 'wp-profiler-security'); ?></h1>
    <p><?php esc_html_e('Mark as Blocked every AI crawler you do not want to allow. A blocked bot is added to robots.txt and, if its User-Agent is real, it is also blocked with a 403 on the front end.', 'wp-profiler-security'); ?></p>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wp-profiler-security'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wps_aibots_nonce'); ?>
        <input type="hidden" name="action" value="wps_save_aibots">

        <div class="wps-card">
            <h2><?php esc_html_e('Apply to all', 'wp-profiler-security'); ?></h2>
            <div class="wps-actions">
                <button type="button" class="button wps-btn-danger wps-bots-block-all"><?php esc_html_e('Block all', 'wp-profiler-security'); ?></button>
                <button type="button" class="button wps-bots-allow-all"><?php esc_html_e('Allow all', 'wp-profiler-security'); ?></button>
            </div>
            <p class="wps-kv"><?php esc_html_e('Saved state:', 'wp-profiler-security'); ?>
                <span class="wps-badge <?php echo count($blocked) ? 'bad' : 'ok'; ?>">
                    <?php
                    printf(
                        /* translators: %d: number of blocked bots. */
                        esc_html(_n('%d blocked', '%d blocked', count($blocked), 'wp-profiler-security')),
                        count($blocked)
                    );
                    ?>
                </span>
                <span class="wps-badge ok">
                    <?php
                    $allowed = count($bots) - count($blocked);
                    printf(
                        /* translators: %d: number of allowed bots. */
                        esc_html(_n('%d allowed', '%d allowed', $allowed, 'wp-profiler-security')),
                        $allowed
                    );
                    ?>
                </span>
            </p>

            <?php if (!empty($blocked) && $physical_robots): ?>
                <p class="wps-status wps-status--warn"><?php esc_html_e('⚠ A physical robots.txt exists in the site root: WordPress does not apply its virtual robots.txt, so the rules will not be added to that file. Edit it manually or remove it to use the virtual one. (The 403 blocking still works.)', 'wp-profiler-security'); ?></p>
            <?php endif; ?>

            <p class="wps-muted" style="font-size:12px;">
                <?php esc_html_e('The User-Agent can be spoofed, so the 403 complements — it does not replace — robots.txt. With page caching (LiteSpeed), the 403 response is marked as non-cacheable. Remember to press Save changes after modifying the selection.', 'wp-profiler-security'); ?>
            </p>
        </div>

        <div class="wps-card">
            <h2>
                <?php
                printf(
                    /* translators: %d: total number of bots in the list. */
                    esc_html__('Bots (%d)', 'wp-profiler-security'),
                    count($bots)
                );
                ?>
            </h2>
            <table class="wps-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Bot', 'wp-profiler-security'); ?></th>
                        <th><?php esc_html_e('User-Agent token', 'wp-profiler-security'); ?></th>
                        <th><?php esc_html_e('Status', 'wp-profiler-security'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bots as $token => $meta): ?>
                        <tr>
                            <td><?php echo esc_html($meta[0]); ?></td>
                            <td><code><?php echo esc_html($token); ?></code></td>
                            <td>
                                <label class="wps-toggle">
                                    <input type="checkbox" class="wps-bot-cb" name="wps_aibots_blocked[]"
                                           value="<?php echo esc_attr($token); ?>"
                                           <?php checked(in_array($token, $blocked, true)); ?>>
                                    <span class="wps-toggle-btn"
                                          data-allowed="<?php echo esc_attr__('Allowed', 'wp-profiler-security'); ?>"
                                          data-blocked="<?php echo esc_attr__('Blocked', 'wp-profiler-security'); ?>"
                                          data-do-block="<?php echo esc_attr__('Block ▸', 'wp-profiler-security'); ?>"
                                          data-do-allow="<?php echo esc_attr__('Allow ▸', 'wp-profiler-security'); ?>"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="wps-actions" style="margin-top:16px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'wp-profiler-security'); ?></button>
            </div>
        </div>
    </form>
</div>

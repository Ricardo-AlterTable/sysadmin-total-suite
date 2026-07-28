<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
}

$settings        = wps_aibots_settings();
$blocked         = $settings['blocked'];
$bots            = wps_aibots_list();
$physical_robots = file_exists(ABSPATH . 'robots.txt');
?>
<div class="wrap">
    <h1><?php esc_html_e('AI bot blocking', 'site-integrity-profiler'); ?></h1>
    <p><?php esc_html_e('Mark as Blocked every AI crawler you do not want to allow. A blocked bot is added to robots.txt and, if its User-Agent is real, it is also blocked with a 403 on the front end.', 'site-integrity-profiler'); ?></p>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'site-integrity-profiler'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wps_aibots_nonce'); ?>
        <input type="hidden" name="action" value="wps_save_aibots">

        <div class="wps-card">
            <h2><?php esc_html_e('Apply to all', 'site-integrity-profiler'); ?></h2>
            <div class="wps-actions">
                <button type="button" class="button wps-btn-danger wps-bots-block-all"><?php esc_html_e('Block all', 'site-integrity-profiler'); ?></button>
                <button type="button" class="button wps-bots-allow-all"><?php esc_html_e('Allow all', 'site-integrity-profiler'); ?></button>
            </div>
            <p class="wps-kv"><?php esc_html_e('Saved state:', 'site-integrity-profiler'); ?>
                <span class="wps-badge <?php echo count($blocked) ? 'bad' : 'ok'; ?>">
                    <?php
                    printf(
                        /* translators: %d: number of blocked bots. */
                        esc_html(_n('%d blocked', '%d blocked', count($blocked), 'site-integrity-profiler')),
                        (int) count($blocked)
                    );
                    ?>
                </span>
                <span class="wps-badge ok">
                    <?php
                    $allowed = count($bots) - count($blocked);
                    printf(
                        /* translators: %d: number of allowed bots. */
                        esc_html(_n('%d allowed', '%d allowed', $allowed, 'site-integrity-profiler')),
                        (int) $allowed
                    );
                    ?>
                </span>
            </p>

            <?php if (!empty($blocked) && $physical_robots): ?>
                <p class="wps-status wps-status--warn"><?php esc_html_e('⚠ A physical robots.txt exists in the site root: WordPress does not apply its virtual robots.txt, so the rules will not be added to that file. Edit it manually or remove it to use the virtual one. (The 403 blocking still works.)', 'site-integrity-profiler'); ?></p>
            <?php endif; ?>

            <p class="wps-muted" style="font-size:12px;">
                <?php esc_html_e('The User-Agent can be spoofed, so the 403 complements — it does not replace — robots.txt. With page caching (LiteSpeed), the 403 response is marked as non-cacheable. Remember to press Save changes after modifying the selection.', 'site-integrity-profiler'); ?>
            </p>
        </div>

        <div class="wps-card">
            <h2>
                <?php
                printf(
                    /* translators: %d: total number of bots in the list. */
                    esc_html__('Bots (%d)', 'site-integrity-profiler'),
                    count($bots)
                );
                ?>
            </h2>
            <table class="wps-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Bot', 'site-integrity-profiler'); ?></th>
                        <th><?php esc_html_e('User-Agent token', 'site-integrity-profiler'); ?></th>
                        <th><?php esc_html_e('Status', 'site-integrity-profiler'); ?></th>
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
                                          data-allowed="<?php echo esc_attr__('Allowed', 'site-integrity-profiler'); ?>"
                                          data-blocked="<?php echo esc_attr__('Blocked', 'site-integrity-profiler'); ?>"
                                          data-do-block="<?php echo esc_attr__('Block ▸', 'site-integrity-profiler'); ?>"
                                          data-do-allow="<?php echo esc_attr__('Allow ▸', 'site-integrity-profiler'); ?>"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="wps-actions" style="margin-top:16px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'site-integrity-profiler'); ?></button>
            </div>
        </div>
    </form>
</div>

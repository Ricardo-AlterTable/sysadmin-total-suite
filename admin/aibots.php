<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$settings        = stsuite_aibots_settings();
$blocked         = $settings['blocked'];
$bots            = stsuite_aibots_list();
$physical_robots = file_exists(ABSPATH . 'robots.txt');
?>
<div class="wrap">
    <h1><?php esc_html_e('AI bot blocking', 'sysadmin-total-suite'); ?></h1>
    <p><?php esc_html_e('Mark as Blocked every AI crawler you do not want to allow. A blocked bot is added to robots.txt and, if its User-Agent is real, it is also blocked with a 403 on the front end.', 'sysadmin-total-suite'); ?></p>

    <?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parámetro de solo lectura para paginación/avisos; no modifica estado. */ if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sysadmin-total-suite'); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('stsuite_aibots_nonce'); ?>
        <input type="hidden" name="action" value="stsuite_save_aibots">

        <div class="stsuite-card">
            <h2><?php esc_html_e('Apply to all', 'sysadmin-total-suite'); ?></h2>
            <div class="stsuite-actions">
                <button type="button" class="button stsuite-btn-danger stsuite-bots-block-all"><?php esc_html_e('Block all', 'sysadmin-total-suite'); ?></button>
                <button type="button" class="button stsuite-bots-allow-all"><?php esc_html_e('Allow all', 'sysadmin-total-suite'); ?></button>
            </div>
            <p class="stsuite-kv"><?php esc_html_e('Saved state:', 'sysadmin-total-suite'); ?>
                <span class="stsuite-badge <?php echo count($blocked) ? 'bad' : 'ok'; ?>">
                    <?php
                    printf(
                        /* translators: %d: number of blocked bots. */
                        esc_html(_n('%d blocked', '%d blocked', count($blocked), 'sysadmin-total-suite')),
                        (int) count($blocked)
                    );
                    ?>
                </span>
                <span class="stsuite-badge ok">
                    <?php
                    $allowed = count($bots) - count($blocked);
                    printf(
                        /* translators: %d: number of allowed bots. */
                        esc_html(_n('%d allowed', '%d allowed', $allowed, 'sysadmin-total-suite')),
                        (int) $allowed
                    );
                    ?>
                </span>
            </p>

            <?php if (!empty($blocked) && $physical_robots): ?>
                <p class="stsuite-status stsuite-status--warn"><?php esc_html_e('⚠ A physical robots.txt exists in the site root: WordPress does not apply its virtual robots.txt, so the rules will not be added to that file. Edit it manually or remove it to use the virtual one. (The 403 blocking still works.)', 'sysadmin-total-suite'); ?></p>
            <?php endif; ?>

            <p class="stsuite-muted" style="font-size:12px;">
                <?php esc_html_e('The User-Agent can be spoofed, so the 403 complements — it does not replace — robots.txt. With page caching (LiteSpeed), the 403 response is marked as non-cacheable. Remember to press Save changes after modifying the selection.', 'sysadmin-total-suite'); ?>
            </p>
        </div>

        <div class="stsuite-card">
            <h2>
                <?php
                printf(
                    /* translators: %d: total number of bots in the list. */
                    esc_html__('Bots (%d)', 'sysadmin-total-suite'),
                    count($bots)
                );
                ?>
            </h2>
            <table class="stsuite-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Bot', 'sysadmin-total-suite'); ?></th>
                        <th><?php esc_html_e('User-Agent token', 'sysadmin-total-suite'); ?></th>
                        <th><?php esc_html_e('Status', 'sysadmin-total-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bots as $token => $meta): ?>
                        <tr>
                            <td><?php echo esc_html($meta[0]); ?></td>
                            <td><code><?php echo esc_html($token); ?></code></td>
                            <td>
                                <label class="stsuite-toggle">
                                    <input type="checkbox" class="stsuite-bot-cb" name="stsuite_aibots_blocked[]"
                                           value="<?php echo esc_attr($token); ?>"
                                           <?php checked(in_array($token, $blocked, true)); ?>>
                                    <span class="stsuite-toggle-btn"
                                          data-allowed="<?php echo esc_attr__('Allowed', 'sysadmin-total-suite'); ?>"
                                          data-blocked="<?php echo esc_attr__('Blocked', 'sysadmin-total-suite'); ?>"
                                          data-do-block="<?php echo esc_attr__('Block ▸', 'sysadmin-total-suite'); ?>"
                                          data-do-allow="<?php echo esc_attr__('Allow ▸', 'sysadmin-total-suite'); ?>"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="stsuite-actions" style="margin-top:16px;">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'sysadmin-total-suite'); ?></button>
            </div>
        </div>
    </form>
</div>

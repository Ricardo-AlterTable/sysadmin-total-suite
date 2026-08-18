<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('list_users')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$stsuite_users       = get_users(['orderby' => 'registered', 'order' => 'ASC']);
$stsuite_role_names  = wp_roles()->get_names();
$stsuite_current_uid = get_current_user_id();
$stsuite_can_delete  = current_user_can('delete_users');
$stsuite_date_format = get_option('date_format') . ' ' . get_option('time_format');
?>
<div class="wrap">
    <h1><?php esc_html_e('Check WP users', 'sysadmin-total-suite'); ?></h1>
    <p>
        <?php esc_html_e('Users registered in this WordPress:', 'sysadmin-total-suite'); ?>
        <strong><?php echo count($stsuite_users); ?></strong>
    </p>
    <?php if ($stsuite_can_delete): ?>
        <p class="stsuite-status stsuite-status--bad">
            <?php
            printf(
                /* translators: %s: the word "irreversible" in bold. */
                esc_html__('⚠ Deleting a user is %s: it is permanently removed (along with the content they authored) and cannot be undone.', 'sysadmin-total-suite'),
                '<strong>' . esc_html__('irreversible', 'sysadmin-total-suite') . '</strong>'
            );
            ?>
        </p>
    <?php endif; ?>

    <table class="stsuite-users-table">
        <thead>
            <tr>
                <th><?php esc_html_e('User', 'sysadmin-total-suite'); ?></th>
                <th><?php esc_html_e('Email', 'sysadmin-total-suite'); ?></th>
                <th><?php esc_html_e('Created on', 'sysadmin-total-suite'); ?></th>
                <th><?php esc_html_e('Role / Capabilities', 'sysadmin-total-suite'); ?></th>
                <th><?php esc_html_e('Actions', 'sysadmin-total-suite'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stsuite_users as $stsuite_u): ?>
                <?php
                $stsuite_roles = array_map(function ($stsuite_r) use ($stsuite_role_names) {
                    return isset($stsuite_role_names[$stsuite_r]) ? translate_user_role($stsuite_role_names[$stsuite_r]) : $stsuite_r;
                }, (array) $stsuite_u->roles);
                $stsuite_roles_txt = $stsuite_roles ? implode(', ', $stsuite_roles) : '—';
                $stsuite_registered = $stsuite_u->user_registered ? wp_date($stsuite_date_format, strtotime($stsuite_u->user_registered . ' UTC')) : '—';
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($stsuite_u->display_name); ?></strong>
                        <br><span class="stsuite-user-login">@<?php echo esc_html($stsuite_u->user_login); ?></span>
                        <?php if ($stsuite_u->ID === $stsuite_current_uid): ?>
                            <span class="stsuite-user-you"><?php esc_html_e('(you)', 'sysadmin-total-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($stsuite_u->user_email); ?></td>
                    <td><?php echo esc_html($stsuite_registered); ?></td>
                    <td><?php echo esc_html($stsuite_roles_txt); ?></td>
                    <td>
                        <?php if ($stsuite_can_delete && $stsuite_u->ID !== $stsuite_current_uid): ?>
                            <button class="button stsuite-delete-user"
                                    data-user-id="<?php echo esc_attr($stsuite_u->ID); ?>"
                                    data-user-label="<?php echo esc_attr($stsuite_u->display_name . ' (@' . $stsuite_u->user_login . ')'); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('stsuite_delete_user')); ?>"><?php esc_html_e('Delete', 'sysadmin-total-suite'); ?></button>
                        <?php else: ?>
                            <span class="stsuite-user-nodelete">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

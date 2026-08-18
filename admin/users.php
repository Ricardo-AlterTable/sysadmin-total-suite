<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('list_users')) {
    wp_die(esc_html__('Insufficient permissions', 'sysadmin-total-suite'), 403);
}

$users       = get_users(['orderby' => 'registered', 'order' => 'ASC']);
$role_names  = wp_roles()->get_names();
$current_uid = get_current_user_id();
$can_delete  = current_user_can('delete_users');
$date_format = get_option('date_format') . ' ' . get_option('time_format');
?>
<div class="wrap">
    <h1><?php esc_html_e('Check WP users', 'sysadmin-total-suite'); ?></h1>
    <p>
        <?php esc_html_e('Users registered in this WordPress:', 'sysadmin-total-suite'); ?>
        <strong><?php echo count($users); ?></strong>
    </p>
    <?php if ($can_delete): ?>
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
            <?php foreach ($users as $u): ?>
                <?php
                $roles = array_map(function ($r) use ($role_names) {
                    return isset($role_names[$r]) ? translate_user_role($role_names[$r]) : $r;
                }, (array) $u->roles);
                $roles_txt = $roles ? implode(', ', $roles) : '—';
                $registered = $u->user_registered ? wp_date($date_format, strtotime($u->user_registered . ' UTC')) : '—';
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($u->display_name); ?></strong>
                        <br><span class="stsuite-user-login">@<?php echo esc_html($u->user_login); ?></span>
                        <?php if ($u->ID === $current_uid): ?>
                            <span class="stsuite-user-you"><?php esc_html_e('(you)', 'sysadmin-total-suite'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($u->user_email); ?></td>
                    <td><?php echo esc_html($registered); ?></td>
                    <td><?php echo esc_html($roles_txt); ?></td>
                    <td>
                        <?php if ($can_delete && $u->ID !== $current_uid): ?>
                            <button class="button stsuite-delete-user"
                                    data-user-id="<?php echo esc_attr($u->ID); ?>"
                                    data-user-label="<?php echo esc_attr($u->display_name . ' (@' . $u->user_login . ')'); ?>"
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

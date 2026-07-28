<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('list_users')) {
    wp_die(esc_html__('Insufficient permissions', 'site-integrity-profiler'), 403);
}

$users       = get_users(['orderby' => 'registered', 'order' => 'ASC']);
$role_names  = wp_roles()->get_names();
$current_uid = get_current_user_id();
$can_delete  = current_user_can('delete_users');
$date_format = get_option('date_format') . ' ' . get_option('time_format');
?>
<div class="wrap">
    <h1><?php esc_html_e('Check WP users', 'site-integrity-profiler'); ?></h1>
    <p>
        <?php esc_html_e('Users registered in this WordPress:', 'site-integrity-profiler'); ?>
        <strong><?php echo count($users); ?></strong>
    </p>
    <?php if ($can_delete): ?>
        <p class="wps-status wps-status--bad">
            <?php
            printf(
                /* translators: %s: the word "irreversible" in bold. */
                esc_html__('⚠ Deleting a user is %s: it is permanently removed (along with the content they authored) and cannot be undone.', 'site-integrity-profiler'),
                '<strong>' . esc_html__('irreversible', 'site-integrity-profiler') . '</strong>'
            );
            ?>
        </p>
    <?php endif; ?>

    <table class="wps-users-table">
        <thead>
            <tr>
                <th><?php esc_html_e('User', 'site-integrity-profiler'); ?></th>
                <th><?php esc_html_e('Email', 'site-integrity-profiler'); ?></th>
                <th><?php esc_html_e('Created on', 'site-integrity-profiler'); ?></th>
                <th><?php esc_html_e('Role / Capabilities', 'site-integrity-profiler'); ?></th>
                <th><?php esc_html_e('Actions', 'site-integrity-profiler'); ?></th>
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
                        <br><span class="wps-user-login">@<?php echo esc_html($u->user_login); ?></span>
                        <?php if ($u->ID === $current_uid): ?>
                            <span class="wps-user-you"><?php esc_html_e('(you)', 'site-integrity-profiler'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($u->user_email); ?></td>
                    <td><?php echo esc_html($registered); ?></td>
                    <td><?php echo esc_html($roles_txt); ?></td>
                    <td>
                        <?php if ($can_delete && $u->ID !== $current_uid): ?>
                            <button class="button wps-delete-user"
                                    data-user-id="<?php echo esc_attr($u->ID); ?>"
                                    data-user-label="<?php echo esc_attr($u->display_name . ' (@' . $u->user_login . ')'); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('wps_delete_user')); ?>"><?php esc_html_e('Delete', 'site-integrity-profiler'); ?></button>
                        <?php else: ?>
                            <span class="wps-user-nodelete">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

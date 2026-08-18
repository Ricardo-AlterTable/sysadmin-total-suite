<?php
// includes/users.php — Comprobación de usuarios de WordPress.
if (!defined('ABSPATH')) exit;

/**
 * Ajax: eliminar un usuario de WordPress. IRREMEDIABLE.
 * Requiere capacidad delete_users + nonce. No permite auto-borrado.
 */
add_action('wp_ajax_stsuite_delete_user', function () {
    if (!current_user_can('delete_users')) {
        wp_send_json_error(['message' => __('Insufficient permissions', 'sysadmin-total-suite')], 403);
    }
    check_ajax_referer('stsuite_delete_user', 'nonce');

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    if (!$user_id) {
        wp_send_json_error(['message' => __('Invalid user ID', 'sysadmin-total-suite')], 400);
    }

    if ($user_id === get_current_user_id()) {
        wp_send_json_error(['message' => __('You cannot delete your own user.', 'sysadmin-total-suite')], 400);
    }

    $user = get_userdata($user_id);
    if (!$user) {
        wp_send_json_error(['message' => __('The user does not exist.', 'sysadmin-total-suite')], 404);
    }

    // Meta-capability por usuario: respeta los filtros map_meta_cap/user_has_cap
    // con los que otros plugins protegen cuentas concretas.
    if (!current_user_can('delete_user', $user_id)) {
        wp_send_json_error(['message' => __('You are not allowed to delete this user.', 'sysadmin-total-suite')], 403);
    }

    // La API de borrado de usuarios no siempre está cargada en admin-ajax.
    require_once ABSPATH . 'wp-admin/includes/user.php';

    if (is_multisite()) {
        require_once ABSPATH . 'wp-admin/includes/ms.php';
        $ok = wpmu_delete_user($user_id);
    } else {
        // Sin reasignar: se elimina también el contenido del que sea autor.
        $ok = wp_delete_user($user_id);
    }

    if (!$ok) {
        wp_send_json_error(['message' => __('Could not delete the user.', 'sysadmin-total-suite')], 500);
    }

    wp_send_json_success([
        /* translators: %s: user login name. */
        'message' => sprintf(__('User deleted: %s', 'sysadmin-total-suite'), $user->user_login),
        'deleted' => $user_id,
    ]);
});

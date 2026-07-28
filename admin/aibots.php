<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Permisos insuficientes', 403);
}

$settings        = wps_aibots_settings();
$blocked         = $settings['blocked'];
$bots            = wps_aibots_list();
$physical_robots = file_exists(ABSPATH . 'robots.txt');
?>
<div class="wrap">
    <h1>Bloqueo de bots de IA</h1>
    <p>Marca como <strong>Bloqueado</strong> cada rastreador de IA que no quieras permitir.
       Un bot bloqueado se añade al <code>robots.txt</code> y, si su User-Agent es real, además se bloquea con <strong>403</strong> en el front.</p>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p>✅ Ajustes guardados.</p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wps_aibots_nonce'); ?>
        <input type="hidden" name="action" value="wps_save_aibots">

        <div class="wps-card">
            <h2>Aplicar a todos</h2>
            <div class="wps-actions">
                <button type="button" class="button wps-btn-danger wps-bots-block-all">Bloquear todos</button>
                <button type="button" class="button wps-bots-allow-all">Permitir todos</button>
            </div>
            <p class="wps-kv">Estado guardado:
                <span class="wps-badge <?php echo count($blocked) ? 'bad' : 'ok'; ?>">
                    <?php echo count($blocked); ?> bloqueados
                </span>
                <span class="wps-badge ok"><?php echo (count($bots) - count($blocked)); ?> permitidos</span>
            </p>

            <?php if (!empty($blocked) && $physical_robots): ?>
                <p class="wps-status wps-status--warn">⚠ Existe un <code>robots.txt</code> físico en la raíz: WordPress no aplica el robots.txt virtual, así que las reglas no se añadirán a ese fichero. Edítalo a mano o elimínalo para usar el virtual. (El bloqueo 403 sí funciona igualmente.)</p>
            <?php endif; ?>

            <p class="wps-muted" style="font-size:12px;">
                El User-Agent es falsificable, por eso el 403 complementa —no sustituye— a robots.txt.
                Con caché de página (LiteSpeed), la respuesta 403 se marca como no cacheable.
                Recuerda pulsar <strong>Guardar cambios</strong> tras modificar la selección.
            </p>
        </div>

        <div class="wps-card">
            <h2>Bots (<?php echo count($bots); ?>)</h2>
            <table class="wps-table">
                <thead>
                    <tr>
                        <th>Bot</th>
                        <th>Token User-Agent</th>
                        <th>Estado</th>
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
                                    <span class="wps-toggle-btn"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="wps-actions" style="margin-top:16px;">
                <button type="submit" class="button button-primary">Guardar cambios</button>
            </div>
        </div>
    </form>
</div>

<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Permisos insuficientes', 403);
}

$settings          = wps_aibots_settings();
$bots              = wps_aibots_list();
$physical_robots   = file_exists(ABSPATH . 'robots.txt');
$blockable         = array_filter($bots, fn($m) => !empty($m[1]));
?>
<div class="wrap">
    <h1>Bloqueo de bots de IA</h1>
    <p>Evita que los rastreadores de IA usen el contenido del sitio. Combina un
       <strong>opt-out en robots.txt</strong> (para los que lo respetan) y un
       <strong>bloqueo real por User-Agent (403)</strong> en el front.</p>

    <?php if (isset($_GET['saved'])): ?>
        <div class="notice notice-success is-dismissible"><p>✅ Ajustes guardados.</p></div>
    <?php endif; ?>

    <div class="wps-tunning-section">
        <h2>Configuración</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wps_aibots_nonce'); ?>
            <input type="hidden" name="action" value="wps_save_aibots">

            <p class="wps-kv">
                <label>
                    <input type="checkbox" name="wps_aibots_robots" value="1" <?php checked($settings['robots']); ?>>
                    Añadir el opt-out a <code>robots.txt</code>
                </label>
            </p>
            <p class="wps-kv">
                <label>
                    <input type="checkbox" name="wps_aibots_block" value="1" <?php checked($settings['block']); ?>>
                    Bloquear con <strong>403</strong> por User-Agent en el front
                </label>
            </p>

            <div class="wps-actions">
                <button type="submit" class="button button-primary">Guardar cambios</button>
            </div>
        </form>

        <p class="wps-kv">Estado actual:
            <span class="wps-badge <?php echo $settings['robots'] ? 'ok' : 'bad'; ?>">robots.txt <?php echo $settings['robots'] ? 'activo' : 'inactivo'; ?></span>
            <span class="wps-badge <?php echo $settings['block'] ? 'ok' : 'bad'; ?>">403 por UA <?php echo $settings['block'] ? 'activo' : 'inactivo'; ?></span>
        </p>

        <?php if ($settings['robots'] && $physical_robots): ?>
            <p class="wps-status wps-status--warn">⚠ Existe un <code>robots.txt</code> físico en la raíz: WordPress no aplica el robots.txt virtual, así que las reglas no se añadirán. Edita ese fichero a mano o elimínalo para usar el virtual.</p>
        <?php endif; ?>

        <p class="wps-muted" style="font-size:12px;">
            Nota: el User-Agent es falsificable, por eso el 403 complementa —no sustituye— a robots.txt.
            Con caché de página (LiteSpeed), la respuesta 403 se marca como no cacheable.
        </p>
    </div>

    <div class="wps-tunning-section">
        <h2>Bots cubiertos (<?php echo count($bots); ?>)</h2>
        <p class="wps-kv">Bloqueables por 403: <strong><?php echo count($blockable); ?></strong> · en robots.txt: <strong><?php echo count($bots); ?></strong></p>
        <table class="wps-tunning-table">
            <thead><tr><th>Bot</th><th>Token User-Agent</th><th>robots.txt</th><th>403</th></tr></thead>
            <tbody>
                <?php foreach ($bots as $token => $meta): ?>
                    <tr>
                        <td><?php echo esc_html($meta[0]); ?></td>
                        <td><code><?php echo esc_html($token); ?></code></td>
                        <td><span class="wps-badge ok">sí</span></td>
                        <td>
                            <?php if (!empty($meta[1])): ?>
                                <span class="wps-badge ok">sí</span>
                            <?php else: ?>
                                <span class="wps-user-nodelete">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

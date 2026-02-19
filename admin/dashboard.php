<?php
if (!defined('ABSPATH')) exit;

$analysis = get_transient('wps_last_analysis');
?>
<div class="wrap">
    <h1 style="color: #6cff5c;">Integridad del Core</h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wps_run_analysis_nonce'); ?>
        <input type="hidden" name="action" value="wps_run_analysis">
        <button type="submit" class="button button-primary">Analizar ahora</button>
    </form>

    <?php if ($analysis): ?>
        <h2 style="color: #6cff5c;">Resultado</h2>
        <?php if (empty($analysis['errors'])):
        ?>
            <p style="color:#6cff5c;">✔ Core verificado correctamente</p>
        <?php else: ?>
            <p style="color:#ff5c5c;">⚠ Se detectaron problemas en el core de WordPress</p>
        <?php endif; ?>

        <?php
        // Clasificar errores en modificados / faltantes / extras
        $modificados = [];
        $faltantes = [];
        $extras = [];

        foreach ($analysis['errors'] as $err) {
            if (strpos($err, 'Modificado:') === 0) {
                $modificados[] = preg_replace('/^Modificado:\s*/', '', $err);
            } elseif (strpos($err, 'Faltante:') === 0) {
                $faltantes[] = preg_replace('/^Faltante:\s*/', '', $err);
            } elseif (strpos($err, 'Extra:') === 0) {
                $extras[] = preg_replace('/^Extra:\s*/', '', $err);
            }
        }
        ?>

        <?php if (!empty($modificados) || !empty($faltantes)): ?>
            <h2 style="color: #6cff5c;">Archivos modificados / faltantes</h2>
            <ul id="wps-list">
                <?php foreach ($modificados as $file): ?>
                    <li>
                        <strong style="color:#ff5c5c;">[Modificado]</strong>
                        <code class="wps-file-path"><?php echo esc_html($file); ?></code>
                        <button class="button show-diff" data-path="<?php echo esc_attr($file); ?>">Mostrar cambios</button>
                        <button class="button restore-file" data-path="<?php echo esc_attr($file); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                    </li>
                <?php endforeach; ?>

                <?php foreach ($faltantes as $file): ?>
                    <li>
                        <strong style="color:#ff5c5c;">[Faltante]</strong>
                        <code class="wps-file-path"><?php echo esc_html($file); ?></code>
                        <button class="button restore-file" data-path="<?php echo esc_attr($file); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button class="button button-secondary restore-all" data-nonce="<?php echo wp_create_nonce('wps_restore_all'); ?>">Restaurar todos los modificados/faltantes</button>
        <?php else: ?>
            <p>No se han detectado archivos modificados ni faltantes.</p>
        <?php endif; ?>

        <?php if (!empty($extras)): ?>
            <h2 style="color: #6cff5c;">Archivos extra</h2>
            <button class="button button-secondary view-extras">Ver archivos extra detectados</button>
        <?php endif; ?>

    <?php else: ?>
        <p>No hay análisis disponible. Ejecuta <strong>Analizar ahora</strong> para obtener resultados.</p>
    <?php endif; ?>
</div>

<!-- Modal for diff -->
<div id="wpsDiffModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2>Diferencias del archivo</h2>
        <pre id="wpsDiffContent"></pre>
    </div>
</div>

<!-- Modal for extras -->
<div id="wpsExtrasModal" class="wps-modal-overlay" style="display:none;">
    <div class="wps-modal">
        <span class="wps-close">&times;</span>
        <h2>Archivos extra detectados</h2>
        <p>Estos archivos no pertenecen al core oficial.</p>
        <ul id="wpsExtrasList">
            <?php if (!empty($extras)):
             foreach ($extras as $file): ?>
                <li><code><?php echo esc_html($file); ?></code></li>
            <?php endforeach; endif; ?>
        </ul>
    </div>
</div>

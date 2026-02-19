<?php
if (!defined('ABSPATH')) exit;

$analysis = get_transient('wps_last_analysis');
?>
<div class="wrap">
    <h1>Integridad del Core</h1>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wps_run_analysis_nonce'); ?>
        <input type="hidden" name="action" value="wps_run_analysis">
        <button type="submit" class="button button-primary">Analizar ahora</button>
    </form>

    <?php if ($analysis): ?>
        <h2>Resultado</h2>
        <?php if (empty($analysis['errors'])): ?>
            <p style="color:green;">✔ Core verificado correctamente</p>
        <?php else: ?>
            <p style="color:#dba617;">⚠ Se detectaron problemas en el core de WordPress</p>
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
            <h2>Archivos modificados / faltantes</h2>
            <ul>
                <?php foreach ($modificados as $file): ?>
                    <li>
                        <strong style="color:#b35;">[Modificado]</strong>
                        <code><?php echo esc_html($file); ?></code>
                        <button class="button show-diff" data-path="<?php echo esc_attr($file); ?>">Mostrar cambios</button>
                        <button class="button restore-file" data-path="<?php echo esc_attr($file); ?>">Restaurar</button>
                    </li>
                <?php endforeach; ?>

                <?php foreach ($faltantes as $file): ?>
                    <li>
                        <strong style="color:#b35;">[Faltante]</strong>
                        <code><?php echo esc_html($file); ?></code>
                        <button class="button restore-file" data-path="<?php echo esc_attr($file); ?>">Restaurar</button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button class="button button-secondary restore-all">Restaurar todos los modificados/faltantes</button>
        <?php else: ?>
            <p>No se han detectado archivos modificados ni faltantes.</p>
        <?php endif; ?>

        <?php if (!empty($extras)): ?>
            <h2>Archivos extra</h2>
            <button class="button button-secondary view-extras">Ver archivos extra detectados</button>
        <?php endif; ?>

    <?php else: ?>
        <p>No hay análisis disponible. Ejecuta <strong>Analizar ahora</strong> para obtener resultados.</p>
    <?php endif; ?>
</div>

<!-- Modal bonito para diff -->
<div id="wpsDiffModal" style="display:none;">
    <div class="wps-modal-content" style="max-width:90%;max-height:80vh;overflow:auto;padding:12px;background:#fff;border-radius:6px;box-shadow:0 6px 30px rgba(0,0,0,.3);">
        <span class="wps-close" style="cursor:pointer;float:right;font-size:18px;padding:4px 8px">×</span>
        <h2>Diff</h2>
        <pre id="wpsDiffContent" style="white-space:pre-wrap;"></pre>
    </div>
</div>

<!-- Modal para extras -->
<div id="wpsExtrasModal" style="display:none;">
    <div class="wps-modal-content" style="max-width:90%;max-height:80vh;overflow:auto;padding:12px;background:#fff;border-radius:6px;box-shadow:0 6px 30px rgba(0,0,0,.3);">
        <span class="wps-close" style="cursor:pointer;float:right;font-size:18px;padding:4px 8px">×</span>
        <h2>Archivos extra detectados</h2>
        <p>Estos archivos no pertenecen al core oficial.</p>
        <ul id="wpsExtrasList">
            <?php if (!empty($extras)): ?>
                <?php foreach ($extras as $file): ?>
                    <li><code><?php echo esc_html($file); ?></code></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

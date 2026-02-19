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

        <?php if (!empty($modificados) || !empty($faltantes)):
            $all_files = [];
            foreach ($modificados as $file) {
                $all_files[] = ['type' => 'Modificado', 'path' => $file];
            }
            foreach ($faltantes as $file) {
                $all_files[] = ['type' => 'Faltante', 'path' => $file];
            }

            // Pagination settings
            $per_page_options = [20, 50, 100];
            $per_page = isset($_GET['per_page']) && in_array($_GET['per_page'], [20, 50, 100, 'all']) ? $_GET['per_page'] : 20;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $total_items = count($all_files);

            if ($per_page === 'all') {
                $paged_files = $all_files;
                $total_pages = 1;
                $current_page = 1;
            } else {
                $per_page_int = intval($per_page);
                $total_pages = ceil($total_items / $per_page_int);
                $current_page = min($current_page, $total_pages);
                $offset = ($current_page - 1) * $per_page_int;
                $paged_files = array_slice($all_files, $offset, $per_page_int, true);
            }
        ?>
            <h2 style="color: #6cff5c;">Archivos modificados / faltantes</h2>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                        <label for="per_page" class="screen-reader-text">Archivos por página</label>
                        <select name="per_page" id="per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($per_page, 'all'); ?>>Todos</option>
                        </select>
                        <input type="submit" class="button" value="Aplicar">
                    </form>
                </div>
                <?php if ($per_page !== 'all' && $total_pages > 1) { ?>
                <h2 class="screen-reader-text">Navegación de archivos</h2>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_items; ?> elementos</span>
                    <span class="pagination-links">
                        <?php
                        // WordPress's paginate_links function is great for this
                        echo paginate_links( array(
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $current_page,
                        ) );
                        ?>
                    </span>
                </div>
                <?php } ?>
                <br class="clear">
            </div>

            <ul id="wps-list">
                <?php foreach ($paged_files as $file_data): ?>
                    <li>
                        <?php if ($file_data['type'] === 'Modificado'): ?>
                            <strong style="color:#ff5c5c;">[Modificado]</strong>
                            <code class="wps-file-path"><?php echo esc_html($file_data['path']); ?></code>
                            <button class="button show-diff" data-path="<?php echo esc_attr($file_data['path']); ?>">Mostrar cambios</button>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file_data['path']); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                        <?php else: ?>
                            <strong style="color:#ff5c5c;">[Faltante]</strong>
                            <code class="wps-file-path"><?php echo esc_html($file_data['path']); ?></code>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file_data['path']); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                        <?php endif; ?>
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
        <button class="button button-secondary" id="wps-extras-modal-close">Cerrar</button>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const extrasModal = document.getElementById('wpsExtrasModal');
    if (!extrasModal) return;

    const viewExtrasButton = document.querySelector('.view-extras');
    const closeExtrasModalButton = document.getElementById('wps-extras-modal-close');
    const closeSpan = extrasModal.querySelector('.wps-close');

    function openExtrasModal() {
        extrasModal.style.display = 'block';
    }

    function closeExtrasModal() {
        extrasModal.style.display = 'none';
    }

    if (viewExtrasButton) {
        viewExtrasButton.addEventListener('click', openExtrasModal);
    }

    if (closeExtrasModalButton) {
        closeExtrasModalButton.addEventListener('click', closeExtrasModal);
    }

    if (closeSpan) {
        closeSpan.addEventListener('click', closeExtrasModal);
    }

    // Also close modal if user clicks outside of the modal content
    window.addEventListener('click', function(event) {
        if (event.target == extrasModal) {
            closeExtrasModal();
        }
    });
});
</script>

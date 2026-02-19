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
        <?php if (empty($analysis['errors'])): ?>
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

        // Pagination for modified/missing files
        if (!empty($modificados) || !empty($faltantes)) {
            $all_files = array_merge(
                array_map(function($f) { return ['type' => 'Modificado', 'path' => $f]; }, $modificados),
                array_map(function($f) { return ['type' => 'Faltante', 'path' => $f]; }, $faltantes)
            );

            $per_page = isset($_GET['per_page']) && in_array($_GET['per_page'], [20, 50, 100, 'all']) ? $_GET['per_page'] : 20;
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $total_items = count($all_files);

            if ($per_page === 'all') {
                $paged_files = $all_files;
                $total_pages = 1;
            } else {
                $per_page_int = intval($per_page);
                $total_pages = ceil($total_items / $per_page_int);
                $current_page = min($current_page, $total_pages);
                $paged_files = array_slice($all_files, ($current_page - 1) * $per_page_int, $per_page_int);
            }
        }

        // Pagination for extra files
        if (!empty($extras)) {
            $extras_per_page = isset($_GET['extras_per_page']) && in_array($_GET['extras_per_page'], [20, 50, 100, 'all']) ? $_GET['extras_per_page'] : 20;
            $extras_current_page = isset($_GET['extras_paged']) ? max(1, intval($_GET['extras_paged'])) : 1;
            $extras_total_items = count($extras);

            if ($extras_per_page === 'all') {
                $paged_extras = $extras;
                $extras_total_pages = 1;
            } else {
                $extras_per_page_int = intval($extras_per_page);
                $extras_total_pages = ceil($extras_total_items / $extras_per_page_int);
                $extras_current_page = min($extras_current_page, $extras_total_pages);
                $paged_extras = array_slice($extras, ($extras_current_page - 1) * $extras_per_page_int, $extras_per_page_int);
            }
        }
        ?>

        <?php if (!empty($paged_files)): ?>
            <h2 style="color: #6cff5c;">Archivos modificados / faltantes</h2>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                        <select name="per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($per_page, 'all'); ?>>Todos</option>
                        </select>
                        <input type="submit" class="button" value="Aplicar">
                    </form>
                </div>
                <?php if ($per_page !== 'all' && $total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_items; ?> elementos</span>
                    <span class="pagination-links">
                        <?php echo paginate_links(['total' => $total_pages, 'current' => $current_page]); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wps-list">
                <?php foreach ($paged_files as $file): ?>
                    <li>
                        <?php if ($file['type'] === 'Modificado'): ?>
                            <strong style="color:#ff5c5c;">[Modificado]</strong>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button show-diff" data-path="<?php echo esc_attr($file['path']); ?>">Mostrar cambios</button>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                        <?php else: ?>
                            <strong style="color:#ff5c5c;">[Faltante]</strong>
                            <code class="wps-file-path"><?php echo esc_html($file['path']); ?></code>
                            <button class="button restore-file" data-path="<?php echo esc_attr($file['path']); ?>" data-nonce="<?php echo wp_create_nonce('wps_restore_file'); ?>">Restaurar</button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button class="button button-secondary restore-all" data-nonce="<?php echo wp_create_nonce('wps_restore_all'); ?>">Restaurar todos los modificados/faltantes</button>
        <?php elseif (!empty($modificados) || !empty($faltantes)): ?>
             <h2 style="color: #6cff5c;">Archivos modificados / faltantes</h2>
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

        <?php if (!empty($paged_extras)): ?>
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
                        <input type="hidden" name="open_extras_modal" value="true" />
                        <?php foreach ($_GET as $key => $value) {
                            if (!in_array($key, ['page', 'open_extras_modal', 'extras_per_page', 'extras_paged'])) {
                                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
                            }
                        } ?>
                        <select name="extras_per_page" onchange="this.form.submit()">
                            <option value="20" <?php selected($extras_per_page, 20); ?>>20</option>
                            <option value="50" <?php selected($extras_per_page, 50); ?>>50</option>
                            <option value="100" <?php selected($extras_per_page, 100); ?>>100</option>
                            <option value="all" <?php selected($extras_per_page, 'all'); ?>>Todos</option>
                        </select>
                        <input type="submit" class="button" value="Aplicar">
                    </form>
                </div>
                <?php if ($extras_per_page !== 'all' && $extras_total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $extras_total_items; ?> items</span>
                    <span class="pagination-links">
                        <?php
                        $pagination_args = [
                            'base' => add_query_arg(['extras_paged' => '%#%', 'open_extras_modal' => 'true']),
                            'format' => '',
                            'total' => $extras_total_pages,
                            'current' => $extras_current_page,
                        ];
                        echo paginate_links($pagination_args);
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <ul id="wpsExtrasList">
                <?php foreach ($paged_extras as $file): ?>
                    <li><code><?php echo esc_html($file); ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <button class="button button-secondary" id="wps-extras-modal-close">Cerrar</button>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const diffModal = document.getElementById('wpsDiffModal');
    const extrasModal = document.getElementById('wpsExtrasModal');

    // Function to close modals
    function closeModal(modal) {
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Close buttons
    const closeButtons = document.querySelectorAll('.wps-close');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            closeModal(this.closest('.wps-modal-overlay'));
        });
    });
    
    // Show diff modal
    const showDiffButtons = document.querySelectorAll('.show-diff');
    showDiffButtons.forEach(button => {
        button.addEventListener('click', function() {
            const path = this.dataset.path;
            // AJAX call to get diff
            // ... (existing logic)
            if(diffModal) diffModal.style.display = 'block';
        });
    });

    // Extras modal
    if (extrasModal) {
        const viewExtrasButton = document.querySelector('.view-extras');
        const closeExtrasModalButton = document.getElementById('wps-extras-modal-close');

        function openExtrasModal() {
            extrasModal.style.display = 'block';
        }

        if (viewExtrasButton) {
            viewExtrasButton.addEventListener('click', openExtrasModal);
        }

        if (closeExtrasModalButton) {
            closeExtrasModalButton.addEventListener('click', function() {
                closeModal(extrasModal);
            });
        }
        
        // Open modal if query param is set
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open_extras_modal') === 'true') {
            openExtrasModal();
        }
    }

    // Close modal on overlay click
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('wps-modal-overlay')) {
            closeModal(event.target);
        }
    });
});
</script>

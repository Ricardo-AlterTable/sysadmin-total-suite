<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Permisos insuficientes', 403);
}

$plugins   = wps_wpo_plugins_info();
$autoload   = wps_wpo_autoload_stats();
$transients = wps_wpo_transient_stats();
$cron       = wps_wpo_cron_info();
$cache      = wps_wpo_cache_info();
$env        = wps_wpo_env_versions();
$schedules  = wp_get_schedules();

// Umbrales orientativos.
$autoload_warn = $autoload['total_bytes'] > 1024 * 1024;      // > 1 MB autoload
$plugins_warn  = $plugins['active'] > 20;                     // muchos plugins activos
$has_cache     = !empty($cache['plugins']) || $cache['page_cache_active'];
?>
<div class="wrap">
    <h1>WPO</h1>
    <p>Web Performance Optimization: chequeo rápido de rendimiento del sitio.</p>

    <!-- 1) Plugins -->
    <div class="wps-card">
        <h2>Plugins que afectan a la carga</h2>
        <p class="wps-kv">Instalados: <strong><?php echo (int) $plugins['total']; ?></strong> ·
           Activos: <strong><?php echo (int) $plugins['active']; ?></strong> ·
           Inactivos: <strong><?php echo (int) $plugins['inactive']; ?></strong>
           <span class="wps-badge <?php echo $plugins_warn ? 'warn' : 'ok'; ?>">
               <?php echo $plugins_warn ? 'Muchos plugins activos' : 'Cantidad razonable'; ?>
           </span>
        </p>
        <p>Los plugins <strong>activos</strong> se cargan en cada petición; son los que impactan en la velocidad:</p>
        <table class="wps-table">
            <thead><tr><th>Plugin activo</th><th>Versión</th></tr></thead>
            <tbody>
                <?php foreach ($plugins['active_list'] as $p): ?>
                    <tr>
                        <td><?php echo esc_html($p['name']); ?></td>
                        <td><?php echo esc_html($p['version'] ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 2) Basura en wp_options -->
    <div class="wps-card">
        <h2>Opciones autoload y basura en wp_options</h2>
        <p class="wps-kv">
            Opciones autoload: <strong><?php echo (int) $autoload['count']; ?></strong> ·
            Tamaño total autoload: <strong><?php echo esc_html(size_format($autoload['total_bytes'], 2)); ?></strong>
            <span class="wps-badge <?php echo $autoload_warn ? 'warn' : 'ok'; ?>">
                <?php echo $autoload_warn ? 'Elevado (>1 MB)' : 'Correcto'; ?>
            </span>
        </p>
        <p class="wps-kv">
            Transitorios con caducidad: <strong><?php echo (int) $transients['total']; ?></strong> ·
            Caducados (basura): <strong class="wps-num-warn"><?php echo (int) $transients['expired']; ?></strong>
        </p>

        <button class="button button-secondary wps-clean-transients"
                data-nonce="<?php echo wp_create_nonce('wps_clean_transients'); ?>">
            Limpiar transitorios caducados
        </button>

        <?php if (!empty($autoload['largest'])): ?>
            <p style="margin-top:15px;">Mayores opciones autoload (revisa si alguna es de un plugin ya desinstalado):</p>
            <table class="wps-table">
                <thead><tr><th>option_name</th><th>Tamaño</th></tr></thead>
                <tbody>
                    <?php foreach ($autoload['largest'] as $o): ?>
                        <tr>
                            <td><code><?php echo esc_html($o['option_name']); ?></code></td>
                            <td><?php echo esc_html(size_format((int) $o['sz'], 2)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="wps-muted" style="font-size:12px;">El borrado automático de estas opciones no se ofrece por seguridad: podrían pertenecer a plugins activos. Elimínalas manualmente solo si reconoces que son de un plugin ya retirado.</p>
        <?php endif; ?>
    </div>

    <!-- 3) WP-Cron -->
    <div class="wps-card">
        <h2>WP-Cron</h2>
        <p class="wps-kv">
            Modo:
            <?php if ($cron['disabled']): ?>
                <strong>WP-Cron interno desactivado</strong> (<code>DISABLE_WP_CRON = true</code>)
                <span class="wps-badge warn">Debe haber un cron real del sistema llamando a wp-cron.php</span>
            <?php else: ?>
                <strong>WP-Cron interno activo</strong>
                <span class="wps-badge ok">Se dispara con las visitas</span>
            <?php endif; ?>
        </p>
        <p class="wps-kv">
            Tareas programadas: <strong><?php echo (int) $cron['total']; ?></strong> ·
            Atrasadas: <strong><?php echo (int) $cron['overdue']; ?></strong>
            <?php if ($cron['overdue'] > 0): ?><span class="wps-badge warn">Hay tareas atrasadas</span><?php endif; ?>
            · Huérfanas: <strong class="wps-num-warn"><?php echo (int) $cron['orphaned']; ?></strong>
            <?php if ($cron['orphaned'] > 0): ?><span class="wps-badge warn">Basura de plugins retirados</span><?php endif; ?>
        </p>

        <?php if ($cron['orphaned'] > 0): ?>
            <button class="button wps-btn-danger wps-clean-cron-all"
                    data-nonce="<?php echo wp_create_nonce('wps_clean_cron_all'); ?>">
                Limpiar todas las tareas cron huérfanas
            </button>
        <?php endif; ?>

        <?php if (!empty($cron['events'])): ?>
            <table class="wps-table">
                <thead><tr><th>Hook</th><th>Próxima ejecución</th><th>Recurrencia</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($cron['events'] as $ev): ?>
                        <?php
                        $when = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ev['time']);
                        $recur = $ev['schedule']
                            ? (isset($schedules[$ev['schedule']]['display']) ? $schedules[$ev['schedule']]['display'] : $ev['schedule'])
                            : 'Una vez';
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($ev['hook']); ?></code></td>
                            <td><?php echo esc_html($when); ?></td>
                            <td><?php echo esc_html($recur); ?></td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <span class="wps-badge warn">Huérfana</span>
                                <?php elseif ($ev['overdue']): ?>
                                    <span class="wps-badge warn">Atrasada</span>
                                <?php else: ?>
                                    <span class="wps-badge ok">OK</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ev['orphan']): ?>
                                    <button class="button wps-btn-danger wps-clean-cron-hook"
                                            data-hook="<?php echo esc_attr($ev['hook']); ?>"
                                            data-nonce="<?php echo wp_create_nonce('wps_clean_cron'); ?>">Eliminar</button>
                                <?php else: ?>
                                    <span class="wps-user-nodelete">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No hay tareas cron programadas.</p>
        <?php endif; ?>
    </div>

    <!-- 4) Caché -->
    <div class="wps-card">
        <h2>Caché</h2>
        <?php if ($has_cache): ?>
            <p class="wps-kv"><span class="wps-badge ok">Caché activa</span></p>
        <?php else: ?>
            <p class="wps-kv"><span class="wps-badge bad">Sin plugin de caché detectado</span></p>
        <?php endif; ?>
        <p class="wps-kv">Servidor web:
            <strong><?php echo $cache['server_software'] ? esc_html($cache['server_software']) : 'desconocido'; ?></strong>
            <?php if ($cache['server_is_ls']): ?><span class="wps-badge ok">LiteSpeed</span><?php endif; ?>
        </p>
        <p class="wps-kv">Plugin(s) de caché activos:
            <strong><?php echo $cache['plugins'] ? esc_html(implode(', ', $cache['plugins'])) : 'ninguno'; ?></strong>
        </p>

        <?php if ($cache['page_method'] === 'litespeed'): ?>
            <p class="wps-kv">Caché de página (LiteSpeed, a nivel de servidor):
                <?php if ($cache['ls_cache_enabled'] === true): ?>
                    <strong class="wps-ok-text">activada</strong>
                <?php elseif ($cache['ls_cache_enabled'] === false): ?>
                    <strong class="wps-bad-text">desactivada</strong>
                    <span class="wps-badge warn">Actívala en LiteSpeed Cache → Cache</span>
                <?php else: ?>
                    <strong><?php echo $cache['server_is_ls'] ? 'activa (servidor LiteSpeed detectado)' : 'estado no legible'; ?></strong>
                <?php endif; ?>
            </p>
            <p class="wps-kv wps-muted" style="font-size:12px;">
                LiteSpeed cachea en el servidor, por eso no usa <code>WP_CACHE</code> ni <code>advanced-cache.php</code>.
                Puedes confirmarlo con la cabecera <code>x-litespeed-cache: hit</code> en el front.
            </p>
        <?php else: ?>
            <p class="wps-kv">Caché de página (WP_CACHE + advanced-cache.php):
                <strong><?php echo $cache['page_cache_active'] ? 'activada' : 'no activada'; ?></strong>
            </p>
        <?php endif; ?>

        <p class="wps-kv">Caché de objetos (object-cache.php):
            <strong><?php echo $cache['object_cache'] ? 'presente' : 'no presente'; ?></strong>
        </p>
    </div>

    <!-- 5) Versiones del entorno -->
    <div class="wps-card">
        <h2>Versiones del entorno</h2>
        <table class="wps-table">
            <tbody>
                <tr><td>PHP</td><td><strong><?php echo esc_html($env['php']); ?></strong></td></tr>
                <tr><td>Base de datos</td><td><strong><?php echo esc_html($env['db_type'] . ' — ' . $env['db_server_info']); ?></strong></td></tr>
                <tr><td>WordPress</td><td><strong><?php echo esc_html($env['wp']); ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

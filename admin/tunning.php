<?php
if (!defined('ABSPATH')) exit;

if (!current_user_can('manage_options')) {
    wp_die('Permisos insuficientes', 403);
}

$plugins   = wps_tunning_plugins_info();
$autoload   = wps_tunning_autoload_stats();
$transients = wps_tunning_transient_stats();
$cache      = wps_tunning_cache_info();
$env        = wps_tunning_env_versions();

// Umbrales orientativos.
$autoload_warn = $autoload['total_bytes'] > 1024 * 1024;      // > 1 MB autoload
$plugins_warn  = $plugins['active'] > 20;                     // muchos plugins activos
$has_cache     = !empty($cache['plugins']) || $cache['page_cache_enabled'];
?>
<div class="wrap">
    <h1 style="color: #6cff5c;">Tunning</h1>
    <p>Chequeo rápido de rendimiento del sitio.</p>

    <!-- 1) Plugins -->
    <div class="wps-tunning-section">
        <h2>Plugins que afectan a la carga</h2>
        <p class="wps-kv">Instalados: <strong><?php echo (int) $plugins['total']; ?></strong> ·
           Activos: <strong><?php echo (int) $plugins['active']; ?></strong> ·
           Inactivos: <strong><?php echo (int) $plugins['inactive']; ?></strong>
           <span class="wps-badge <?php echo $plugins_warn ? 'warn' : 'ok'; ?>">
               <?php echo $plugins_warn ? 'Muchos plugins activos' : 'Cantidad razonable'; ?>
           </span>
        </p>
        <p>Los plugins <strong>activos</strong> se cargan en cada petición; son los que impactan en la velocidad:</p>
        <table class="wps-tunning-table">
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
    <div class="wps-tunning-section">
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
            Caducados (basura): <strong style="color:#ffcf5c;"><?php echo (int) $transients['expired']; ?></strong>
        </p>

        <button class="button button-secondary wps-clean-transients"
                data-nonce="<?php echo wp_create_nonce('wps_clean_transients'); ?>">
            Limpiar transitorios caducados
        </button>

        <?php if (!empty($autoload['largest'])): ?>
            <p style="margin-top:15px;">Mayores opciones autoload (revisa si alguna es de un plugin ya desinstalado):</p>
            <table class="wps-tunning-table">
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
            <p style="color:#9aa7b3; font-size:12px;">El borrado automático de estas opciones no se ofrece por seguridad: podrían pertenecer a plugins activos. Elimínalas manualmente solo si reconoces que son de un plugin ya retirado.</p>
        <?php endif; ?>
    </div>

    <!-- 3) Caché -->
    <div class="wps-tunning-section">
        <h2>Caché</h2>
        <?php if ($has_cache): ?>
            <p class="wps-kv"><span class="wps-badge ok">Caché activa</span></p>
        <?php else: ?>
            <p class="wps-kv"><span class="wps-badge bad">Sin plugin de caché detectado</span></p>
        <?php endif; ?>
        <p class="wps-kv">Plugin(s) de caché activos:
            <strong><?php echo $cache['plugins'] ? esc_html(implode(', ', $cache['plugins'])) : 'ninguno'; ?></strong>
        </p>
        <p class="wps-kv">Caché de página (WP_CACHE + advanced-cache.php):
            <strong><?php echo $cache['page_cache_enabled'] ? 'activada' : 'no activada'; ?></strong>
        </p>
        <p class="wps-kv">Caché de objetos (object-cache.php):
            <strong><?php echo $cache['object_cache'] ? 'presente' : 'no presente'; ?></strong>
        </p>
    </div>

    <!-- 4) Versiones del entorno -->
    <div class="wps-tunning-section">
        <h2>Versiones del entorno</h2>
        <table class="wps-tunning-table">
            <tbody>
                <tr><td>PHP</td><td><strong><?php echo esc_html($env['php']); ?></strong></td></tr>
                <tr><td>Base de datos</td><td><strong><?php echo esc_html($env['db_type'] . ' — ' . $env['db_server_info']); ?></strong></td></tr>
                <tr><td>WordPress</td><td><strong><?php echo esc_html($env['wp']); ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

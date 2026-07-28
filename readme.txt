=== WP Profiler & Security ===
Contributors: ricardoaltertable
Tags: security, performance, integrity, profiling, ai-bots
Requires at least: 5.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integridad del core, profiling de tiempos, gestión de usuarios, WPO y bloqueo de bots de IA en un único panel de administración.

== Description ==

WP Profiler & Security reúne, en un panel de administración unificado, varias utilidades de mantenimiento para WordPress:

* **Integridad del core**: verifica los archivos de WordPress contra los checksums oficiales, muestra las diferencias (diff) de los modificados y permite restaurarlos desde el paquete oficial (con copia de seguridad opcional). También detecta y permite eliminar archivos no reconocidos por el core, y gestiona las copias de seguridad creadas (listado con fecha y hora, restauración y purga individual o total).
* **Profiling**: mide el tiempo de carga de la portada (core, plugins, tema, SQL y llamadas HTTP externas), guarda un histórico y lo muestra en gráficas.
* **Comprobar usuarios WP**: lista los usuarios (nombre, alta, rol) y permite eliminarlos con doble confirmación.
* **WPO (Web Performance Optimization)**: plugins activos, tamaño de las opciones autoload, transitorios caducados, estado de WP-Cron (incluida limpieza de tareas huérfanas), detección de caché y versiones del entorno (PHP, MySQL/MariaDB, WordPress).
* **Bloqueo de bots de IA**: opt-out en robots.txt y bloqueo real por User-Agent (403) para rastreadores de IA, seleccionable bot a bot.

Todas las acciones requieren permisos de administrador y usan nonces. Varias operaciones son destructivas (borrado de usuarios, de archivos extra y de tareas cron) y avisan de ello antes de ejecutarse.

== External services ==

Este plugin se conecta a servicios de WordPress.org para poder verificar y restaurar el core:

1. **API de checksums de WordPress.org** (`https://api.wordpress.org/core/checksums/1.0/`): se consulta al ejecutar el análisis de integridad para obtener las sumas de comprobación oficiales de tu versión e idioma. Se envían la versión de WordPress y el locale del sitio.
2. **Descargas de WordPress.org** (`https://downloads.wordpress.org/` y los sitios de idioma `https://*.wordpress.org/`): se descarga el paquete ZIP oficial de tu versión únicamente cuando pides ver diferencias (diff) o restaurar un archivo del core. Se envía la versión y el idioma para construir la URL del paquete.

No se envían datos personales ni se contacta con ningún otro servicio de terceros. Estos servicios los presta la WordPress Foundation; consulta https://wordpress.org/about/privacy/ .

== Installation ==

1. Sube la carpeta del plugin a `/wp-content/plugins/wp-profiler-security/` o instálalo desde el escritorio de WordPress.
2. Activa el plugin desde el menú **Plugins**.
3. Accede al menú **WP Profiler & Security** y sus secciones: Integridad, Profiling, Comprobar usuarios WP, WPO y Bloqueo bots IA.

Opcional: para medir el tiempo de las consultas SQL en Profiling, añade `define('SAVEQUERIES', true);` en `wp-config.php` (no recomendado dejarlo activo permanentemente en producción).

== Frequently Asked Questions ==

= ¿Necesita alguna extensión de PHP? =
Sí, la extensión ZipArchive para poder mostrar diferencias y restaurar archivos del core desde el paquete oficial.

= ¿Por qué en Profiling "MySQL" aparece como N/A? =
El tiempo de las consultas solo está disponible si la constante `SAVEQUERIES` está activada. El número de consultas y el resto de tiempos se muestran igualmente.

= La caché de página aparece como "no activada" pero uso LiteSpeed =
LiteSpeed cachea a nivel de servidor y no usa `WP_CACHE` ni `advanced-cache.php`. El plugin detecta ese caso y muestra su estado real; puedes confirmarlo con la cabecera `x-litespeed-cache: hit`.

= ¿El bloqueo por User-Agent es infalible? =
No. El User-Agent es falsificable, por eso el bloqueo 403 complementa —no sustituye— al opt-out de robots.txt.

== Screenshots ==

1. Integridad del core: resultado del análisis y archivos modificados/extra.
2. Profiling: última medición y evolución en gráficas.
3. WPO: plugins, opciones autoload, WP-Cron, caché y versiones.
4. Bloqueo de bots de IA: selección por bot.

== Changelog ==

= 3.8 =
* Nuevo gestor de copias de seguridad en la sección Integridad: lista las copias con su fecha y hora, permite restaurar cada archivo desde su copia y eliminar copias de forma individual, por lote o todas a la vez.
* Se detectan también las copias guardadas por versiones anteriores en la raíz del sitio y se avisa de que esa ubicación es accesible por web.

= 3.7 =
* Corregido: el botón "Lanzar prueba" de Profiling no registraba mediciones. La URL firmada se generaba con esc_js(), que convierte los "&" en "&amp;" y rompía la cadena de consulta, de modo que el nonce no llegaba al servidor.

= 3.6 =
* **Seguridad (importante):** el visor de diferencias ya no interpreta como HTML el contenido del archivo analizado. Antes, un archivo del core manipulado con código JavaScript podía ejecutarlo en el panel al pulsar "Mostrar cambios" (XSS almacenado).
* Copias de seguridad con nombre de carpeta aleatorio y extensión neutralizada (.bak); caché de ZIP protegida frente a acceso directo.
* La prueba de profiling exige nonce y permisos: antes cualquier visitante podía forzar escrituras en la base de datos saltándose la caché.
* La validación de rutas se realiza tras normalizarlas; se respaldan las tareas cron antes de eliminarlas; borrado de usuario con la meta-capability delete_user.
* Los archivos ocultos y de configuración de la raíz ya no se marcan como "extra" (evita borrados accidentales de .user.ini, ads.txt, verificaciones de buscadores...).
* Robustez: el ZIP oficial se abre una sola vez por petición y se elevan los límites en operaciones largas.

= 3.5 =
* Seguridad: resolución de rutas endurecida (admite archivos faltantes sin usar realpath), restauración y comparación limitadas a rutas del core, y copias de seguridad movidas a uploads con protección de acceso.
* Corregido: la restauración de archivos marcados como "Faltante" fallaba siempre.
* Añadido uninstall.php para limpiar opciones, transitorios y caché al desinstalar.

= 3.4 =
* Preparación para el directorio de WordPress.org: readme.txt, cabeceras (Requires at least / Requires PHP / Text Domain) y Chart.js empaquetada localmente (sin CDN externo).

= 3.0 - 3.3 =
* Bloqueo de bots de IA con toggle Permitir/Bloquear por bot.
* Rediseño de la interfaz a panel moderno.
* Sección Tunning renombrada a WPO.

= 2.0 - 2.9 =
* Nuevas secciones: Comprobar usuarios WP, Tunning/WPO (rendimiento, wp_options, WP-Cron, caché) y Bloqueo de bots de IA.
* Copia de seguridad opcional en la restauración; borrado de archivos extra.

= 1.6 - 1.9 =
* Integridad limitada al core real; alineación de idioma en diff/restauración; correcciones de nonces y de la interfaz.

== Upgrade Notice ==

= 3.8 =
Añade el gestor de copias de seguridad (listado con fecha, restauración y purga) en la sección Integridad.

= 3.7 =
Corrige el botón "Lanzar prueba" de Profiling, que dejó de registrar mediciones en la 3.6.

= 3.6 =
Actualización de seguridad recomendada: corrige un XSS almacenado en el visor de diferencias y endurece varias operaciones destructivas.

= 3.5 =
Correcciones de seguridad y arreglo de la restauración de archivos faltantes. Las copias de seguridad pasan a guardarse en uploads, protegidas del acceso web.

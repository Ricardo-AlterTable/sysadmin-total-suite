# Sysadmin Total Suite

Plugin de WordPress para **administradores** que reúne, en un panel con estética de *control panel* moderno, cinco utilidades de mantenimiento del sitio:

1. **Integridad del core** — verifica los archivos de WordPress contra los checksums oficiales y muestra las diferencias de los modificados. **Informe de solo lectura**: el plugin no modifica ningún archivo.
2. **Profiling** — mide el tiempo de carga del home (core, plugins, tema, SQL y HTTP) con histórico y gráficas.
3. **Comprobar usuarios WP** — lista los usuarios (nombre, alta, rol) y permite eliminarlos con doble confirmación.
4. **WPO** — chequeo rápido de rendimiento: plugins activos, basura en `wp_options`, WP-Cron, caché y versiones del entorno.
5. **Bloqueo de bots de IA** — opt-out en `robots.txt` y bloqueo real por User-Agent (403) para rastreadores de IA.

> ⚠️ Herramienta de administración. Todas las acciones requieren capacidades de administrador y están protegidas con nonces. El plugin **no escribe ni borra nada dentro de los directorios del core** (`wp-admin`, `wp-includes`, raíz). Las operaciones que sí cambian algo (borrar un usuario, limpiar transitorios caducados, eliminar tareas cron huérfanas) actúan sobre la base de datos y piden confirmación.

---

## Características

### 🛡️ Integridad del core (solo lectura)
- Descarga los checksums oficiales de `api.wordpress.org` para tu versión y **locale**.
- Comprueba **solo el core real**: `wp-admin/`, `wp-includes/` y los archivos sueltos de la raíz. Ignora `wp-content/` (temas y plugins) para evitar falsos positivos.
- Clasifica los hallazgos en **Modificados**, **Faltantes** y **Extra** (archivos no reconocidos por el core).
- Mensajes separados: el estado del core no se mezcla con el de los archivos extra.
- **Ver diferencias** (diff) de cualquier archivo modificado contra el original del ZIP oficial, descargado según el **idioma** del sitio (p. ej. `es_ES`) con *fallback* al internacional.
- Los ficheros ocultos y de configuración de la raíz (`.user.ini`, `ads.txt`, verificaciones de buscadores…) no se marcan como intrusos.
- Para **reparar** el core, enlaza a *Escritorio → Actualizaciones → Reinstalar ahora*, que es el mecanismo propio de WordPress.

### 📊 Profiling
- Mide el tiempo de la portada desde el **inicio real de la petición** (`$timestart`): Core, Plugins, Tema, MySQL y llamadas HTTP externas.
- Cuenta las consultas SQL (`get_num_queries()`) y, si `SAVEQUERIES` está activo, su tiempo.
- Guarda un **histórico** de las últimas 20 mediciones y lo muestra en **gráficas** (Chart.js, empaquetada localmente).
- Botón para **lanzar una prueba** manual del home, firmado con nonce.

### 👥 Comprobar usuarios WP
- Tabla con **nombre**, `@login`, **correo**, **fecha de creación**, **rol/permisos** y acciones.
- **Eliminar usuario** con **doble confirmación** y aviso de que es irremediable (elimina también el contenido del que sea autor).
- No permite el auto-borrado; usa la API nativa (`wp_delete_user` / `wpmu_delete_user`) y comprueba la meta-capability `delete_user`.

### ⚙️ WPO (Web Performance Optimization)
- **Plugins que afectan a la carga**: instalados / activos / inactivos y listado de los activos.
- **Basura en `wp_options`**: tamaño de las opciones *autoload*, mayores opciones y conteo de **transitorios caducados**, con botón para limpiarlos (`delete_expired_transients`).
- **WP-Cron**: modo (interno vs `DISABLE_WP_CRON`), tareas programadas, **atrasadas** y **huérfanas** (de plugins retirados), con limpieza segura (solo hooks sin acción registrada) y respaldo previo de la programación.
- **Caché**: detecta plugins de caché conocidos y el estado real de **LiteSpeed** (caché a nivel de servidor), además de caché de página (PHP) y de objetos.
- **Versiones del entorno**: PHP, MySQL/MariaDB (cadena real del servidor) y WordPress.

### 🤖 Bloqueo de bots de IA
- **Toggle Permitir/Bloquear por bot** (por defecto *Permitido*), con botones para **aplicar a todos**.
- Al bloquear un bot se le aplican **ambas capas** automáticamente: entrada en **`robots.txt`** (vía filtro `robots_txt`) y, si su User-Agent es real, **bloqueo 403** en el front.
- Cobertura de ~20 rastreadores: GPTBot, ClaudeBot, PerplexityBot, CCBot, Google-Extended, Applebot-Extended, Amazonbot, Bytespider, Meta, etc.
- Cabecera `X-Robots-Tag: noai, noimageai` como señal adicional de opt-out.
- Avisos integrados: UA falsificable, `robots.txt` físico y compatibilidad con caché de página (respuesta 403 no cacheable).

---

## Requisitos

- WordPress 5.3 o superior.
- PHP 7.4 o superior.
- Extensión **ZipArchive** habilitada (necesaria para mostrar el diff contra el paquete oficial).
- Conexión saliente a `api.wordpress.org` y `*.wordpress.org` (checksums y ZIP oficiales).

---

## Instalación

1. Copia la carpeta del plugin en `wp-content/plugins/sysadmin-total-suite/`.
2. Actívalo desde **Plugins** en el escritorio de WordPress.
3. Encontrarás el menú **Sysadmin Total Suite** con sus secciones: Integridad, Profiling, Comprobar usuarios WP, WPO y Bloqueo bots IA.

> Si usas un plugin/servidor de caché (p. ej. **LiteSpeed**), purga la caché tras actualizar el plugin para que se sirvan los assets nuevos.

Opcional, para medir el **tiempo** de las consultas SQL en Profiling, añade en `wp-config.php`:

```php
define('SAVEQUERIES', true);
```

(No recomendado dejarlo activo permanentemente en producción por su coste.)

### Idioma

El plugin está internacionalizado con **inglés como idioma base**. Solo se incluye el fichero `.pot`: las traducciones se gestionan a través de [translate.wordpress.org](https://translate.wordpress.org/), como exige el directorio oficial. Si quieres una traducción propia mientras tanto, genera el `.mo` desde el `.pot` y colócalo en `wp-content/languages/plugins/`.

---

## Uso

- **Integridad → Analizar ahora**: ejecuta la verificación. Revisa modificados/faltantes/extra y usa *Mostrar cambios* para ver el diff. *Purgar Caché* borra la caché interna del plugin (transient y ZIP descargados).
- **Profiling → Lanzar prueba**: genera una medición del home y actualiza las gráficas.
- **Comprobar usuarios WP**: revisa la tabla y elimina usuarios si es necesario (doble confirmación).
- **WPO**: revisa los indicadores y usa los botones de limpieza (transitorios caducados, tareas cron huérfanas).
- **Bloqueo bots IA**: marca los bots a bloquear y guarda.

---

## Seguridad

- Todas las acciones comprueban capacidades (`manage_options`, `list_users`, `delete_users`) y **nonces**.
- **No se escribe ni se borra nada en `wp-admin`, `wp-includes` ni la raíz del sitio.** Lo único que el plugin escribe es la caché del paquete oficial, en su propia carpeta dentro de `uploads/` (resuelta en tiempo de ejecución con `wp_upload_dir()`) y protegida frente a acceso directo con `.htaccess` e `index.php`.
- Las rutas se resuelven con un helper propio (`stsuite_resolve_site_path()`) que normaliza `.`/`..` de forma léxica, valida el prefijo `ABSPATH` y comprueba `realpath` del directorio padre para impedir escapes por enlaces simbólicos. La validación se hace **después** de normalizar, de modo que `wp-admin/../wp-content/...` se rechaza.
- El diff se limita a rutas del **core real** (`stsuite_is_core_path()`).
- El visor de diferencias inserta el contenido del archivo con `textContent`/nodos DOM, **nunca como HTML**: un archivo del core manipulado con `<script>` no puede ejecutarse en el panel.
- La prueba de profiling (`stsuite_profiling_test`) exige **nonce y `manage_options`**; las visitas normales al home escriben como máximo una medición por minuto.
- La limpieza de cron solo actúa sobre hooks **sin acción registrada** (huérfanos) y **respalda** la programación en `stsuite_cron_backup` antes de tocar nada.
- Las consultas a base de datos usan `$wpdb->prepare()` cuando llevan parámetros, con `esc_like()` en los `LIKE`.
- Las URL de descarga se construyen con la versión y el idioma **saneados** (`[0-9.]` y `[a-zA-Z_]`), evitando la inyección de host (SSRF).
- Sin `eval()`, `base64_decode()`, ejecución de comandos ni includes dinámicos.

---

## Estructura del proyecto

```
sysadmin-total-suite.php   # Bootstrap: menús, assets, análisis, helpers de rutas seguras
uninstall.php              # Limpieza de opciones/transitorios/caché al desinstalar
readme.txt                 # Ficha para el directorio de WordPress.org (en inglés)
.distignore                # Exclusiones del paquete distribuible
languages/
  sysadmin-total-suite.pot # Plantilla de traducción
includes/
  diff.php                 # AJAX: diff; descarga del ZIP oficial
  profiler.php             # Medición de tiempos y guardado del histórico
  users.php                # AJAX: eliminar usuario
  wpo.php                  # Helpers de rendimiento (WPO) + AJAX: limpiar transitorios / cron
  aibots.php               # robots.txt + bloqueo 403 por User-Agent de bots de IA
admin/
  dashboard.php            # Página Integridad
  profiler.php             # Página Profiling
  users.php                # Página Comprobar usuarios WP
  wpo.php                  # Página WPO
  aibots.php               # Página Bloqueo de bots de IA
  assets/
    admin.css              # Tema del panel (paleta en variables CSS)
    admin.js               # Lógica de UI (AJAX, modales, confirmaciones)
    profiler.js            # Lógica de la pantalla de Profiling (gráficas)
    chart.min.js           # Chart.js 4.5.1 empaquetada localmente
```

Convenciones: todas las funciones, opciones, transitorios, nonces y acciones AJAX usan el prefijo **`stsuite_`**; las constantes, `STSUITE_`; las clases CSS, `stsuite-`. El tema visual está centralizado en variables CSS al inicio de `admin/assets/admin.css`, por lo que cambiar la paleta es trivial.

---

## Notas y limitaciones

- El diff solo aplica a archivos que **pertenecen al ZIP oficial** de tu versión e idioma; los temas por defecto que WordPress ya no empaqueta no aparecen ahí.
- El plugin **no repara** archivos: esa función corresponde a *Escritorio → Actualizaciones → Reinstalar ahora*. Fue una decisión de cumplimiento con las directrices del directorio, que prohíben a los plugins escribir en los directorios del core.
- La detección de la caché de **LiteSpeed** lee la opción `litespeed.conf.cache` y `SERVER_SOFTWARE`; LiteSpeed cachea a nivel de servidor, por lo que no usa `WP_CACHE`/`advanced-cache.php`.
- El profiling basado en *milestones* (`plugins_loaded`, `after_setup_theme`, `template_redirect`) es orientativo; el tiempo total es *wall-clock* real.
- La detección de tareas cron "huérfanas" es heurística: un plugin puede registrar su hook solo en el front y parecer huérfano en el admin. Por eso se respalda la programación antes de eliminar.

---

## Changelog

- **5.0** — La sección de Integridad pasa a ser un **informe de solo lectura**: se eliminan la restauración de archivos, la restauración desde copia, el gestor de copias y el borrado de archivos extra, siguiendo las indicaciones del equipo de revisión de WordPress.org. Se enlaza a la reinstalación oficial del core.
- **4.2** — Renombrado a *Sysadmin Total Suite* con prefijo `stsuite_` en todo el código; scripts en línea movidos a ficheros encolados; Chart.js actualizada a 4.5.1; se retiran los ficheros de traducción y `load_plugin_textdomain()`; menú en posición no destacada.
- **3.9 – 4.1** — Cumplimiento de Plugin Check (0 errores / 0 advertencias): comentarios `translators`, escapado de salidas, API de ficheros de WordPress, `LIKE` preparados, `wp_safe_redirect()` y saneado de variables de servidor. Readme en inglés.
- **3.8** — Gestor de copias de seguridad con listado por fecha, restauración y purga.
- **3.7** — Corregido el botón "Lanzar prueba" de Profiling (la URL firmada se rompía por el escapado de `&`).
- **3.6** — Corrección de un **XSS almacenado** en el visor de diferencias, endurecimiento de copias y caché, nonce en la prueba de profiling, validación de rutas tras normalizar y menos falsos positivos en "archivos extra".
- **3.5** — Endurecimiento de la resolución de rutas, arreglo de la restauración de archivos faltantes y `uninstall.php`.
- **3.4** — Preparación para WordPress.org: `readme.txt`, cabeceras, Chart.js local e internacionalización.
- **3.2 – 3.3** — Sección Tunning renombrada a WPO.
- **3.0 – 3.1** — Bloqueo de bots de IA con toggle Permitir/Bloquear por bot.
- **2.6 – 2.8** — Rediseño de la interfaz a panel moderno y nueva sección de bloqueo de bots de IA.
- **2.2 – 2.5** — Secciones Comprobar usuarios WP y Tunning/WPO; detección de caché de LiteSpeed.
- **1.6 – 2.1** — Integridad limitada al core real, alineación de idioma en el diff, correcciones de nonces y de la interfaz.

---

## Estado en el directorio de WordPress.org

El plugin está preparado para el directorio: **Plugin Check en 0 errores y 0 advertencias**, sin recursos externos (CDN), internacionalizado y con el nombre y el slug libres de términos restringidos. La versión 5.0 incorpora los cambios pedidos por el equipo de revisión.

---

## Autor

**Ricardo Morales** — [@Ricardo-AlterTable](https://github.com/Ricardo-AlterTable) en GitHub.

## Licencia

Publicado bajo **GPL-2.0-or-later**, la licencia estándar de los plugins de WordPress.

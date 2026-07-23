# WP Profiler & Security

Plugin de WordPress para **administradores** que reúne, en un panel con estética de *control panel* moderno, cuatro utilidades de mantenimiento del sitio:

1. **Integridad del core** — verifica los archivos de WordPress contra los checksums oficiales y permite comparar y restaurar los modificados.
2. **Profiling** — mide el tiempo de carga del home (core, plugins, tema, SQL y HTTP) con histórico y gráficas.
3. **Comprobar usuarios WP** — lista los usuarios (nombre, alta, rol) y permite eliminarlos con doble confirmación.
4. **Tunning** — chequeo rápido de rendimiento: plugins activos, basura en `wp_options`, WP-Cron, caché y versiones del entorno.
5. **Bloqueo de bots de IA** — opt-out en `robots.txt` y bloqueo real por User-Agent (403) para rastreadores de IA.

> ⚠️ Herramienta de administración. Todas las acciones requieren capacidades de administrador y están protegidas con nonces. Varias operaciones son **destructivas** (borrado de usuarios, archivos extra y tareas cron); úsalas con conocimiento de causa.

---

## Características

### 🛡️ Integridad del core
- Descarga los checksums oficiales de `api.wordpress.org` para tu versión y **locale**.
- Comprueba **solo el core real**: `wp-admin/`, `wp-includes/` y los archivos sueltos de la raíz. Ignora `wp-content/` (temas y plugins) para evitar falsos positivos.
- Clasifica los hallazgos en **Modificados**, **Faltantes** y **Extra** (archivos no reconocidos por el core).
- Mensajes separados: el estado del core no se mezcla con el de los archivos extra.
- **Ver diferencias** (diff) de cualquier archivo modificado contra el original del ZIP oficial.
- **Restaurar** archivos individualmente o en lote, con **copia de seguridad opcional** (te pregunta y, si aceptas, indica la ruta del backup).
- **Eliminar** archivos extra (individual o en masa) con confirmación de irreversibilidad.
- Descarga el ZIP acorde al **idioma** del sitio (p. ej. `es_ES`) con *fallback* al internacional, de modo que el diff y la restauración sean coherentes con los checksums.

### 📊 Profiling
- Mide el tiempo de la portada desde el **inicio real de la petición** (`$timestart`): Core, Plugins, Tema, MySQL y llamadas HTTP externas.
- Cuenta las consultas SQL (`get_num_queries()`) y, si `SAVEQUERIES` está activo, su tiempo.
- Guarda un **histórico** de las últimas 20 mediciones y lo muestra en **gráficas** (Chart.js).
- Botón para **lanzar una prueba** manual del home.

### 👥 Comprobar usuarios WP
- Tabla con **nombre**, `@login`, **correo**, **fecha de creación**, **rol/permisos** y acciones.
- **Eliminar usuario** con **doble confirmación** y aviso de que es irremediable (elimina también el contenido del que sea autor).
- No permite el auto-borrado; usa la API nativa (`wp_delete_user` / `wpmu_delete_user`).

### ⚙️ Tunning
- **Plugins que afectan a la carga**: instalados / activos / inactivos y listado de los activos.
- **Basura en `wp_options`**: tamaño de las opciones *autoload*, mayores opciones y conteo de **transitorios caducados**, con botón para limpiarlos (`delete_expired_transients`).
- **WP-Cron**: modo (interno vs `DISABLE_WP_CRON`), tareas programadas, **atrasadas** y **huérfanas** (de plugins retirados), con limpieza segura (solo hooks sin acción registrada).
- **Caché**: detecta plugins de caché conocidos y el estado real de **LiteSpeed** (caché a nivel de servidor), además de caché de página (PHP) y de objetos.
- **Versiones del entorno**: PHP, MySQL/MariaDB (cadena real del servidor) y WordPress.

### 🤖 Bloqueo de bots de IA
- **Opt-out en `robots.txt`** (vía filtro `robots_txt`) para los rastreadores de IA que lo respetan: GPTBot, ClaudeBot, PerplexityBot, CCBot, Google-Extended, Applebot-Extended, Amazonbot, Bytespider, Meta, etc.
- **Bloqueo real por User-Agent (403)** en el front para forzar el bloqueo de los que ignoran `robots.txt`.
- Cabecera `X-Robots-Tag: noai, noimageai` como señal adicional de opt-out.
- Interruptores independientes (robots.txt / 403), lista de bots cubiertos y avisos (UA falsificable, `robots.txt` físico, compatibilidad con caché de página).

---

## Requisitos

- WordPress 5.3 o superior (usa `wp_date`, Site Health APIs, etc.).
- PHP 7.4 o superior.
- Extensión **ZipArchive** habilitada (necesaria para el diff/restauración del core).
- Conexión saliente a `api.wordpress.org` y `*.wordpress.org` (checksums y ZIP oficiales).

---

## Instalación

1. Copia la carpeta del plugin en `wp-content/plugins/wp-profiler-security/`.
2. Actívalo desde **Plugins** en el escritorio de WordPress.
3. Encontrarás el menú **WP Profiler & Security** con sus secciones (Integridad, Profiling, Comprobar usuarios WP, Tunning).

> Si usas un plugin/servidor de caché (p. ej. **LiteSpeed**), purga la caché tras actualizar el plugin para que se sirvan los assets nuevos.

Opcional, para medir el **tiempo** de las consultas SQL en Profiling, añade en `wp-config.php`:

```php
define('SAVEQUERIES', true);
```

(No recomendado dejarlo activo permanentemente en producción por su coste.)

---

## Uso

- **Integridad → Analizar ahora**: ejecuta la verificación. Revisa modificados/faltantes/extra y usa *Mostrar cambios*, *Restaurar* o *Eliminar* según corresponda. *Purgar Caché* borra la caché interna del plugin (transient y ZIP descargados).
- **Profiling → Lanzar prueba**: genera una medición del home y actualiza las gráficas.
- **Comprobar usuarios WP**: revisa la tabla y elimina usuarios si es necesario (doble confirmación).
- **Tunning**: revisa los indicadores y usa los botones de limpieza (transitorios caducados, tareas cron huérfanas).

---

## Seguridad

- Todas las acciones comprueban capacidades (`manage_options`, `list_users`, `delete_users`) y **nonces**.
- Las rutas de archivo se validan contra *path traversal* y se limitan a `ABSPATH`.
- El borrado de archivos extra solo admite rutas que el último análisis marcó como *Extra*.
- La limpieza de cron solo actúa sobre hooks **sin acción registrada** (huérfanos); nunca sobre tareas activas.
- Las consultas a base de datos usan `$wpdb->prepare()` cuando llevan parámetros.

---

## Estructura del proyecto

```
wp-profiler-security.php   # Bootstrap: menús, assets, acción de análisis
includes/
  diff.php                 # AJAX: diff, restaurar, eliminar extra; descarga del ZIP oficial
  profiler.php             # Medición de tiempos y guardado del histórico
  users.php                # AJAX: eliminar usuario
  tunning.php              # Helpers de rendimiento + AJAX: limpiar transitorios / cron
  aibots.php               # robots.txt + bloqueo 403 por User-Agent de bots de IA
admin/
  dashboard.php            # Página Integridad
  profiler.php             # Página Profiling (gráficas)
  users.php                # Página Comprobar usuarios WP
  tunning.php              # Página Tunning
  aibots.php               # Página Bloqueo de bots de IA
  assets/
    admin.css              # Tema del panel (paleta en variables CSS)
    admin.js               # Lógica de UI (AJAX, modales, confirmaciones)
```

El tema visual está centralizado en variables CSS al inicio de `admin/assets/admin.css`, por lo que cambiar la paleta es trivial.

---

## Notas y limitaciones

- El diff/restauración del core solo aplica a archivos que **pertenecen al ZIP oficial** de tu versión e idioma; los temas por defecto que WordPress ya no empaqueta no son restaurables desde el core.
- La detección de la caché de **LiteSpeed** lee la opción `litespeed.conf.cache` y `SERVER_SOFTWARE`; LiteSpeed cachea a nivel de servidor, por lo que no usa `WP_CACHE`/`advanced-cache.php`.
- El profiling basado en *milestones* (`plugins_loaded`, `after_setup_theme`, `template_redirect`) es orientativo; el tiempo total es *wall-clock* real.

---

## Changelog

- **2.8** — Nueva sección **Bloqueo de bots de IA** (robots.txt + 403 por User-Agent).
- **2.6 – 2.7** — Rediseño completo de la interfaz a panel moderno (estética terminal conservada solo en el diff); ajustes de espaciado.
- **2.5** — Detección de la caché de LiteSpeed a nivel de servidor.
- **2.4** — Comprobación y limpieza de WP-Cron (tareas huérfanas) en Tunning.
- **2.3** — Nueva sección **Tunning** (plugins, `wp_options`, caché, versiones).
- **2.2** — Nueva sección **Comprobar usuarios WP**.
- **2.0 – 2.1** — Mensajes de core separados de archivos extra; borrado de extras (individual/masivo); estilos de botones.
- **1.9** — Copia de seguridad opcional en la restauración (con ruta del backup).
- **1.8** — Alineación de *locale* en diff/restauración y mejoras de legibilidad.
- **1.7** — La integridad se limita al core real (`wp-admin`, `wp-includes`, raíz).
- **1.6** — Correcciones de nonces en restauración, modales y medición del profiler.

---

## Autor

**Ricardo Morales** — [@Ricardo-AlterTable](https://github.com/Ricardo-AlterTable) en GitHub.

## Licencia

Publicado bajo **GPL-2.0-or-later** (la licencia estándar de los plugins de WordPress). Consulta el archivo [`LICENSE`](LICENSE) para el texto completo.

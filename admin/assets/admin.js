jQuery(document).ready(function ($) {
    if (typeof WPS_AJAX === 'undefined') {
        console.warn('WPS_AJAX no definido. Asegúrate de que wp_localize_script() se ejecutó.');
    }

    // Extraer el mensaje de error real del servidor.
    // wp_send_json_error() responde con códigos 4xx/5xx, así que jQuery entra en
    // el callback "error" y el cuerpo JSON queda en jqXHR.responseJSON.
    function serverErrorMessage(jqXHR, textStatus) {
        if (textStatus === 'timeout') {
            return "La operación ha superado el tiempo de espera.";
        }
        const data = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data;
        if (data && data.message) {
            return data.message + (data.details ? " (" + data.details + ")" : "");
        }
        return "Error de comunicación con el servidor.";
    }

    // Función para colorear diff
    function formatDiff(text) {
        if (!text) return '';
        return text.split("\n").map(function (line) {
            if (line.startsWith('+')) {
                return '<span class="diff-add">' + line + '</span>';
            } else if (line.startsWith('-')) {
                return '<span class="diff-del">' + line + '</span>';
            } else {
                return '<span class="diff-ctx">' + line + '</span>';
            }
        }).join("\n");
    }

    // Mostrar cambios (diff)
    $(document).on('click', '.show-diff', function (e) {
        e.preventDefault();
        const path = $(this).data('path');
        $("#wpsDiffContent").html("<em>Cargando diff...</em>");
        $("#wpsDiffModal").css('display', 'flex');

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_show_diff',
                nonce: WPS_AJAX.nonce,
                path: path
            },
            timeout: 300000, // 5 minutes
            success: function (res) {
                if (res && res.success) {
                    $("#wpsDiffContent").html(formatDiff(res.data.diff));
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'Respuesta inválida';
                    $("#wpsDiffContent").text("Error: " + msg);
                }
            },
            error: function (jqXHR, textStatus) {
                $("#wpsDiffContent").text("Error: " + serverErrorMessage(jqXHR, textStatus));
            }
        });
    });

    // Restaurar archivo
    $(document).on('click', '.restore-file', function (e) {
        e.preventDefault();
        if (!confirm("¿Seguro que deseas restaurar este archivo desde el core original?")) return;

        const doBackup = confirm(
            "¿Quieres guardar una copia de seguridad del archivo actual antes de sobrescribirlo?\n\n" +
            "Aceptar = sí, hacer copia\n" +
            "Cancelar = restaurar sin copia"
        );

        const button = $(this);
        const path = button.data('path');
        button.prop('disabled', true).text('Restaurando...');

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_restore_file',
                nonce: button.data('nonce'),
                path: path,
                backup: doBackup ? '1' : '0'
            },
            timeout: 300000, // 5 minutes
            success: function (res) {
                if (res && res.success) {
                    let msg = "Archivo restaurado: " + path;
                    if (res.data && res.data.backup) {
                        msg += "\n\nCopia de seguridad guardada en:\n" + res.data.backup;
                    } else {
                        msg += "\n\n(No se creó copia de seguridad)";
                    }
                    alert(msg);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'Error al restaurar';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Restaurar');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Restaurar');
            }
        });
    });

    // Restaurar todos
    $(document).on('click', '.restore-all', function (e) {
        e.preventDefault();
        if (!confirm("¿Seguro que deseas restaurar TODOS los archivos modificados/faltantes?")) return;

        const button = $(this);

        const files = $('.restore-file').map(function() {
            return $(this).data('path');
        }).get();

        if (files.length === 0) {
            alert("No hay archivos para restaurar. Si hay archivos marcados como faltantes, es posible que deba corregir el plugin para incluirlos en la restauración masiva.");
            return;
        }

        const doBackup = confirm(
            "¿Quieres guardar una copia de seguridad de los archivos actuales antes de sobrescribirlos?\n\n" +
            "Aceptar = sí, hacer copia\n" +
            "Cancelar = restaurar sin copia"
        );

        button.prop('disabled', true).text('Restaurando...');

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_restore_all_files',
                nonce: button.data('nonce'),
                files: files,
                backup: doBackup ? '1' : '0'
            },
            timeout: 600000, // 10 minutes for all files
            success: function (res) {
                if (res && res.success) {
                    let message = "Operación completada.\n\n";
                    if (res.data.restored && res.data.restored.length > 0) {
                        message += "Archivos restaurados (" + res.data.restored.length + "):\n" + res.data.restored.join("\n");
                    }

                    if (res.data.backup_dir) {
                        message += "\n\nCopia de seguridad guardada en:\n" + res.data.backup_dir;
                    } else {
                        message += "\n\n(No se creó copia de seguridad)";
                    }

                    const errorKeys = Object.keys(res.data.errors);
                    if (errorKeys.length > 0) {
                        message += "\n\nArchivos con errores ("+ errorKeys.length +"):\n";
                        errorKeys.forEach(function(file) {
                            message += file + ": " + res.data.errors[file] + "\n";
                        });
                    }
                    alert(message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'Error al restaurar todos';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Restaurar Todos');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Restaurar Todos');
            }
        });
    });

    // Ver extras
    $(document).on('click', '.view-extras', function (e) {
        e.preventDefault();
        $("#wpsExtrasModal").css('display', 'flex');
    });

    // Eliminar un archivo extra (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-extra', function (e) {
        e.preventDefault();
        const button = $(this);
        const path = button.data('path');
        if (!confirm(
            "¿Eliminar este archivo?\n\n" + path + "\n\n" +
            "⚠ Esta acción es IRREVERSIBLE: el archivo se borra de forma permanente y no se puede deshacer."
        )) return;

        button.prop('disabled', true).text('Eliminando...');
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_delete_extra',
                nonce: button.data('nonce'),
                path: path
            },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('li').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'No se pudo eliminar';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Eliminar');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Eliminar');
            }
        });
    });

    // Eliminar TODOS los archivos extra (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-all-extras', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(
            "¿Eliminar TODOS los archivos no reconocidos por WordPress?\n\n" +
            "⚠ Esta acción es IRREVERSIBLE: los archivos se borran de forma permanente y no se pueden deshacer."
        )) return;

        button.prop('disabled', true).text('Eliminando...');
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_delete_all_extras',
                nonce: button.data('nonce')
            },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    let message = "Operación completada.\n\n";
                    if (res.data.deleted && res.data.deleted.length > 0) {
                        message += "Eliminados (" + res.data.deleted.length + "):\n" + res.data.deleted.join("\n");
                    }
                    const errorKeys = Object.keys(res.data.errors || {});
                    if (errorKeys.length > 0) {
                        message += "\n\nCon errores (" + errorKeys.length + "):\n";
                        errorKeys.forEach(function (file) {
                            message += file + ": " + res.data.errors[file] + "\n";
                        });
                    }
                    alert(message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'No se pudo eliminar';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Eliminar todos los archivos extra');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Eliminar todos los archivos extra');
            }
        });
    });

    // Eliminar usuario de WordPress (doble confirmación, IRREMEDIABLE)
    $(document).on('click', '.wps-delete-user', function (e) {
        e.preventDefault();
        const button = $(this);
        const uid = button.data('user-id');
        const label = button.data('user-label') || ('#' + uid);

        // 1ª confirmación
        if (!confirm(
            "¿Seguro que quieres BORRAR el usuario?\n\n" + label + "\n\n" +
            "⚠ Esta acción es IRREMEDIABLE: el usuario se elimina permanentemente y no se puede deshacer."
        )) return;

        // 2ª confirmación
        if (!confirm(
            "Confirmación final.\n\n" +
            "Se eliminará el usuario \"" + label + "\" y el contenido del que sea autor.\n\n" +
            "¿Continuar con el borrado definitivo?"
        )) return;

        button.prop('disabled', true).text('Eliminando...');
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_delete_user',
                nonce: button.data('nonce'),
                user_id: uid
            },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('tr').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'No se pudo eliminar el usuario';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Eliminar');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Eliminar');
            }
        });
    });

    // Tunning: limpiar transitorios caducados
    $(document).on('click', '.wps-clean-transients', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm("¿Limpiar los transitorios caducados?\n\nEs una operación segura: son datos temporales caducados y WordPress los regenera cuando los necesite.")) return;

        button.prop('disabled', true).text('Limpiando...');
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_clean_transients',
                nonce: button.data('nonce')
            },
            timeout: 120000,
            success: function (res) {
                if (res && res.success) {
                    alert("Transitorios caducados eliminados: " + (res.data.removed || 0));
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : 'No se pudo limpiar';
                    alert("Error: " + msg);
                    button.prop('disabled', false).text('Limpiar transitorios caducados');
                }
            },
            error: function (jqXHR, textStatus) {
                alert("Error: " + serverErrorMessage(jqXHR, textStatus));
                button.prop('disabled', false).text('Limpiar transitorios caducados');
            }
        });
    });

    // Cerrar modales (botón X y botón "Cerrar")
    $(document).on('click', '.wps-close, #wps-extras-modal-close', function () {
        $(this).closest('.wps-modal-overlay').css('display', 'none');
    });

    // Cerrar al hacer clic en el fondo del overlay
    $(document).on('click', '.wps-modal-overlay', function (e) {
        if (e.target === this) {
            $(this).css('display', 'none');
        }
    });
});

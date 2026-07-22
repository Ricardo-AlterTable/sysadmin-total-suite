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

        const button = $(this);
        const path = button.data('path');
        button.prop('disabled', true).text('Restaurando...');

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_restore_file',
                nonce: button.data('nonce'),
                path: path
            },
            timeout: 300000, // 5 minutes
            success: function (res) {
                if (res && res.success) {
                    alert("Archivo restaurado: " + path);
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
        button.prop('disabled', true).text('Restaurando...');

        const files = $('.restore-file').map(function() {
            return $(this).data('path');
        }).get();

        if (files.length === 0) {
            alert("No hay archivos para restaurar. Si hay archivos marcados como faltantes, es posible que deba corregir el plugin para incluirlos en la restauración masiva.");
            button.prop('disabled', false).text('Restaurar Todos');
            return;
        }

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: {
                action: 'wps_restore_all_files',
                nonce: button.data('nonce'),
                files: files
            },
            timeout: 600000, // 10 minutes for all files
            success: function (res) {
                if (res && res.success) {
                    let message = "Operación completada.\n\n";
                    if (res.data.restored && res.data.restored.length > 0) {
                        message += "Archivos restaurados (" + res.data.restored.length + "):\n" + res.data.restored.join("\n");
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

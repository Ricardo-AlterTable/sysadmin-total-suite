jQuery(document).ready(function ($) {
    if (typeof WPS_AJAX === 'undefined') {
        console.warn('WPS_AJAX no definido. Asegúrate de que wp_localize_script() se ejecutó.');
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
        $("#wpsDiffModal").fadeIn();

        $.post(WPS_AJAX.ajax_url, {
            action: 'wps_show_diff',
            nonce: WPS_AJAX.nonce,
            path: path
        }, function (res) {
            if (res && res.success) {
                $("#wpsDiffContent").html(formatDiff(res.data.diff));
            } else {
                const msg = res && res.data && res.data.message ? res.data.message : 'Respuesta inválida';
                $("#wpsDiffContent").text("Error: " + msg);
            }
        }).fail(function () {
            $("#wpsDiffContent").text("Error de comunicación con el servidor.");
        });
    });

    // Restaurar archivo
    $(document).on('click', '.restore-file', function (e) {
        e.preventDefault();
        if (!confirm("¿Seguro que deseas restaurar este archivo desde el core original?")) return;

        const path = $(this).data('path');
        $.post(WPS_AJAX.ajax_url, {
            action: 'wps_restore_file',
            nonce: WPS_AJAX.nonce,
            path: path
        }, function (res) {
            if (res && res.success) {
                alert("Archivo restaurado: " + path);
                location.reload();
            } else {
                const msg = res && res.data && res.data.message ? res.data.message : 'Error al restaurar';
                alert("Error: " + msg);
            }
        }).fail(function () {
            alert("Error de comunicación con el servidor.");
        });
    });

    // Restaurar todos
    $(document).on('click', '.restore-all', function (e) {
        e.preventDefault();
        if (!confirm("¿Seguro que deseas restaurar TODOS los archivos modificados/faltantes?")) return;

        $.post(WPS_AJAX.ajax_url, {
            action: 'wps_restore_all_files',
            nonce: WPS_AJAX.nonce
        }, function (res) {
            if (res && res.success) {
                alert("Todos los archivos restaurados.");
                location.reload();
            } else {
                const msg = res && res.data && res.data.message ? res.data.message : 'Error al restaurar todos';
                alert("Error: " + msg);
            }
        }).fail(function () {
            alert("Error de comunicación con el servidor.");
        });
    });

    // Ver extras
    $(document).on('click', '.view-extras', function (e) {
        e.preventDefault();
        $("#wpsExtrasModal").fadeIn();
    });

    // Cerrar modales
    $(document).on('click', '.wps-close', function () {
        $(this).closest('.wps-modal-content').parent().fadeOut();
    });
});

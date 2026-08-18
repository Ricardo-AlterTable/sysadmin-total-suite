jQuery(document).ready(function ($) {
    var T = (window.STSUITE_AJAX && STSUITE_AJAX.i18n) ? STSUITE_AJAX.i18n : {};

    if (typeof STSUITE_AJAX === 'undefined') {
        console.warn(T.noWpsAjax || 'STSUITE_AJAX is not defined.');
        return;
    }

    // Formatea una cadena con un único marcador (%s / %d).
    function fmt(str, val) {
        return String(str || '').replace(/%[sd]/, val);
    }

    // Mensaje de error real del servidor (jqXHR.responseJSON) o genérico.
    function serverErrorMessage(jqXHR, textStatus) {
        if (textStatus === 'timeout') return T.timeout;
        var data = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data;
        if (data && data.message) {
            return data.message + (data.details ? ' (' + data.details + ')' : '');
        }
        return T.commError;
    }

    function errText(jqXHR, textStatus) {
        return fmt(T.errorPrefix, serverErrorMessage(jqXHR, textStatus));
    }

    // Colorear diff.
    // IMPORTANTE: el diff contiene el contenido LITERAL del archivo local, que es
    // justamente el dato potencialmente malicioso que este plugin detecta. Nunca
    // debe interpretarse como HTML: se construyen nodos y se usa textContent, de
    // modo que un <script> incrustado en un archivo del core no pueda ejecutarse
    // en el panel de administración.
    function renderDiff(target, text) {
        target.textContent = '';
        if (!text) return;

        const frag = document.createDocumentFragment();
        text.split("\n").forEach(function (line, i) {
            const span = document.createElement('span');
            if (line.charAt(0) === '+') {
                span.className = 'diff-add';
            } else if (line.charAt(0) === '-') {
                span.className = 'diff-del';
            } else {
                span.className = 'diff-ctx';
            }
            span.textContent = line;                       // escapado por el DOM
            if (i > 0) frag.appendChild(document.createTextNode("\n"));
            frag.appendChild(span);
        });
        target.appendChild(frag);
    }

    // Mostrar cambios (diff)
    $(document).on('click', '.show-diff', function (e) {
        e.preventDefault();
        const path = $(this).data('path');
        $("#stsuiteDiffContent").text(T.loadingDiff);
        $("#stsuiteDiffModal").css('display', 'flex');

        $.ajax({
            url: STSUITE_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'stsuite_show_diff', nonce: STSUITE_AJAX.nonce, path: path },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    renderDiff(document.getElementById('stsuiteDiffContent'), res.data.diff);
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.commError;
                    $("#stsuiteDiffContent").text(fmt(T.errorPrefix, msg));
                }
            },
            error: function (jqXHR, textStatus) {
                $("#stsuiteDiffContent").text(errText(jqXHR, textStatus));
            }
        });
    });

    // Ver extras
    $(document).on('click', '.view-extras', function (e) {
        e.preventDefault();
        $("#stsuiteExtrasModal").css('display', 'flex');
    });

    // WPO: limpiar transitorios caducados
    $(document).on('click', '.stsuite-clean-transients', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmCleanTransients)) return;

        button.prop('disabled', true).text(T.cleaning);
        $.ajax({
            url: STSUITE_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'stsuite_clean_transients', nonce: button.data('nonce') },
            timeout: 120000,
            success: function (res) {
                if (res && res.success) {
                    alert(fmt(T.transientsRemoved, res.data.removed || 0));
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.cleanError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.cleanTransients);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.cleanTransients);
            }
        });
    });

    // WPO: eliminar una tarea cron huérfana
    $(document).on('click', '.stsuite-clean-cron-hook', function (e) {
        e.preventDefault();
        const button = $(this);
        const hook = button.data('hook');
        if (!confirm(fmt(T.confirmCleanCronHook, hook))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: STSUITE_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'stsuite_clean_cron_hook', nonce: button.data('nonce'), hook: hook },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('tr').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.cleanError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.delete);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.delete);
            }
        });
    });

    // WPO: eliminar TODAS las tareas cron huérfanas
    $(document).on('click', '.stsuite-clean-cron-all', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmCleanCronAll)) return;

        button.prop('disabled', true).text(T.cleaning);
        $.ajax({
            url: STSUITE_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'stsuite_clean_cron_all', nonce: button.data('nonce') },
            timeout: 120000,
            success: function (res) {
                if (res && res.success) {
                    let message = fmt(T.cronRemoved, res.data.removed || 0);
                    if (res.data.hooks && res.data.hooks.length) {
                        message += "\n\n" + T.hooksCleaned + "\n" + res.data.hooks.join("\n");
                    }
                    alert(message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.cleanError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.cleanAllCron);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.cleanAllCron);
            }
        });
    });

    // Eliminar usuario de WordPress (doble confirmación, IRREVERSIBLE)
    $(document).on('click', '.stsuite-delete-user', function (e) {
        e.preventDefault();
        const button = $(this);
        const uid = button.data('user-id');
        const label = button.data('user-label') || ('#' + uid);

        if (!confirm(fmt(T.confirmDeleteUser1, label))) return;
        if (!confirm(fmt(T.confirmDeleteUser2, label))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: STSUITE_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'stsuite_delete_user', nonce: button.data('nonce'), user_id: uid },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('tr').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteUserError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.delete);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.delete);
            }
        });
    });

    // Bloqueo bots IA: aplicar a todos (bloquear / permitir)
    $(document).on('click', '.stsuite-bots-block-all', function (e) {
        e.preventDefault();
        $('.stsuite-bot-cb').prop('checked', true);
    });
    $(document).on('click', '.stsuite-bots-allow-all', function (e) {
        e.preventDefault();
        $('.stsuite-bot-cb').prop('checked', false);
    });


    // Abrir el modal de extras al volver de su paginación (?open_extras_modal=true)
    (function () {
        var params = new URLSearchParams(window.location.search);
        if (params.get('open_extras_modal') === 'true') {
            var m = document.getElementById('stsuiteExtrasModal');
            if (m) m.style.display = 'flex';
        }
    })();

    // Cerrar modales
    $(document).on('click', '.stsuite-close, #stsuite-extras-modal-close', function () {
        $(this).closest('.stsuite-modal-overlay').css('display', 'none');
    });
    $(document).on('click', '.stsuite-modal-overlay', function (e) {
        if (e.target === this) $(this).css('display', 'none');
    });
});

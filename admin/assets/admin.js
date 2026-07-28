jQuery(document).ready(function ($) {
    var T = (window.WPS_AJAX && WPS_AJAX.i18n) ? WPS_AJAX.i18n : {};

    if (typeof WPS_AJAX === 'undefined') {
        console.warn(T.noWpsAjax || 'WPS_AJAX is not defined.');
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
        $("#wpsDiffContent").text(T.loadingDiff);
        $("#wpsDiffModal").css('display', 'flex');

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_show_diff', nonce: WPS_AJAX.nonce, path: path },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    renderDiff(document.getElementById('wpsDiffContent'), res.data.diff);
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.commError;
                    $("#wpsDiffContent").text(fmt(T.errorPrefix, msg));
                }
            },
            error: function (jqXHR, textStatus) {
                $("#wpsDiffContent").text(errText(jqXHR, textStatus));
            }
        });
    });

    // Restaurar archivo
    $(document).on('click', '.restore-file', function (e) {
        e.preventDefault();
        if (!confirm(T.confirmRestore)) return;
        const doBackup = confirm(T.askBackupFile);

        const button = $(this);
        const path = button.data('path');
        button.prop('disabled', true).text(T.restoring);

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_restore_file', nonce: button.data('nonce'), path: path, backup: doBackup ? '1' : '0' },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    let msg = fmt(T.fileRestored, path);
                    if (res.data && res.data.backup) {
                        msg += "\n\n" + T.backupSavedIn + "\n" + res.data.backup;
                    } else {
                        msg += "\n\n" + T.noBackupMade;
                    }
                    alert(msg);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.restoreError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.restore);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.restore);
            }
        });
    });

    // Restaurar todos
    $(document).on('click', '.restore-all', function (e) {
        e.preventDefault();
        if (!confirm(T.confirmRestoreAll)) return;

        const button = $(this);
        const files = $('.restore-file').map(function () { return $(this).data('path'); }).get();

        if (files.length === 0) {
            alert(T.noFilesToRestore);
            return;
        }

        const doBackup = confirm(T.askBackupAll);
        button.prop('disabled', true).text(T.restoring);

        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_restore_all_files', nonce: button.data('nonce'), files: files, backup: doBackup ? '1' : '0' },
            timeout: 600000,
            success: function (res) {
                if (res && res.success) {
                    let message = T.opDone + "\n\n";
                    if (res.data.restored && res.data.restored.length > 0) {
                        message += fmt(T.filesRestoredN, res.data.restored.length) + "\n" + res.data.restored.join("\n");
                    }
                    if (res.data.backup_dir) {
                        message += "\n\n" + T.backupSavedIn + "\n" + res.data.backup_dir;
                    } else {
                        message += "\n\n" + T.noBackupMade;
                    }
                    const errorKeys = Object.keys(res.data.errors || {});
                    if (errorKeys.length > 0) {
                        message += "\n\n" + fmt(T.filesWithErrorsN, errorKeys.length) + "\n";
                        errorKeys.forEach(function (file) { message += file + ": " + res.data.errors[file] + "\n"; });
                    }
                    alert(message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.restoreAllError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.restoreAll);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.restoreAll);
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
        if (!confirm(fmt(T.confirmDeleteExtra, path))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_extra', nonce: button.data('nonce'), path: path },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('li').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteError;
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

    // Eliminar TODOS los archivos extra (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-all-extras', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmDeleteAllExtra)) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_all_extras', nonce: button.data('nonce') },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    let message = T.opDone + "\n\n";
                    if (res.data.deleted && res.data.deleted.length > 0) {
                        message += fmt(T.deletedN, res.data.deleted.length) + "\n" + res.data.deleted.join("\n");
                    }
                    const errorKeys = Object.keys(res.data.errors || {});
                    if (errorKeys.length > 0) {
                        message += "\n\n" + fmt(T.filesWithErrorsN, errorKeys.length) + "\n";
                        errorKeys.forEach(function (file) { message += file + ": " + res.data.errors[file] + "\n"; });
                    }
                    alert(message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.deleteAllExtra);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.deleteAllExtra);
            }
        });
    });

    // WPO: limpiar transitorios caducados
    $(document).on('click', '.wps-clean-transients', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmCleanTransients)) return;

        button.prop('disabled', true).text(T.cleaning);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_clean_transients', nonce: button.data('nonce') },
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
    $(document).on('click', '.wps-clean-cron-hook', function (e) {
        e.preventDefault();
        const button = $(this);
        const hook = button.data('hook');
        if (!confirm(fmt(T.confirmCleanCronHook, hook))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_clean_cron_hook', nonce: button.data('nonce'), hook: hook },
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
    $(document).on('click', '.wps-clean-cron-all', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmCleanCronAll)) return;

        button.prop('disabled', true).text(T.cleaning);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_clean_cron_all', nonce: button.data('nonce') },
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
    $(document).on('click', '.wps-delete-user', function (e) {
        e.preventDefault();
        const button = $(this);
        const uid = button.data('user-id');
        const label = button.data('user-label') || ('#' + uid);

        if (!confirm(fmt(T.confirmDeleteUser1, label))) return;
        if (!confirm(fmt(T.confirmDeleteUser2, label))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_user', nonce: button.data('nonce'), user_id: uid },
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
    $(document).on('click', '.wps-bots-block-all', function (e) {
        e.preventDefault();
        $('.wps-bot-cb').prop('checked', true);
    });
    $(document).on('click', '.wps-bots-allow-all', function (e) {
        e.preventDefault();
        $('.wps-bot-cb').prop('checked', false);
    });

    // ---------- Copias de seguridad ----------
    function backupCtx(el) {
        const $row  = $(el).closest('tr');
        const $card = $(el).closest('.wps-backup');
        return {
            store:  $card.data('store'),
            batch:  $card.data('batch'),
            file:   $row.data('file'),
            target: $row.data('target')
        };
    }

    // Restaurar un archivo desde una copia
    $(document).on('click', '.wps-restore-backup', function (e) {
        e.preventDefault();
        const button = $(this);
        const ctx = backupCtx(this);
        if (!confirm(fmt(T.confirmRestoreBackup, ctx.target))) return;

        button.prop('disabled', true).text(T.restoring);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_restore_backup', nonce: button.data('nonce'), store: ctx.store, batch: ctx.batch, file: ctx.file },
            timeout: 120000,
            success: function (res) {
                if (res && res.success) {
                    alert(res.data.message);
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.restoreError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.restore);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.restore);
            }
        });
    });

    // Eliminar un archivo de una copia (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-backup-file', function (e) {
        e.preventDefault();
        const button = $(this);
        const ctx = backupCtx(this);
        if (!confirm(fmt(T.confirmDeleteBackupFile, ctx.target))) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_backup_file', nonce: button.data('nonce'), store: ctx.store, batch: ctx.batch, file: ctx.file },
            timeout: 60000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('tr').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteError;
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

    // Eliminar una copia completa (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-backup-batch', function (e) {
        e.preventDefault();
        const button = $(this);
        const ctx = backupCtx(this);
        if (!confirm(T.confirmDeleteBackupBatch)) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_backup_batch', nonce: button.data('nonce'), store: ctx.store, batch: ctx.batch },
            timeout: 120000,
            success: function (res) {
                if (res && res.success) {
                    button.closest('.wps-backup').fadeOut();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.deleteBackupBatch);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.deleteBackupBatch);
            }
        });
    });

    // Eliminar TODAS las copias (IRREVERSIBLE)
    $(document).on('click', '.wps-delete-all-backups', function (e) {
        e.preventDefault();
        const button = $(this);
        if (!confirm(T.confirmDeleteAllBackups)) return;

        button.prop('disabled', true).text(T.deleting);
        $.ajax({
            url: WPS_AJAX.ajax_url,
            type: 'POST',
            data: { action: 'wps_delete_all_backups', nonce: button.data('nonce') },
            timeout: 300000,
            success: function (res) {
                if (res && res.success) {
                    alert(fmt(T.backupsDeleted, res.data.deleted || 0));
                    location.reload();
                } else {
                    const msg = res && res.data && res.data.message ? res.data.message : T.deleteError;
                    alert(fmt(T.errorPrefix, msg));
                    button.prop('disabled', false).text(T.deleteAllBackups);
                }
            },
            error: function (jqXHR, textStatus) {
                alert(errText(jqXHR, textStatus));
                button.prop('disabled', false).text(T.deleteAllBackups);
            }
        });
    });

    // Cerrar modales
    $(document).on('click', '.wps-close, #wps-extras-modal-close', function () {
        $(this).closest('.wps-modal-overlay').css('display', 'none');
    });
    $(document).on('click', '.wps-modal-overlay', function (e) {
        if (e.target === this) $(this).css('display', 'none');
    });
});

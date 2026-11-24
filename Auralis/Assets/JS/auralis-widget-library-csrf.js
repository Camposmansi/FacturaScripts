/*!
 * @package FacturaScripts
 * @author MansiHost.com
 * @description WidgetLibrary para ConfigAuralis con CSRF (multireqtoken en search/upload).
 * Sobrescribe WidgetLibrary.js del Core (que no envía token → 403).
 */
(function () {
    'use strict';

    function rememberToken(token) {
        if (token) {
            window.AURALIS_LIBRARY_CSRF = String(token);
        }
    }

    function csrfToken(widgetId) {
        if (window.AURALIS_LIBRARY_CSRF) {
            return window.AURALIS_LIBRARY_CSRF;
        }

        if (widgetId) {
            const wrap = document.getElementById(widgetId);
            if (wrap) {
                if (wrap.dataset && wrap.dataset.multireqtoken) {
                    rememberToken(wrap.dataset.multireqtoken);
                    return wrap.dataset.multireqtoken;
                }
                const form = wrap.closest('form');
                if (form) {
                    const input = form.querySelector('input[name="multireqtoken"]');
                    if (input && input.value) {
                        rememberToken(input.value);
                        return input.value;
                    }
                }
            }
        }

        const activePane = document.querySelector('.tab-pane.active, .tab-pane.show.active');
        if (activePane) {
            const activeInput = activePane.querySelector('input[name="multireqtoken"]');
            if (activeInput && activeInput.value) {
                rememberToken(activeInput.value);
                return activeInput.value;
            }
        }

        const input = document.querySelector('input[name="multireqtoken"]');
        if (input && input.value) {
            rememberToken(input.value);
            return input.value;
        }

        return '';
    }

    function widgetLibrarySearch(widgetId) {
        $('#list_' + widgetId).html(
            '<div class="col-12 text-center pt-5 pb-5"><i class="fa-solid fa-circle-notch fa-4x fa-spin"></i></div>'
        );
        const hidden = $('div#' + widgetId + ' input.input-hidden');
        const token = csrfToken(widgetId);
        if (!token) {
            alert('Token CSRF no encontrado. Recargue la página e inténtelo de nuevo.');
            return;
        }
        const payload = {
            action: 'widget-library-search',
            active_tab: hidden.closest('form').find('input[name="activetab"]').val(),
            col_name: hidden.attr('name'),
            widget_id: widgetId,
            query: $('#modal_' + widgetId + '_q').val(),
            sort: $('#modal_' + widgetId + '_s').val(),
            multireqtoken: token
        };
        $.ajax({
            method: 'POST',
            url: window.location.href.split('#')[0],
            data: payload,
            dataType: 'json',
            success: function (data) {
                if (data && data.newToken) {
                    rememberToken(data.newToken);
                }
                if (data && data.error) {
                    alert(data.error);
                    return;
                }
                $('div#list_' + widgetId).html(data.html);
            },
            error: function (err) {
                let msg = err.status + ' ' + (err.responseText || '');
                try {
                    const parsed = JSON.parse(err.responseText);
                    if (parsed && parsed.error) {
                        msg = parsed.error;
                    }
                } catch (e) { /* ignore */ }
                alert(msg);
            }
        });
    }

    const widgetLibrarySearchTimeouts = {};

    function widgetLibrarySearchKp(widgetId, event) {
        if (widgetLibrarySearchTimeouts[widgetId]) {
            clearTimeout(widgetLibrarySearchTimeouts[widgetId]);
        }
        widgetLibrarySearchTimeouts[widgetId] = setTimeout(function () {
            widgetLibrarySearch(widgetId);
        }, 400);
    }

    function widgetLibrarySelect(widgetId, fileId, fileName) {
        $('div#' + widgetId + ' input.input-hidden').val(fileId);
        $('div#' + widgetId + ' span.file-name').text(fileName);
        $('div#list_' + widgetId + ' div.file').removeClass('border-primary');
        $('div#list_' + widgetId + ' div[data-idfile="' + fileId + '"]').addClass('border-primary');
        const modalEl = document.getElementById('modal_' + widgetId);
        if (modalEl && window.bootstrap && window.bootstrap.Modal) {
            const instance = window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
            instance.hide();
            return;
        }
        $('#modal_' + widgetId).modal('hide');
    }

    function widgetLibraryUpload(widgetId, file) {
        const hidden = $('div#' + widgetId + ' input.input-hidden');
        const token = csrfToken(widgetId);
        if (!token) {
            alert('Token CSRF no encontrado. Recargue la página e inténtelo de nuevo.');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'widget-library-upload');
        formData.append('active_tab', hidden.closest('form').find('input[name="activetab"]').val());
        formData.append('col_name', hidden.attr('name'));
        formData.append('widget_id', widgetId);
        formData.append('file', file);
        formData.append('multireqtoken', token);
        $.ajax({
            method: 'POST',
            url: window.location.href.split('#')[0],
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            success: function (data) {
                if (data && data.newToken) {
                    rememberToken(data.newToken);
                }
                if (data && data.error) {
                    alert(data.error);
                    return;
                }
                $('div#list_' + widgetId).html(data.html);
                if (data.records === 1) {
                    widgetLibrarySelect(widgetId, data.new_file, data.new_filename);
                }
            },
            error: function (err) {
                let msg = err.status + ' ' + (err.responseText || '');
                try {
                    const parsed = JSON.parse(err.responseText);
                    if (parsed && parsed.error) {
                        msg = parsed.error;
                    }
                } catch (e) { /* ignore */ }
                alert(msg);
            }
        });
    }

    function installWidgetLibraryCsrf() {
        // defineProperty evita que WidgetLibrary.js del Core (si carga tarde) pise el CSRF.
        try {
            Object.defineProperty(window, 'widgetLibrarySearch', { value: widgetLibrarySearch, writable: true, configurable: true });
            Object.defineProperty(window, 'widgetLibrarySearchKp', { value: widgetLibrarySearchKp, writable: true, configurable: true });
            Object.defineProperty(window, 'widgetLibrarySelect', { value: widgetLibrarySelect, writable: true, configurable: true });
            Object.defineProperty(window, 'widgetLibraryUpload', { value: widgetLibraryUpload, writable: true, configurable: true });
        } catch (e) {
            window.widgetLibrarySearch = widgetLibrarySearch;
            window.widgetLibrarySearchKp = widgetLibrarySearchKp;
            window.widgetLibrarySelect = widgetLibrarySelect;
            window.widgetLibraryUpload = widgetLibraryUpload;
        }

        if (!window.AURALIS_LIBRARY_CSRF) {
            csrfToken(null);
        }
    }

    installWidgetLibraryCsrf();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installWidgetLibraryCsrf);
    } else {
        installWidgetLibraryCsrf();
    }
    // Reinstalar tras carga completa por si otro script pisa las funciones.
    window.addEventListener('load', installWidgetLibraryCsrf);
})();

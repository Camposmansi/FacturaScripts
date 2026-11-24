/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Selector multi-asistentes Tom Select para el modal de calendario Auralis.
 */
(function () {
    'use strict';

    var tsInstance = null;

    function csrfToken() {
        if (window.AURALIS_LIBRARY_CSRF) {
            return String(window.AURALIS_LIBRARY_CSRF);
        }
        var input = document.querySelector('#formAuralisCalendarEvent input[name="multireqtoken"]')
            || document.querySelector('input[name="multireqtoken"]');
        return input && input.value ? input.value : '';
    }

    function rememberToken(token) {
        if (token) {
            window.AURALIS_LIBRARY_CSRF = String(token);
            document.querySelectorAll('input[name="multireqtoken"]').forEach(function (input) {
                input.value = token;
            });
        }
    }

    function syncHiddenJson() {
        var hidden = document.getElementById('zEvParticipantsJson');
        if (!hidden || !tsInstance) {
            return;
        }
        var items = [];
        (tsInstance.items || []).forEach(function (value) {
            var opt = tsInstance.options[value];
            if (!opt || opt.is_group === true || opt.tipo === 'group') {
                return;
            }
            items.push({
                tipo: opt.tipo || 'user',
                codigo: opt.codigo || String(value).split(':').slice(1).join(':'),
                email: opt.email || null,
                nombre_snapshot: opt.text || opt.label || null,
                estado_asistencia: 'pendiente'
            });
        });
        hidden.value = JSON.stringify(items);
        syncCodclienteFromParticipants(items);
    }

    /**
     * Si hay asistente tipo cliente, espeja el primero en #zEvCodcliente (cabecera del evento).
     * @param {Array<{tipo?: string, codigo?: string}>} items
     */
    function syncCodclienteFromParticipants(items) {
        var hidden = document.getElementById('zEvCodcliente');
        if (!hidden) {
            return;
        }
        var first = '';
        (items || []).forEach(function (row) {
            if (first || !row || row.tipo !== 'cliente' || !row.codigo) {
                return;
            }
            first = String(row.codigo).trim();
        });
        if (first !== '') {
            hidden.value = first;
        }
    }

    /** Primer codcliente entre asistentes (Tom Select o JSON). */
    function firstClienteCodigo() {
        if (tsInstance) {
            var found = '';
            (tsInstance.items || []).forEach(function (value) {
                if (found) {
                    return;
                }
                var opt = tsInstance.options[value];
                if (opt && opt.tipo === 'cliente' && opt.codigo) {
                    found = String(opt.codigo).trim();
                }
            });
            if (found) {
                return found;
            }
        }
        var jsonEl = document.getElementById('zEvParticipantsJson');
        if (!jsonEl) {
            return '';
        }
        try {
            var list = JSON.parse(jsonEl.value || '[]');
            for (var i = 0; i < list.length; i++) {
                if (list[i] && list[i].tipo === 'cliente' && list[i].codigo) {
                    return String(list[i].codigo).trim();
                }
            }
        } catch (e) { /* ignore */ }
        return '';
    }

    function optionValue(tipo, codigo) {
        return String(tipo) + ':' + String(codigo);
    }

    /** Etiqueta i18n del tipo (user/cliente/contacto) desde data-* del select. */
    function tipoLabel(tipo) {
        var el = document.getElementById('zEvParticipants');
        var key = String(tipo || 'user').toLowerCase();
        if (!el || !el.dataset) {
            return key;
        }
        var map = {
            user: el.dataset.tipoUser,
            cliente: el.dataset.tipoCliente,
            contacto: el.dataset.tipoContacto,
            group: el.dataset.tipoGroup
        };
        var label = map[key];
        return (label && String(label).trim()) ? String(label).trim() : key;
    }

    /** Siembra usuarios del selector #zEvAsignado (ya cargados en la página). */
    function seedUsersFromOrganizerSelect() {
        var select = document.getElementById('zEvAsignado');
        if (!select || !tsInstance) {
            return;
        }
        Array.prototype.forEach.call(select.options || [], function (opt) {
            var nick = String(opt.value || '').trim();
            if (!nick) {
                return;
            }
            var value = optionValue('user', nick);
            if (tsInstance.options[value]) {
                return;
            }
            tsInstance.addOption({
                value: value,
                text: String(opt.textContent || nick).trim(),
                tipo: 'user',
                codigo: nick,
                email: null
            });
        });
    }

    function destroy() {
        if (tsInstance) {
            try {
                tsInstance.destroy();
            } catch (e) { /* ignore */ }
            tsInstance = null;
        }
    }

    function setParticipants(list) {
        ensure();
        if (!tsInstance) {
            return;
        }
        tsInstance.clear(true);
        seedUsersFromOrganizerSelect();
        (list || []).forEach(function (row) {
            if (!row || !row.codigo || !row.tipo || row.tipo === 'group') {
                return;
            }
            var value = optionValue(row.tipo, row.codigo);
            var label = row.nombre_snapshot || row.label || row.codigo;
            if (!tsInstance.options[value]) {
                tsInstance.addOption({
                    value: value,
                    text: label,
                    tipo: row.tipo,
                    codigo: String(row.codigo),
                    email: row.email || null
                });
            }
            tsInstance.addItem(value, true);
        });
        syncHiddenJson();
    }

    function clearParticipants() {
        if (tsInstance) {
            tsInstance.clear(true);
            seedUsersFromOrganizerSelect();
        }
        var hidden = document.getElementById('zEvParticipantsJson');
        if (hidden) {
            hidden.value = '[]';
        }
    }

    function isGroupRow(row) {
        return !!row && (row.is_group === true || row.tipo === 'group');
    }

    /**
     * @param {Array} results
     * @param {{sinGrupos?: boolean}} [opciones] sinGrupos descarta los grupos (defensa en profundidad).
     */
    function mapResults(results, opciones) {
        var sinGrupos = !!(opciones && opciones.sinGrupos);
        return (results || []).filter(function (row) {
            return row && !(sinGrupos && isGroupRow(row));
        }).map(function (row) {
            var group = isGroupRow(row);
            return {
                value: optionValue(row.tipo, row.codigo),
                text: row.label,
                tipo: row.tipo,
                codigo: String(row.codigo),
                email: row.email || null,
                group: row.group || null,
                is_group: group,
                provider: row.provider || null,
                member_count: group ? (parseInt(row.member_count, 10) || 0) : 0
            };
        });
    }

    /**
     * POST al endpoint de asistentes con CSRF. Llama a done(data) o done(null) si falla.
     * @param {Object<string, string>} params
     * @param {function(Object|null): void} done
     */
    function postParticipantAction(params, done) {
        var token = csrfToken();
        if (!token) {
            console.warn('[Auralis] Sin token CSRF para asistentes');
            done(null);
            return;
        }
        var body = new URLSearchParams();
        body.set('action', 'calendar-participant-search');
        Object.keys(params || {}).forEach(function (key) {
            body.set(key, params[key]);
        });
        body.set('multireqtoken', token);

        var url = window.location.pathname;
        fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'action=calendar-participant-search', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (data) {
                return { status: r.status, data: data };
            });
        }).then(function (pack) {
            var data = pack.data || {};
            if (data.newToken) {
                rememberToken(data.newToken);
            }
            if (pack.status >= 400 || data.ok === false) {
                console.warn('[Auralis] Petición de asistentes falló', pack.status, data.message || data);
                done(null);
                return;
            }
            done(data);
        }).catch(function (err) {
            console.warn('[Auralis] Error de red en asistentes', err);
            done(null);
        });
    }

    /**
     * @param {string} query
     * @param {function(Array=): void} callback
     * @param {{sinGrupos?: boolean}} [opciones]
     */
    function fetchParticipants(query, callback, opciones) {
        var params = { q: query || '' };
        if (opciones && opciones.sinGrupos) {
            params.sin_grupos = '1';
        }
        postParticipantAction(params, function (data) {
            if (!data) {
                callback();
                return;
            }
            callback(mapResults(data.results, opciones));
        });
    }

    var expandingGroup = false;

    function groupText(key, replacements) {
        var el = document.getElementById('zEvParticipants');
        var text = (el && el.dataset && el.dataset[key]) ? String(el.dataset[key]) : '';
        Object.keys(replacements || {}).forEach(function (name) {
            text = text.split('%' + name + '%').join(String(replacements[name]));
        });
        return text;
    }

    /** Quita pill y opción del grupo para que nunca quede colgado ni se serialice. */
    function dropGroupEntry(ts, value) {
        try {
            ts.removeItem(value, true);
            ts.removeOption(value, true);
        } catch (e) { /* ignore */ }
    }

    /**
     * Sustituye un grupo recién elegido por sus miembros (personas), respetando maxItems.
     * @param {Object} ts instancia Tom Select
     * @param {string} value valor del grupo (group:role:xxx)
     */
    function expandGroup(ts, value) {
        var opt = ts.options[value];
        if (!opt) {
            return;
        }
        var groupCode = String(opt.codigo || '');
        var memberCount = parseInt(opt.member_count, 10) || 0;
        dropGroupEntry(ts, value);
        syncHiddenJson();
        if (expandingGroup) {
            return;
        }

        var maxItems = parseInt(ts.settings.maxItems, 10) || 50;
        var free = Math.max(0, maxItems - (ts.items || []).length);
        if (!groupCode || free <= 0) {
            return;
        }
        if (memberCount > 10 || memberCount > free) {
            var msg = groupText('groupConfirm', { count: memberCount, free: free });
            if (!window.confirm(msg)) {
                return;
            }
        }
        expandingGroup = true;

        var exclude = (ts.items || []).filter(function (v) {
            var o = ts.options[v];
            return o && !isGroupRow(o);
        }).map(String);

        postParticipantAction({
            expandir: groupCode,
            exclude: JSON.stringify(exclude)
        }, function (data) {
            expandingGroup = false;
            if (!data) {
                console.warn('[Auralis] No se pudo expandir el grupo', groupCode);
                return;
            }
            var cutByFree = false;
            var rows = mapResults(data.results, { sinGrupos: true });
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                if ((ts.items || []).indexOf(row.value) >= 0) {
                    continue;
                }
                if ((ts.items || []).length >= maxItems) {
                    cutByFree = true;
                    break;
                }
                if (!ts.options[row.value]) {
                    ts.addOption(row);
                }
                ts.addItem(row.value, true);
            }
            syncHiddenJson();
            if (data.truncated || cutByFree) {
                var notice = groupText('groupTruncated');
                console.info('[Auralis] ' + notice);
                if (notice) {
                    window.alert(notice);
                }
            }
        });
    }

    function ensure() {
        var el = document.getElementById('zEvParticipants');
        if (!el) {
            return null;
        }
        if (typeof TomSelect === 'undefined') {
            console.warn('[Auralis] TomSelect no cargado');
            return null;
        }
        if (tsInstance) {
            return tsInstance;
        }

        tsInstance = new TomSelect(el, {
            plugins: ['remove_button'],
            valueField: 'value',
            labelField: 'text',
            searchField: ['text', 'codigo', 'email'],
            maxItems: 50,
            create: false,
            persist: true,
            preload: 'focus',
            loadThrottle: 200,
            dropdownParent: 'body',
            placeholder: el.getAttribute('placeholder') || '',
            load: function (query, callback) {
                fetchParticipants(query || '', callback);
            },
            render: {
                option: function (data, escape) {
                    var email = data.email ? ' <small class="text-muted">' + escape(data.email) + '</small>' : '';
                    var tipo = data.tipo || 'user';
                    return '<div><span class="badge text-bg-light me-1">' + escape(tipoLabel(tipo)) + '</span>'
                        + escape(data.text || '') + email + '</div>';
                },
                item: function (data, escape) {
                    var tipo = data.tipo || 'user';
                    return '<div><span class="z-ts-tipo">' + escape(tipoLabel(tipo)) + '</span> '
                        + escape(data.text || data.codigo || '') + '</div>';
                },
                no_results: function () {
                    return '<div class="no-results py-2 px-3 text-muted">Sin resultados</div>';
                }
            },
            onInitialize: function () {
                seedUsersFromOrganizerSelect();
            },
            onFocus: function () {
                seedUsersFromOrganizerSelect();
            },
            onItemAdd: function (value) {
                var opt = this.options[value];
                if (opt && isGroupRow(opt)) {
                    expandGroup(this, value);
                }
            },
            onChange: function () {
                syncHiddenJson();
            }
        });

        return tsInstance;
    }

    var filterTsInstance = null;

    function filterTipoLabel(tipo, el) {
        var key = String(tipo || 'user').toLowerCase();
        if (!el || !el.dataset) {
            return key;
        }
        var map = {
            user: el.dataset.tipoUser,
            cliente: el.dataset.tipoCliente,
            contacto: el.dataset.tipoContacto
        };
        var label = map[key];
        return (label && String(label).trim()) ? String(label).trim() : key;
    }

    /**
     * Tom Select single-select en cabecera (#calFilterParticipante).
     * @param {function(): void} onChange
     */
    function initHeaderFilter(onChange) {
        var el = document.getElementById('calFilterParticipante');
        if (!el || typeof TomSelect === 'undefined') {
            return null;
        }
        if (filterTsInstance) {
            return filterTsInstance;
        }

        filterTsInstance = new TomSelect(el, {
            plugins: ['clear_button'],
            valueField: 'value',
            labelField: 'text',
            searchField: ['text', 'codigo', 'email'],
            maxItems: 1,
            create: false,
            persist: false,
            preload: 'focus',
            loadThrottle: 200,
            dropdownParent: 'body',
            dropdownClass: 'ts-dropdown auralis-cal-filter-participant-dropdown',
            placeholder: el.getAttribute('placeholder') || '',
            load: function (query, callback) {
                fetchParticipants(query || '', callback, { sinGrupos: true });
            },
            render: {
                option: function (data, escape) {
                    var email = data.email
                        ? '<div class="small text-muted text-truncate">' + escape(data.email) + '</div>'
                        : '';
                    var tipo = data.tipo || 'user';
                    return '<div class="py-1"><span class="badge text-bg-light me-1">'
                        + escape(filterTipoLabel(tipo, el)) + '</span>'
                        + '<span class="fw-semibold">' + escape(data.text || '') + '</span>'
                        + email + '</div>';
                },
                item: function (data, escape) {
                    return '<div class="text-truncate">' + escape(data.text || data.codigo || '') + '</div>';
                },
                no_results: function () {
                    return '<div class="no-results py-2 px-3 text-muted">Sin resultados</div>';
                }
            },
            onChange: function () {
                if (typeof onChange === 'function') {
                    onChange();
                }
            }
        });

        return filterTsInstance;
    }

    window.AuralisCalendarParticipants = {
        ensure: ensure,
        setParticipants: setParticipants,
        clearParticipants: clearParticipants,
        syncHiddenJson: syncHiddenJson,
        firstClienteCodigo: firstClienteCodigo,
        destroy: destroy,
        initHeaderFilter: initHeaderFilter
    };

    document.addEventListener('DOMContentLoaded', function () {
        ensure();
    });

    // Si el modal se abre después, reinicializar/siembra.
    document.addEventListener('shown.bs.modal', function (ev) {
        if (ev.target && ev.target.id === 'auralisNewEventModal') {
            ensure();
            seedUsersFromOrganizerSelect();
        }
    });
})();

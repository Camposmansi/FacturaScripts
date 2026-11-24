/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Gestión dinámica de recordatorios estilo Google Calendar en el modal de eventos Auralis.
 */

(function () {
    'use strict';

    const UNIT_OPTIONS = [
        { value: 'minutes', labelKey: 'minutes' },
        { value: 'hours', labelKey: 'hours' },
        { value: 'days', labelKey: 'days' },
        { value: 'weeks', labelKey: 'weeks' }
    ];

    const DEFAULT_I18N = {
        minutes: 'minutos',
        hours: 'horas',
        days: 'días',
        weeks: 'semanas',
        typeNotification: 'Notificación',
        noTypes: 'Sin plantillas disponibles',
        remove: 'Eliminar'
    };

    let typeCache = {};
    let currentOrigenTipo = 'general';
    let i18n = { ...DEFAULT_I18N };

    function loadI18n() {
        const section = document.getElementById('zEvNotificationsSection');
        if (!section || !section.dataset) {
            return;
        }
        i18n = {
            minutes: section.dataset.i18nMinutes || DEFAULT_I18N.minutes,
            hours: section.dataset.i18nHours || DEFAULT_I18N.hours,
            days: section.dataset.i18nDays || DEFAULT_I18N.days,
            weeks: section.dataset.i18nWeeks || DEFAULT_I18N.weeks,
            typeNotification: section.dataset.i18nTypeNotification || DEFAULT_I18N.typeNotification,
            noTypes: section.dataset.i18nNoTypes || DEFAULT_I18N.noTypes,
            remove: section.dataset.i18nRemove || DEFAULT_I18N.remove
        };
    }

    function getListEl() {
        return document.getElementById('zEvNotificationsList');
    }

    function getHiddenInput() {
        return document.getElementById('zEvNotificationsJson');
    }

    function getOrigenTipo() {
        const el = document.getElementById('zEvOrigenTipo');
        return el && el.value ? el.value : currentOrigenTipo;
    }

    function serializeRows() {
        const list = getListEl();
        if (!list) {
            return [];
        }

        const rows = [];
        list.querySelectorAll('.z-ev-notif-row').forEach(function (row) {
            const name = row.querySelector('.z-ev-notif-name');
            const value = row.querySelector('.z-ev-notif-value');
            const unit = row.querySelector('.z-ev-notif-unit');
            if (!name || !value || !unit || !name.value) {
                return;
            }
            rows.push({
                notification_name: name.value,
                offset_value: parseInt(value.value, 10) || 1,
                offset_unit: unit.value || 'minutes'
            });
        });

        return rows;
    }

    function syncHiddenInput() {
        const hidden = getHiddenInput();
        if (hidden) {
            hidden.value = JSON.stringify(serializeRows());
        }
    }

    function fetchTypes(origenTipo) {
        if (typeCache[origenTipo]) {
            return Promise.resolve(typeCache[origenTipo]);
        }

        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('action', 'calendar-notification-types');
        url.searchParams.set('origen_tipo', origenTipo);

        return fetch(url.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                const types = (data && data.types) ? data.types : [];
                typeCache[origenTipo] = types;
                return types;
            })
            .catch(function () {
                return [];
            });
    }

    function fetchDefaults(origenTipo) {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('action', 'calendar-notification-defaults');
        url.searchParams.set('origen_tipo', origenTipo);

        return fetch(url.toString())
            .then(function (res) { return res.json(); })
            .then(function (data) {
                return (data && data.defaults) ? data.defaults : [];
            })
            .catch(function () {
                return [];
            });
    }

    function buildTypeField(types, selectedName) {
        const wrap = document.createElement('div');
        wrap.className = 'z-ev-notif-type flex-grow-1';

        if (!types.length) {
            const span = document.createElement('span');
            span.className = 'form-control bg-light text-muted';
            span.textContent = i18n.noTypes;
            wrap.appendChild(span);
            return { wrap: wrap, input: null };
        }

        if (types.length === 1) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.className = 'z-ev-notif-name';
            hidden.value = types[0].name;

            const label = document.createElement('span');
            label.className = 'form-control bg-transparent border-0 ps-0 text-truncate';
            label.textContent = i18n.typeNotification;

            wrap.appendChild(hidden);
            wrap.appendChild(label);
            return { wrap: wrap, input: hidden };
        }

        const select = document.createElement('select');
        select.className = 'form-select z-ev-notif-name';

        types.forEach(function (type) {
            const opt = document.createElement('option');
            opt.value = type.name;
            opt.textContent = type.label || type.name;
            if (selectedName && selectedName === type.name) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        wrap.appendChild(select);
        return { wrap: wrap, input: select };
    }

    function buildUnitSelect(selectedUnit) {
        const select = document.createElement('select');
        select.className = 'form-select z-ev-notif-unit';

        UNIT_OPTIONS.forEach(function (unit) {
            const opt = document.createElement('option');
            opt.value = unit.value;
            opt.textContent = i18n[unit.labelKey] || unit.value;
            if (selectedUnit === unit.value) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });

        return select;
    }

    function addRow(data, types) {
        const list = getListEl();
        if (!list) {
            return;
        }

        const row = document.createElement('div');
        row.className = 'z-ev-notif-row d-flex align-items-center gap-2 flex-nowrap';

        const typeField = buildTypeField(types, data ? data.notification_name : null);

        const valueInput = document.createElement('input');
        valueInput.type = 'number';
        valueInput.min = '1';
        valueInput.max = '999';
        valueInput.className = 'form-control z-ev-notif-value';
        valueInput.value = data && data.offset_value ? String(data.offset_value) : '10';

        const unitSelect = buildUnitSelect(data ? data.offset_unit : 'minutes');

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-link text-muted p-0 z-ev-notif-remove flex-shrink-0';
        removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
        removeBtn.setAttribute('aria-label', i18n.remove);

        removeBtn.addEventListener('click', function () {
            row.remove();
            syncHiddenInput();
        });

        const inputs = [typeField.input, valueInput, unitSelect].filter(Boolean);
        inputs.forEach(function (el) {
            el.addEventListener('change', syncHiddenInput);
            el.addEventListener('input', syncHiddenInput);
        });

        row.appendChild(typeField.wrap);
        row.appendChild(valueInput);
        row.appendChild(unitSelect);
        row.appendChild(removeBtn);
        list.appendChild(row);
        syncHiddenInput();
    }

    function renderRows(notifications, origenTipo) {
        const list = getListEl();
        if (!list) {
            return Promise.resolve();
        }

        list.innerHTML = '';
        return fetchTypes(origenTipo).then(function (types) {
            (notifications || []).forEach(function (item) {
                addRow(item, types);
            });
            syncHiddenInput();
        });
    }

    function reset() {
        const list = getListEl();
        if (list) {
            list.innerHTML = '';
        }
        syncHiddenInput();
    }

    function loadDefaults(origenTipo) {
        currentOrigenTipo = origenTipo || getOrigenTipo();
        return fetchDefaults(currentOrigenTipo).then(function (defaults) {
            return renderRows(defaults, currentOrigenTipo);
        });
    }

    function loadFromEvent(notifications) {
        return renderRows(notifications || [], getOrigenTipo());
    }

    function appendNotification(origenTipo) {
        currentOrigenTipo = origenTipo || getOrigenTipo();
        return fetchDefaults(currentOrigenTipo).then(function (defaults) {
            if (defaults && defaults.length) {
                return fetchTypes(currentOrigenTipo).then(function (types) {
                    // Una sola fila por clic, con los valores de ConfigAjustesAuralis.
                    addRow(defaults[0], types);
                });
            }

            return fetchTypes(currentOrigenTipo).then(function (types) {
                const fallback = {
                    notification_name: types.length ? types[0].name : null,
                    offset_value: 10,
                    offset_unit: 'minutes'
                };
                addRow(fallback, types);
            });
        });
    }

    function init() {
        loadI18n();

        const addBtn = document.getElementById('zEvAddNotification');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                appendNotification(getOrigenTipo());
            });
        }

        const form = document.getElementById('formAuralisCalendarEvent');
        if (form) {
            form.addEventListener('submit', function () {
                syncHiddenInput();
            });
        }
    }

    window.AuralisCalendarNotifications = {
        reset: reset,
        loadDefaults: loadDefaults,
        loadFromEvent: loadFromEvent,
        appendNotification: appendNotification,
        syncHiddenInput: syncHiddenInput,
        clearTypeCache: function () {
            typeCache = {};
        }
    };

    document.addEventListener('DOMContentLoaded', init);
})();

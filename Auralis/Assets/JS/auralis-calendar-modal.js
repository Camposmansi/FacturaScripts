/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Lógica compartida del formulario modal de eventos de calendario Auralis y popup de recurrencia.
 */

(function () {
    'use strict';

    let initialized = false;

    function setDotActive(dot) {
        document.querySelectorAll('#auralisNewEventModal .z-color-dot').forEach(function (d) {
            d.classList.remove('active');
        });
        dot.classList.add('active');
        const color = dot.getAttribute('data-color') || '#4285f4';
        dot.style.setProperty('--dot-color', color);
        const colorInput = document.getElementById('zEvColor');
        if (colorInput) {
            colorInput.value = color;
        }
        const customPicker = document.getElementById('zEvColorPickerInput');
        if (customPicker && color.charAt(0) === '#') {
            try {
                customPicker.value = color;
            } catch (e) {}
        }
    }

    function toggleRepEndFields() {
        const tipo = document.getElementById('zEvRepFinTipo');
        const fechaWrap = document.getElementById('zEvRepFinFechaWrap');
        const countWrap = document.getElementById('zEvRepFinCountWrap');
        if (!tipo) {
            return;
        }

        const val = tipo.value;
        if (fechaWrap) {
            fechaWrap.classList.toggle('d-none', val !== 'fecha');
            fechaWrap.classList.toggle('d-flex', val === 'fecha');
        }
        if (countWrap) {
            countWrap.classList.toggle('d-none', val !== 'count');
            countWrap.classList.toggle('d-flex', val === 'count');
        }
    }

    function toggleRecurrenceFields() {
        const seRepite = document.getElementById('zEvSeRepite');
        const fields = document.getElementById('zEvRecurrenceFields');
        const endHeader = document.getElementById('zEvRepEndHeader');
        if (!seRepite) {
            return;
        }

        const isOn = seRepite.checked;
        if (fields) {
            fields.classList.toggle('d-none', !isOn);
        }
        if (endHeader) {
            endHeader.classList.toggle('d-none', !isOn);
        }
        toggleRepEndFields();
    }

    function getDefaultDuration() {
        const fDuracion = document.getElementById('zEvDuracion');
        const raw = fDuracion ? parseInt(fDuracion.getAttribute('data-default-duration') || '', 10) : NaN;
        if (!Number.isNaN(raw) && raw >= 5 && raw <= 480) {
            return raw;
        }
        return 60;
    }

    function parseTimeToMinutes(value) {
        if (!value || typeof value !== 'string') {
            return null;
        }
        const parts = value.split(':');
        const h = parseInt(parts[0], 10);
        const m = parseInt(parts[1] || '0', 10);
        if (Number.isNaN(h) || Number.isNaN(m)) {
            return null;
        }
        return (h * 60) + m;
    }

    function formatMinutesAsTime(totalMinutes) {
        let mins = totalMinutes % (24 * 60);
        if (mins < 0) {
            mins += 24 * 60;
        }
        const h = Math.floor(mins / 60);
        const m = mins % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function clampDuration(mins) {
        const n = parseInt(mins, 10);
        if (Number.isNaN(n) || n <= 0) {
            return getDefaultDuration();
        }
        return Math.max(5, Math.min(480, n));
    }

    /**
     * HH:MM válido. Recorta segundos (09:00:00) y rechaza cadenas vacías.
     */
    function normalizeClock(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }
        const mins = parseTimeToMinutes(raw.length > 5 ? raw.slice(0, 5) : raw);
        if (mins === null) {
            return '';
        }
        return formatMinutesAsTime(mins);
    }

    /**
     * Guarda la hora de inicio aunque el input type=time esté en un modal oculto.
     * Chromium devuelve "" al leer .value con display:none; data-pending-time no.
     */
    function rememberStartTime(value) {
        const fHora = document.getElementById('zEvHoraInicio');
        if (!fHora) {
            return '';
        }
        const normalized = normalizeClock(value) || '09:00';
        fHora.setAttribute('data-pending-time', normalized);
        fHora.value = normalized;
        return normalized;
    }

    function readStartMinutes(fHora) {
        let start = parseTimeToMinutes(fHora.value);
        if (start !== null) {
            return start;
        }
        start = parseTimeToMinutes(fHora.getAttribute('data-pending-time') || '');
        if (start !== null) {
            fHora.value = formatMinutesAsTime(start);
        }
        return start;
    }

    /**
     * Calcula #zEvHoraFin desde hora_inicio + duracion (contrato BD: sin columna hora_fin).
     */
    function syncEndFromDuration() {
        const fHora = document.getElementById('zEvHoraInicio');
        const fFin = document.getElementById('zEvHoraFin');
        const fDuracion = document.getElementById('zEvDuracion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        if (!fHora || !fFin || !fDuracion) {
            return;
        }
        if (fTodoDia && fTodoDia.checked) {
            fFin.value = '';
            return;
        }
        const start = readStartMinutes(fHora);
        if (start === null) {
            return;
        }
        const dur = clampDuration(fDuracion.value || getDefaultDuration());
        fDuracion.value = String(dur);
        const end = formatMinutesAsTime(start + dur);
        fFin.value = end;
        fHora.setAttribute('data-pending-time', formatMinutesAsTime(start));
    }

    /**
     * Recalcula duracion oculta desde hora fin - hora inicio.
     */
    function syncDurationFromEnd() {
        const fHora = document.getElementById('zEvHoraInicio');
        const fFin = document.getElementById('zEvHoraFin');
        const fDuracion = document.getElementById('zEvDuracion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        if (!fHora || !fFin || !fDuracion || (fTodoDia && fTodoDia.checked)) {
            return;
        }
        const start = readStartMinutes(fHora);
        let end = parseTimeToMinutes(fFin.value);
        if (start === null || end === null) {
            return;
        }
        if (end <= start) {
            end = start + clampDuration(getDefaultDuration());
            fFin.value = formatMinutesAsTime(end);
        }
        let dur = end - start;
        if (dur > 480) {
            dur = 480;
            fFin.value = formatMinutesAsTime(start + dur);
        }
        if (dur < 5) {
            dur = 5;
            fFin.value = formatMinutesAsTime(start + dur);
        }
        fDuracion.value = String(dur);
    }

    function setNotificationsExpanded(expanded) {
        // BC: la sección de avisos ya no colapsa; se mantiene visible si existe en el DOM.
        const body = document.getElementById('zEvNotificationsBody');
        if (body) {
            body.classList.remove('d-none');
        }
        void expanded;
    }

    function toggleTodoDiaInputs() {
        const fTodoDia = document.getElementById('zEvTodoDia');
        const fHora = document.getElementById('zEvHoraInicio');
        const fFin = document.getElementById('zEvHoraFin');
        const fDuracion = document.getElementById('zEvDuracion');
        const fFechaFinContainer = document.getElementById('zEvFechaFinContainer');
        const fFecha = document.getElementById('zEvFecha');
        const fFechaFin = document.getElementById('zEvFechaFin');

        if (!fTodoDia) {
            return;
        }

        const isChecked = fTodoDia.checked;
        if (fHora) {
            fHora.disabled = isChecked;
            if (isChecked) {
                fHora.removeAttribute('required');
                fHora.value = '00:00';
            } else {
                fHora.setAttribute('required', 'required');
                const visible = normalizeClock(fHora.value);
                const pending = normalizeClock(fHora.getAttribute('data-pending-time') || '');
                if (visible === '' || visible === '00:00') {
                    fHora.value = (pending !== '' && pending !== '00:00') ? pending : '09:00';
                }
                fHora.setAttribute('data-pending-time', normalizeClock(fHora.value) || '09:00');
            }
        }
        if (fFin) {
            fFin.disabled = isChecked;
            if (isChecked) {
                fFin.value = '';
            }
        }
        if (fDuracion) {
            if (isChecked) {
                fDuracion.value = '0';
            } else if (!fDuracion.value || fDuracion.value === '0') {
                fDuracion.value = String(getDefaultDuration());
            }
        }
        if (!isChecked) {
            syncEndFromDuration();
        }
        if (fFechaFinContainer) {
            if (isChecked) {
                fFechaFinContainer.classList.remove('d-none');
                fFechaFinContainer.classList.add('d-flex');
                if (fFechaFin && fFecha && (!fFechaFin.value || fFechaFin.value < fFecha.value)) {
                    fFechaFin.value = fFecha.value;
                }
            } else {
                fFechaFinContainer.classList.add('d-none');
                fFechaFinContainer.classList.remove('d-flex');
            }
        }
    }

    function resetRecurrenceFields() {
        const seRepite = document.getElementById('zEvSeRepite');
        const intervalo = document.getElementById('zEvRepIntervalo');
        const unidad = document.getElementById('zEvRepUnidad');
        const finTipo = document.getElementById('zEvRepFinTipo');
        const finFecha = document.getElementById('zEvRepFinFecha');
        const finCount = document.getElementById('zEvRepFinCount');
        const scope = document.getElementById('zEvScope');

        if (seRepite) {
            seRepite.checked = false;
        }
        if (intervalo) {
            intervalo.value = '1';
        }
        if (unidad) {
            unidad.value = 'week';
        }
        if (finTipo) {
            finTipo.value = 'nunca';
        }
        if (finFecha) {
            finFecha.value = '';
        }
        if (finCount) {
            finCount.value = '10';
        }
        if (scope) {
            scope.value = 'esta';
        }
        toggleRecurrenceFields();
    }

    function setColor(color) {
        if (!color) {
            return;
        }
        const colorInput = document.getElementById('zEvColor');
        if (colorInput) {
            colorInput.value = color;
        }

        let matched = false;
        document.querySelectorAll('#auralisNewEventModal .z-color-dot:not(.z-color-dot-custom)').forEach(function (dot) {
            const dotColor = dot.getAttribute('data-color') || '';
            if (dotColor.toLowerCase() === color.toLowerCase()) {
                setDotActive(dot);
                matched = true;
            }
        });

        const customDot = document.getElementById('zEvCustomDot');
        const customPicker = document.getElementById('zEvColorPickerInput');
        if (customPicker && color.charAt(0) === '#') {
            try {
                customPicker.value = color;
            } catch (e) {}
        }

        if (!matched && customDot) {
            document.querySelectorAll('#auralisNewEventModal .z-color-dot').forEach(function (d) {
                d.classList.remove('active');
            });
            customDot.classList.add('active');
            customDot.setAttribute('data-color', color);
            customDot.style.background = color;
            customDot.style.color = colorTextoSobre(color);
            customDot.style.setProperty('--dot-color', color);
        } else if (matched && customDot) {
            // Vuelve al punto neutro del CSS (.z-color-dot-custom).
            customDot.classList.remove('active');
            customDot.removeAttribute('data-color');
            customDot.style.background = '';
            customDot.style.color = '';
            customDot.style.removeProperty('--dot-color');
        }
    }

    function askRecurrenceScope(title, onSelect) {
        const i18n = window.AURALIS_I18N || {};
        const scopeTitle = title || i18n.recurrenceEventTitle || 'Evento recurrente';
        const scopeBody = i18n.recurrenceApplyChanges || 'Este evento se repite. ¿Cómo deseas aplicar los cambios?';
        const scopeThisLabel = i18n.scopeThis || 'Solo este evento';
        const scopeFutureLabel = i18n.scopeFuture || 'Este y los futuros';
        const cancelLabel = i18n.cancel || 'Cancelar';
        const closeLabel = i18n.close || 'Cerrar';

        let modalEl = document.getElementById('zDragRecurrenceModal');
        if (!modalEl) {
            const div = document.createElement('div');
            div.innerHTML = `
                <div class="modal fade auralis-modal" id="zDragRecurrenceModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-sm">
                        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                            <div class="modal-header py-2 bg-light border-bottom">
                                <h6 class="modal-title fw-bold mb-0 text-dark" style="font-size: 0.9rem;">
                                    <i class="fa-solid fa-repeat me-1 text-primary"></i>${scopeTitle}
                                </h6>
                                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal" aria-label="${closeLabel}"></button>
                            </div>
                            <div class="modal-body p-3 text-center">
                                <p class="small text-muted mb-3">${scopeBody}</p>
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-sm btn-primary py-1.5" id="zBtnScopeThis">${scopeThisLabel}</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1.5" id="zBtnScopeFuture">${scopeFutureLabel}</button>
                                    <button type="button" class="btn btn-sm btn-link text-muted text-decoration-none py-1" data-bs-dismiss="modal">${cancelLabel}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(div.firstElementChild);
            modalEl = document.getElementById('zDragRecurrenceModal');
        } else {
            const titleEl = modalEl.querySelector('.modal-title');
            if (titleEl) {
                titleEl.innerHTML = `<i class="fa-solid fa-repeat me-1 text-primary"></i>${scopeTitle}`;
            }
        }

        const bsModal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
        let selected = null;

        const btnThis = document.getElementById('zBtnScopeThis');
        const btnFuture = document.getElementById('zBtnScopeFuture');

        function cleanup() {
            btnThis.removeEventListener('click', handleThis);
            btnFuture.removeEventListener('click', handleFuture);
            modalEl.removeEventListener('hidden.bs.modal', handleHidden);
        }

        function handleThis() {
            selected = 'esta';
            if (bsModal) bsModal.hide();
        }

        function handleFuture() {
            selected = 'esta_y_futuras';
            if (bsModal) bsModal.hide();
        }

        function handleHidden() {
            cleanup();
            if (typeof onSelect === 'function') onSelect(selected);
        }

        btnThis.addEventListener('click', handleThis);
        btnFuture.addEventListener('click', handleFuture);
        modalEl.addEventListener('hidden.bs.modal', handleHidden);

        if (bsModal) {
            if (btnThis) btnThis.textContent = scopeThisLabel;
            if (btnFuture) btnFuture.textContent = scopeFutureLabel;
            bsModal.show();
        } else {
            const footer = i18n.scopePromptFooter || 'Escribe 1 o 2 (Cancelar para abortar):';
            const ans = window.prompt(
                scopeTitle + '\n\n1) ' + scopeThisLabel + '\n2) ' + scopeFutureLabel + '\n\n' + footer,
                '1'
            );
            if (typeof onSelect === 'function') {
                onSelect(ans === '2' ? 'esta_y_futuras' : (ans === '1' ? 'esta' : null));
            }
        }
    }

    function initCalendarEventModal() {
        if (initialized || !document.getElementById('auralisNewEventModal')) {
            return;
        }
        initialized = true;

        document.querySelectorAll('#auralisNewEventModal .z-color-dot:not(.z-color-dot-custom)').forEach(function (dot) {
            dot.addEventListener('click', function () {
                setDotActive(this);
            });
        });

        const customPicker = document.getElementById('zEvColorPickerInput');
        if (customPicker) {
            customPicker.addEventListener('input', function () {
                setColor(this.value);
            });
            customPicker.addEventListener('change', function () {
                setColor(this.value);
            });
        }

        const todoDiaEl = document.getElementById('zEvTodoDia');
        if (todoDiaEl) {
            todoDiaEl.addEventListener('change', toggleTodoDiaInputs);
        }

        const seRepiteEl = document.getElementById('zEvSeRepite');
        if (seRepiteEl) {
            seRepiteEl.addEventListener('change', toggleRecurrenceFields);
        }

        const repFinTipoEl = document.getElementById('zEvRepFinTipo');
        if (repFinTipoEl) {
            repFinTipoEl.addEventListener('change', toggleRepEndFields);
        }

        const fechaEl = document.getElementById('zEvFecha');
        if (fechaEl) {
            fechaEl.addEventListener('change', function () {
                const fFechaFin = document.getElementById('zEvFechaFin');
                if (fFechaFin && (!fFechaFin.value || fFechaFin.value < this.value)) {
                    fFechaFin.value = this.value;
                }
            });
        }

        const idtipoEl = document.getElementById('zEvIdtipo');
        if (idtipoEl) {
            idtipoEl.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                if (!opt) {
                    return;
                }
                const dur = parseInt(opt.getAttribute('data-duration') || '', 10);
                const color = opt.getAttribute('data-color');
                const fDuracion = document.getElementById('zEvDuracion');
                const fTodoDia = document.getElementById('zEvTodoDia');
                if (fDuracion && !Number.isNaN(dur) && dur > 0 && (!fTodoDia || !fTodoDia.checked)) {
                    fDuracion.value = String(dur);
                    syncEndFromDuration();
                }
                if (color) {
                    setColor(color);
                }
            });
        }

        const horaInicioEl = document.getElementById('zEvHoraInicio');
        if (horaInicioEl) {
            const onStartEdited = function () {
                const normalized = normalizeClock(this.value);
                if (normalized !== '') {
                    this.setAttribute('data-pending-time', normalized);
                }
                syncEndFromDuration();
            };
            horaInicioEl.addEventListener('change', onStartEdited);
            horaInicioEl.addEventListener('input', onStartEdited);
        }

        const eventModalEl = document.getElementById('auralisNewEventModal');
        if (eventModalEl) {
            eventModalEl.addEventListener('shown.bs.modal', function () {
                window.requestAnimationFrame(function () {
                    syncEndFromDuration();
                });
            });
        }

        const horaFinEl = document.getElementById('zEvHoraFin');
        if (horaFinEl) {
            horaFinEl.addEventListener('change', syncDurationFromEnd);
            horaFinEl.addEventListener('input', syncDurationFromEnd);
        }

        if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
            document.querySelectorAll('#auralisNewEventModal [data-bs-toggle="tooltip"]').forEach(function (el) {
                window.bootstrap.Tooltip.getOrCreateInstance(el);
            });
        }

        const idsalaEl = document.getElementById('zEvIdsala');
        if (idsalaEl) {
            idsalaEl.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                if (!opt) {
                    return;
                }
                const alm = opt.getAttribute('data-codalmacen');
                const fAlm = document.getElementById('zEvCodalmacen');
                if (fAlm && alm) {
                    fAlm.value = alm;
                }
            });
        }
    }

    function escapeDayHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatNaturalDayDate(dateStr) {
        if (!dateStr || typeof dateStr !== 'string') {
            return '';
        }
        const parts = dateStr.split('-');
        if (parts.length !== 3) {
            return dateStr;
        }
        const dateObj = new Date(
            parseInt(parts[0], 10),
            parseInt(parts[1], 10) - 1,
            parseInt(parts[2], 10)
        );
        if (Number.isNaN(dateObj.getTime())) {
            return dateStr;
        }
        const full = dateObj.toLocaleDateString('es-ES', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        return full.charAt(0).toUpperCase() + full.slice(1);
    }

    function dayOverflowTimeLabel(ev, allDayLabel) {
        const todoDia = ev.todo_dia === 1 || ev.todo_dia === '1' || ev.todo_dia === true;
        if (todoDia) {
            return allDayLabel;
        }
        const startRaw = ev.hora_inicio ? String(ev.hora_inicio).slice(0, 5) : '';
        if (startRaw && /^\d{2}:\d{2}$/.test(startRaw)) {
            const dur = parseInt(ev.duracion, 10);
            if (!Number.isNaN(dur) && dur > 0) {
                const bits = startRaw.split(':');
                const total = (parseInt(bits[0], 10) * 60) + parseInt(bits[1], 10) + dur;
                const eh = String(Math.floor(total / 60) % 24).padStart(2, '0');
                const em = String(total % 60).padStart(2, '0');
                return startRaw + ' – ' + eh + ':' + em;
            }
            return startRaw;
        }
        return ev.subtitle ? String(ev.subtitle) : '';
    }

    function renderReceiptOverflowCard(ev) {
        const title = escapeDayHtml(ev.title || ev.titulo || '');
        const subtitle = escapeDayHtml(ev.subtitle || '');
        const badgeText = escapeDayHtml(ev.badge_text || '');
        const badgeColor = escapeDayHtml(ev.badge_color || 'yellow');
        const iconHtml = ev.icon
            ? '<i class="' + escapeDayHtml(ev.icon) + ' z-card-icon-inline"></i> '
            : '';
        const rawUrl = String(ev.url || '#');
        const safeUrl = (rawUrl.startsWith('#') || rawUrl.startsWith('/') || /^[a-zA-Z0-9_-]+\?/.test(rawUrl))
            ? escapeDayHtml(rawUrl)
            : '#';

        const a = document.createElement('a');
        a.href = safeUrl;
        a.className = 'z-calendar-card border-left-' + badgeColor + ' text-decoration-none';
        a.title = (ev.title || '') + (ev.subtitle ? ' - ' + ev.subtitle : '');
        a.innerHTML =
            '<div class="z-calendar-card-header">' +
            '<span class="z-calendar-card-title text-truncate">' + iconHtml + title + '</span>' +
            (badgeText
                ? '<span class="z-badge z-badge-pill badge-' + badgeColor + '">' + badgeText + '</span>'
                : '') +
            '</div>' +
            (subtitle
                ? '<div class="z-calendar-card-body"><div class="z-calendar-card-subtitle">' + subtitle + '</div></div>'
                : '');
        return a;
    }

    function renderEventOverflowCard(ev, allDayLabel) {
        const title = escapeDayHtml(ev.title || ev.titulo || '');
        const badgeText = escapeDayHtml(ev.badge_text || '');
        const timeLabel = escapeDayHtml(dayOverflowTimeLabel(ev, allDayLabel));
        const color = String(ev.bg_color || ev.border_color || '').trim();
        const hasHex = color.charAt(0) === '#';
        const badgeColor = escapeDayHtml(ev.badge_color || 'blue');

        const a = document.createElement('a');
        a.href = '#';
        a.className = 'z-calendar-card text-decoration-none z-day-overflow-event-card'
            + (hasHex ? '' : ' border-left-' + badgeColor);
        if (hasHex) {
            a.style.borderLeft = '4px solid ' + color;
        }
        a.title = (ev.title || '') + (timeLabel ? ' - ' + timeLabel : '');
        a.innerHTML =
            '<div class="z-calendar-card-header">' +
            '<span class="z-calendar-card-title text-break">' + title + '</span>' +
            (badgeText
                ? '<span class="z-badge z-badge-pill badge-' + badgeColor + '">' + badgeText + '</span>'
                : '') +
            '</div>' +
            (timeLabel
                ? '<div class="z-calendar-card-body"><div class="z-calendar-card-subtitle">'
                + '<i class="fa-regular fa-clock me-1"></i>' + timeLabel
                + '</div></div>'
                : '');
        return a;
    }

    /**
     * Eventos de la celda mensual, incluidas las tarjetas ocultas (a partir de la 3.ª).
     * Una tarjeta con JSON ilegible no anula el resto del día.
     */
    function collectDayCardEvents(buttonEl) {
        const day = buttonEl && typeof buttonEl.closest === 'function'
            ? buttonEl.closest('.z-calendar-day')
            : null;
        if (!day) {
            return [];
        }
        const events = [];
        day.querySelectorAll('.z-calendar-card[data-event-json]').forEach(function (el) {
            const raw = el.getAttribute('data-event-json');
            if (!raw) {
                return;
            }
            try {
                events.push(JSON.parse(raw));
            } catch (err) {
                // Siguiente tarjeta.
            }
        });
        return events;
    }

    /**
     * Modal de desglose diario ("+X más…") compartido.
     * opts: { dateStr, events, mode: 'events'|'receipts', allowCreate, onEventClick, onCreate, titlePrefix }
     */
    function openDayOverflow(opts) {
        opts = opts || {};
        const dateStr = opts.dateStr || '';
        const events = Array.isArray(opts.events) ? opts.events : [];
        const mode = opts.mode === 'receipts' ? 'receipts' : 'events';
        const allowCreate = !!opts.allowCreate && mode === 'events';
        const modalEl = document.getElementById('auralisCalendarDayModal');
        if (!modalEl || !window.bootstrap) {
            return;
        }

        const labelEvent = modalEl.getAttribute('data-label-event') || 'evento';
        const labelEvents = modalEl.getAttribute('data-label-events') || 'eventos';
        const labelReceipt = modalEl.getAttribute('data-label-receipt') || 'recibo';
        const labelReceipts = modalEl.getAttribute('data-label-receipts') || 'recibos';
        const labelAllDay = modalEl.getAttribute('data-label-all-day') || 'Todo el día';
        const labelAdd = modalEl.getAttribute('data-label-add') || 'Nuevo evento';
        const dayKind = opts.titlePrefix != null
            ? String(opts.titlePrefix)
            : (modalEl.getAttribute('data-day-kind') || '');

        const modalTitle = document.getElementById('auralisCalendarDayModalLabel');
        const modalCount = document.getElementById('auralisCalendarDayModalCount');
        const modalBody = document.getElementById('auralisCalendarDayModalBody');
        const addBtn = document.getElementById('auralisCalendarDayModalAdd');
        const addLabel = document.getElementById('auralisCalendarDayModalAddLabel');

        const natural = formatNaturalDayDate(dateStr);
        if (modalTitle) {
            const iconClass = mode === 'receipts' ? 'fa-solid fa-receipt' : 'fa-solid fa-calendar-days';
            const icon = '<i class="' + iconClass + ' me-2 text-primary"></i>';
            const titleText = dayKind
                ? (dayKind + (natural ? ': ' + natural : ''))
                : (natural || dateStr);
            modalTitle.innerHTML = icon + escapeDayHtml(titleText);
        }
        if (modalCount) {
            const n = events.length;
            if (mode === 'receipts') {
                modalCount.textContent = n + ' ' + (n === 1 ? labelReceipt : labelReceipts);
            } else {
                modalCount.textContent = n + ' ' + (n === 1 ? labelEvent : labelEvents);
            }
        }
        if (addLabel) {
            addLabel.textContent = labelAdd;
        }
        if (addBtn) {
            addBtn.classList.toggle('d-none', !allowCreate);
            addBtn.onclick = function () {
                const inst = bootstrap.Modal.getInstance(modalEl);
                if (inst) {
                    inst.hide();
                }
                if (typeof opts.onCreate === 'function') {
                    opts.onCreate(dateStr);
                }
            };
        }

        if (modalBody) {
            modalBody.innerHTML = '';
            const list = document.createElement('div');
            list.className = 'd-flex flex-column gap-2 z-day-overflow-list';

            events.forEach(function (ev) {
                const card = mode === 'receipts'
                    ? renderReceiptOverflowCard(ev)
                    : renderEventOverflowCard(ev, labelAllDay);
                card.addEventListener('click', function (evClick) {
                    evClick.preventDefault();
                    const inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) {
                        inst.hide();
                    }
                    if (typeof opts.onEventClick === 'function') {
                        opts.onEventClick(ev, evClick);
                    }
                });
                list.appendChild(card);
            });
            modalBody.appendChild(list);
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    /**
     * Blanco u oscuro según el contraste con el fondo (mismo criterio que AuralisCalendarEvent::colorTextoSobre).
     */
    function colorTextoSobre(fondo) {
        let hex = String(fondo || '').trim().replace(/^#/, '');
        if (hex.length === 3) {
            hex = hex.split('').map(function (c) { return c + c; }).join('');
        }
        if (!/^[0-9a-f]{6}$/i.test(hex)) {
            return '#ffffff';
        }
        const luminancia = function (h) {
            const canales = [0, 2, 4].map(function (pos) {
                const c = parseInt(h.substr(pos, 2), 16) / 255;
                return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * canales[0] + 0.7152 * canales[1] + 0.0722 * canales[2];
        };
        const l = luminancia(hex);
        const contrasteBlanco = 1.05 / (l + 0.05);
        const contrasteOscuro = (l + 0.05) / (luminancia('1f2937') + 0.05);
        return (contrasteBlanco < 3 && contrasteOscuro > contrasteBlanco) ? '#1f2937' : '#ffffff';
    }

    window.AuralisCalendarModal = {
        colorTextoSobre: colorTextoSobre,
        init: initCalendarEventModal,
        resetRecurrenceFields: resetRecurrenceFields,
        setColor: setColor,
        syncTodoDia: toggleTodoDiaInputs,
        syncRecurrence: toggleRecurrenceFields,
        toggleRepEndFields: toggleRepEndFields,
        askRecurrenceScope: askRecurrenceScope,
        getDefaultDuration: getDefaultDuration,
        syncEndFromDuration: syncEndFromDuration,
        syncDurationFromEnd: syncDurationFromEnd,
        rememberStartTime: rememberStartTime,
        collectDayCards: collectDayCardEvents,
        setNotificationsExpanded: setNotificationsExpanded,
        openDayOverflow: openDayOverflow
    };

    document.addEventListener('DOMContentLoaded', initCalendarEventModal);
})();

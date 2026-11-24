/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Calendario timegrid interactivo genérico de Auralis para satélites (AJAX calendar-events / save-event / delete-event).
 *
 * Enganche para otros plugins: filtros propios que deban viajar en calendar-events.
 *   window.AuralisCalendarParams = window.AuralisCalendarParams || [];
 *   window.AuralisCalendarParams.push(function (params) { params.set('mi_filtro', valor); });
 *   window.AuralisCalendarPage.reload();   // tras cambiar el filtro
 * El parámetro lo lee el controlador de la página (el satélite, o un plugin que extienda CalendarioUsuario).
 */

document.addEventListener('DOMContentLoaded', function () {
    const dataEl = document.getElementById('auralis-calendar-data') || document.getElementById('zsalud-calendar-data');
    if (!dataEl) {
        return;
    }

    let pageData = {};
    try {
        pageData = JSON.parse(dataEl.textContent || '{}');
    } catch (e) {
        console.error('[AuralisCalendar] JSON inválido', e);
        return;
    }

    const origenTipoDefault = pageData.origenTipo || 'general';
    // Teléfono → 1 día; tablet → 3 días; escritorio → semana (misma funcionalidad, mejor densidad).
    const defaultViewType = (window.innerWidth < 576)
        ? '1day'
        : (window.innerWidth < 992 ? '3days' : 'week');
    const calendarState = {
        currentDate: new Date(pageData.calendarCenterDate || new Date().toISOString().slice(0, 10)),
        viewType: defaultViewType
    };

    const btnCalPrev = document.getElementById('btnCalPrev');
    const btnCalNext = document.getElementById('btnCalNext');
    const btnCalToday = document.getElementById('btnCalToday');

    if (btnCalPrev) {
        btnCalPrev.addEventListener('click', () => shiftCalendarDate(-1));
    }
    if (btnCalNext) {
        btnCalNext.addEventListener('click', () => shiftCalendarDate(1));
    }
    if (btnCalToday) {
        btnCalToday.addEventListener('click', () => {
            calendarState.currentDate = new Date();
            loadCalendarAjax();
        });
    }

    document.querySelectorAll('[data-cal-view]').forEach(btn => {
        if (btn.getAttribute('data-cal-view') === calendarState.viewType) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-cal-view]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            calendarState.viewType = this.getAttribute('data-cal-view');
            loadCalendarAjax();
        });
    });

    const calTitleRange = document.getElementById('calTitleRange');
    const calJumpDate = document.getElementById('calJumpDate');
    if (calTitleRange && calJumpDate) {
        calTitleRange.addEventListener('click', function () {
            if (typeof calJumpDate.showPicker === 'function') {
                calJumpDate.showPicker();
            } else {
                calJumpDate.click();
            }
        });
        calJumpDate.addEventListener('change', function () {
            if (!this.value) {
                return;
            }
            const parts = this.value.split('-');
            if (parts.length === 3) {
                calendarState.currentDate = new Date(
                    parseInt(parts[0], 10),
                    parseInt(parts[1], 10) - 1,
                    parseInt(parts[2], 10)
                );
                loadCalendarAjax();
            }
        });
    }

    ['calFilterAlmacen', 'calFilterTipo', 'calFilterEstado', 'calFilterAgente'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', () => loadCalendarAjax());
        }
    });

    if (window.AuralisCalendarParticipants && typeof window.AuralisCalendarParticipants.initHeaderFilter === 'function') {
        window.AuralisCalendarParticipants.initHeaderFilter(function () {
            loadCalendarAjax();
        });
    }

    function shiftCalendarDate(direction) {
        const days = (calendarState.viewType === '1day') ? 1
            : ((calendarState.viewType === '3days') ? 3
                : (calendarState.viewType === 'week' ? 7 : 30));

        if (calendarState.viewType === 'month') {
            calendarState.currentDate.setMonth(calendarState.currentDate.getMonth() + direction);
        } else {
            calendarState.currentDate.setDate(calendarState.currentDate.getDate() + (direction * days));
        }
        loadCalendarAjax();
    }

    function loadCalendarAjax() {
        const container = document.querySelector('.z-calendar-container');
        if (!container) {
            return Promise.resolve();
        }

        container.style.opacity = '0.5';

        let start = new Date(calendarState.currentDate);
        let end = new Date(calendarState.currentDate);

        if (calendarState.viewType === '1day') {
            // mismo día
        } else if (calendarState.viewType === '3days') {
            end.setDate(end.getDate() + 2);
        } else if (calendarState.viewType === 'week') {
            const dayOfWeek = start.getDay();
            const distanceToMonday = (dayOfWeek === 0 ? -6 : 1 - dayOfWeek);
            start.setDate(start.getDate() + distanceToMonday);
            end = new Date(start);
            end.setDate(start.getDate() + 6);
        } else {
            start.setDate(1);
            end.setMonth(end.getMonth() + 1);
            end.setDate(0);
        }

        const fmtStart = formatDateString(start);
        const fmtEnd = formatDateString(end);

        const titleEl = document.getElementById('calTitleRange');
        if (titleEl) {
            titleEl.textContent = formatRangeLabel(start, end, calendarState.viewType);
        }

        const url = new URL(window.location.href);
        url.searchParams.set('action', 'calendar-events');
        url.searchParams.set('view', calendarState.viewType);
        url.searchParams.set('desde', fmtStart);
        url.searchParams.set('hasta', fmtEnd);
        url.searchParams.set('allow_create', '1');
        url.searchParams.set('origen_tipo', origenTipoDefault);

        const calFilterAlmacen = document.getElementById('calFilterAlmacen');
        if (calFilterAlmacen && calFilterAlmacen.value) {
            url.searchParams.set('codalmacen', calFilterAlmacen.value);
        }
        const calFilterTipo = document.getElementById('calFilterTipo');
        if (calFilterTipo && calFilterTipo.value) {
            url.searchParams.set('idtipo', calFilterTipo.value);
        }
        const calFilterEstado = document.getElementById('calFilterEstado');
        if (calFilterEstado && calFilterEstado.value) {
            url.searchParams.set('estado', calFilterEstado.value);
        }
        const calFilterAgente = document.getElementById('calFilterAgente');
        if (calFilterAgente && calFilterAgente.value) {
            url.searchParams.set('codagente', calFilterAgente.value);
        }
        const calFilterParticipante = document.getElementById('calFilterParticipante');
        if (calFilterParticipante && calFilterParticipante.value) {
            url.searchParams.set('participant', calFilterParticipante.value);
        }
        // Lateral de calendarios (Mi calendario): añade calendarios[] con los marcados.
        if (window.AuralisCalendarSidebar && typeof window.AuralisCalendarSidebar.appendParams === 'function') {
            window.AuralisCalendarSidebar.appendParams(url.searchParams);
        }
        // Parámetros de otros plugins (ver cabecera del fichero).
        (Array.isArray(window.AuralisCalendarParams) ? window.AuralisCalendarParams : []).forEach(function (fn) {
            if (typeof fn !== 'function') {
                return;
            }
            try {
                fn(url.searchParams);
            } catch (err) {
                console.error('[AuralisCalendar] parámetro de plugin', err);
            }
        });

        return fetch(url.toString())
            .then(res => {
                const contentType = res.headers.get('Content-Type') || '';
                if (!res.ok || !contentType.includes('application/json')) {
                    return res.text().then(text => {
                        console.error('[AuralisCalendar] Respuesta no-JSON', res.status, text.substring(0, 300));
                        container.innerHTML = '<div class="alert alert-danger m-3">Error al cargar el calendario.</div>';
                        throw new Error('Respuesta no-JSON: ' + res.status);
                    });
                }
                return res.json();
            })
            .then(data => {
                if (!data) {
                    return;
                }
                if (data.html !== undefined) {
                    container.innerHTML = data.html || '<div class="p-3 text-muted text-center">Sin eventos</div>';
                    ajustarAltoAgenda();
                    if (window.AuralisTimegrid && typeof window.AuralisTimegrid.initScroll === 'function') {
                        window.AuralisTimegrid.initScroll(container);
                    }
                    document.dispatchEvent(new CustomEvent('auralis-calendar-loaded', {
                        detail: {
                            container: container,
                            fecha: new Date(calendarState.currentDate),
                            view: calendarState.viewType,
                            desde: fmtStart,
                            hasta: fmtEnd
                        }
                    }));
                }
            })
            .catch(err => console.error('[AuralisCalendar] fetch', err))
            .finally(() => {
                container.style.opacity = '1';
            });
    }

    /**
     * Agenda de página completa (.auralis-calendar-page): la rejilla ocupa el alto libre de la ventana para que
     * solo se desplace ella (sin scroll de página encima). Con lateral, este tiene su propio scroll en su columna.
     * El widget del Dashboard no se toca. En vista mes no hay rejilla con scroll: se desplaza la página.
     */
    function ajustarAltoAgenda() {
        const container = document.querySelector('.z-calendar-container');
        const pagina = container ? container.closest('.auralis-calendar-page') : null;
        if (!pagina) {
            return;
        }
        // El alto mínimo de la caja (420px / 70vh en móvil) solo evitaba saltos; con la rejilla ajustada sobra.
        container.style.minHeight = '0px';
        const abajo = function (el) {
            return el.getBoundingClientRect().bottom + window.scrollY;
        };
        // Fin real del contenido (el <body> puede tener min-height): elementos en flujo hijos de body.
        let finContenido = abajo(pagina);
        Array.prototype.forEach.call(document.body.children, function (el) {
            const estilo = window.getComputedStyle(el);
            if (estilo.display !== 'none' && (estilo.position === 'static' || estilo.position === 'relative')) {
                finContenido = Math.max(finContenido, abajo(el));
            }
        });
        // + el relleno inferior del <body> (en móvil deja hueco a la barra fija de abajo).
        const trasPagina = finContenido - abajo(pagina)
            + (parseFloat(window.getComputedStyle(document.body).paddingBottom) || 0);
        // Alto visible sin la barra horizontal (innerHeight la incluye).
        const altoVentana = document.documentElement.clientHeight || window.innerHeight;

        const lateral = document.getElementById('auralisCalendarSidebar');
        const layout = lateral ? lateral.parentElement : null;
        const relleno = layout ? abajo(pagina) - abajo(layout) : 0;
        if (lateral) {
            const escritorio = window.matchMedia('(min-width: 992px)').matches;
            lateral.style.maxHeight = escritorio
                ? Math.max(240, Math.floor(altoVentana - (lateral.getBoundingClientRect().top + window.scrollY) - relleno - trasPagina)) + 'px'
                : '';
        }

        const rejilla = container.querySelector('.z-timegrid-body');
        if (!rejilla) {
            return;
        }
        const columna = container.closest('.auralis-calendar-main') || pagina;
        const bajoRejilla = abajo(columna) - abajo(rejilla);
        const alto = altoVentana - (rejilla.getBoundingClientRect().top + window.scrollY)
            - bajoRejilla - relleno - trasPagina;
        rejilla.style.maxHeight = Math.max(240, Math.floor(alto)) + 'px';

        // Segunda pasada: márgenes que no se ven en las cajas (p. ej. del tema) aún desbordan unos píxeles.
        const exceso = document.documentElement.scrollHeight - altoVentana;
        if (exceso > 0 && exceso < 48) {
            rejilla.style.maxHeight = Math.max(240, Math.floor(alto) - exceso) + 'px';
            if (lateral && lateral.style.maxHeight) {
                lateral.style.maxHeight = Math.max(240, parseInt(lateral.style.maxHeight, 10) - exceso) + 'px';
            }
        }

        ajustarHorario(container, rejilla, parseInt(rejilla.style.maxHeight, 10) || 0);
    }

    /** Alto por hora (px): por debajo de HORA_MIN cuesta pulsar y arrastrar; por encima de HORA_MAX sobra. */
    const HORA_MIN = 40;
    const HORA_MAX = 80;
    const LS_24H = 'auralis-calendar-24h';

    function ver24h() {
        try {
            return window.localStorage.getItem(LS_24H) === '1';
        } catch (e) {
            return false;
        }
    }

    /**
     * Horario visible: la rejilla pinta solo de calendar_hora_inicio (data-scroll-hour) a calendar_hora_fin
     * (data-hora-fin), ampliado para que entren los eventos de los días mostrados; «24 h» enseña el día entero.
     * Las horas de fuera se ocultan y los eventos se recolocan en el tramo (auralis-timegrid.js lee
     * data-rango-inicio / data-rango-horas para la línea de ahora y el arrastre). Si el tramo cabe con horas de
     * HORA_MIN px o más, se ve entero sin scroll; si no, se desplaza solo dentro del tramo.
     */
    function ajustarHorario(container, rejilla, altoDisponible) {
        const wrapper = container.querySelector('.z-timegrid-wrapper');
        if (!wrapper || !wrapper.hasAttribute('data-hora-fin')) {
            return;
        }
        let inicio = parseInt(wrapper.getAttribute('data-scroll-hour') || '0', 10) || 0;
        let fin = parseInt(wrapper.getAttribute('data-hora-fin') || '24', 10) || 24;
        if (ver24h()) {
            inicio = 0;
            fin = 24;
        }
        const eventos = [];
        wrapper.querySelectorAll('.z-timegrid-event[data-event-json]').forEach(function (el) {
            let card = null;
            try {
                card = JSON.parse(el.getAttribute('data-event-json') || '{}');
            } catch (e) {
                return;
            }
            const partes = String(card.hora_inicio || '').split(':');
            if (partes.length < 2) {
                return;
            }
            const desde = (parseInt(partes[0], 10) || 0) * 60 + (parseInt(partes[1], 10) || 0);
            const hasta = Math.min(1440, desde + (parseInt(card.duracion, 10) || 0));
            eventos.push({ el: el, desde: desde, hasta: hasta });
            inicio = Math.min(inicio, Math.floor(desde / 60));
            fin = Math.max(fin, Math.ceil(hasta / 60));
        });
        const horas = Math.max(1, fin - inicio);

        wrapper.setAttribute('data-rango-inicio', String(inicio));
        wrapper.setAttribute('data-rango-horas', String(horas));
        const fuera = function (hora) {
            return hora < inicio || hora >= fin;
        };
        wrapper.querySelectorAll('.z-timegrid-time-axis .z-timegrid-hour-slot').forEach(function (slot, hora) {
            slot.classList.toggle('z-fuera-horario', fuera(hora));
        });
        wrapper.querySelectorAll('.z-timegrid-hour-cell[data-hour-num]').forEach(function (celda) {
            celda.classList.toggle('z-fuera-horario', fuera(parseInt(celda.getAttribute('data-hour-num'), 10)));
        });
        // Misma regla que AuralisCalendarEvent::calcularPosicion (alto mínimo 1,38 % del día), sobre el tramo.
        const minimo = 1.38 * 24 / horas;
        eventos.forEach(function (ev) {
            ev.el.style.top = (((ev.desde / 60) - inicio) / horas * 100) + '%';
            ev.el.style.height = Math.max(minimo, ((ev.hasta - ev.desde) / 60) / horas * 100) + '%';
        });

        const porHora = Math.floor(altoDisponible / horas);
        if (porHora < HORA_MIN) {
            wrapper.style.removeProperty('--z-slot-h');
            rejilla.style.overflowY = '';
        } else {
            const alto = Math.min(porHora, HORA_MAX);
            wrapper.style.setProperty('--z-slot-h', alto + 'px');
            rejilla.style.maxHeight = (horas * alto) + 'px';
            rejilla.style.overflowY = 'hidden';
        }

        // Rango y alto por hora ya fijados: la línea «ahora» se recoloca con ellos.
        repintarLineaAhora(container);
    }

    function repintarLineaAhora(ambito) {
        if (window.AuralisTimegrid && typeof window.AuralisTimegrid.updateNowMarker === 'function') {
            window.AuralisTimegrid.updateNowMarker(ambito);
        }
    }

    // «24 h»: enseña el día entero (con scroll); se recuerda por navegador. Sin sentido en la vista mes.
    const btn24h = document.getElementById('btnCal24h');
    function pintarBoton24h() {
        if (!btn24h) {
            return;
        }
        const activo = ver24h();
        btn24h.classList.toggle('active', activo);
        btn24h.setAttribute('aria-pressed', activo ? 'true' : 'false');
        btn24h.classList.toggle('is-oculto', calendarState.viewType === 'month');
    }
    if (btn24h) {
        btn24h.addEventListener('click', function () {
            try {
                window.localStorage.setItem(LS_24H, ver24h() ? '0' : '1');
            } catch (e) {
                /* sin almacenamiento: no se recuerda */
            }
            pintarBoton24h();
            loadCalendarAjax();
        });
        document.querySelectorAll('[data-cal-view]').forEach(function (b) {
            b.addEventListener('click', pintarBoton24h);
        });
        pintarBoton24h();
    }

    let ajusteAltoPendiente = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(ajusteAltoPendiente);
        ajusteAltoPendiente = window.setTimeout(function () {
            ajustarAltoAgenda();
            // También fuera de la agenda de página completa (p. ej. widget del Dashboard).
            repintarLineaAhora(document);
        }, 150);
    });

    function formatDateString(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function formatLabelDate(date) {
        return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * Rango compacto sin repetir mes ni año: «27 oct 2026», «27 – 29 oct 2026», «26 oct – 1 nov 2026»,
     * «29 dic 2026 – 4 ene 2027»; en vista mes, «Octubre de 2026».
     */
    function formatRangeLabel(start, end, viewType) {
        if (viewType === 'month') {
            const label = start.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
            return label.charAt(0).toUpperCase() + label.slice(1);
        }
        if (viewType === '1day' || start.toDateString() === end.toDateString()) {
            return formatLabelDate(start);
        }
        const sameYear = start.getFullYear() === end.getFullYear();
        const sameMonth = sameYear && start.getMonth() === end.getMonth();
        const startLabel = sameMonth
            ? String(start.getDate())
            : start.toLocaleDateString('es-ES', sameYear
                ? { day: 'numeric', month: 'short' }
                : { day: 'numeric', month: 'short', year: 'numeric' });
        return `${startLabel} – ${formatLabelDate(end)}`;
    }

    // Modal de día (+ X más)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.z-btn-more-events');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const dateStr = btn.getAttribute('data-date');
            const wrapper = btn.closest('.z-calendar-wrapper, .z-timegrid-wrapper');
            const allowCreate = wrapper
                ? wrapper.getAttribute('data-allow-create') === '1'
                : true;
            const origenTipo = wrapper
                ? (wrapper.getAttribute('data-origen-tipo') || origenTipoDefault)
                : origenTipoDefault;
            let events = [];
            if (window.AuralisCalendarModal && typeof window.AuralisCalendarModal.collectDayCards === 'function') {
                events = window.AuralisCalendarModal.collectDayCards(btn);
            }
            if (!events.length) {
                const legacy = btn.getAttribute('data-events-json');
                if (legacy) {
                    try {
                        const parsed = JSON.parse(legacy);
                        if (Array.isArray(parsed)) {
                            events = parsed;
                        }
                    } catch (err) {
                        console.error(err);
                    }
                }
            }

            if (window.AuralisCalendarModal && typeof window.AuralisCalendarModal.openDayOverflow === 'function') {
                window.AuralisCalendarModal.openDayOverflow({
                    dateStr: dateStr || '',
                    events: events,
                    mode: 'events',
                    allowCreate: allowCreate,
                    titlePrefix: '',
                    onEventClick: function (ev) {
                        openEditEventModal(ev);
                    },
                    onCreate: function (d) {
                        openNewEventModal(d, '09:00', origenTipo);
                    }
                });
            }
            return;
        }

        const eventEl = e.target.closest('[data-event-json]');
        if (eventEl && (eventEl.classList.contains('z-timegrid-event')
            || eventEl.classList.contains('z-timegrid-allday-card')
            || eventEl.classList.contains('z-calendar-card'))) {
            const eventJson = eventEl.getAttribute('data-event-json');
            if (eventJson) {
                e.preventDefault();
                e.stopPropagation();
                try {
                    openEditEventModal(JSON.parse(eventJson));
                } catch (err) {
                    console.error(err);
                }
                return;
            }
        }

        const hourCell = e.target.closest('.z-timegrid-hour-cell.is-creatable');
        if (hourCell) {
            const dateStr = hourCell.getAttribute('data-date');
            const hourStr = hourCell.getAttribute('data-hour');
            const wrapper = hourCell.closest('.z-timegrid-wrapper, .z-calendar-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || origenTipoDefault) : origenTipoDefault;
            openNewEventModal(dateStr, hourStr, origenTipo);
            return;
        }

        const btnAddDay = e.target.closest('.z-btn-add-day-event');
        if (btnAddDay) {
            e.preventDefault();
            e.stopPropagation();
            const dateStr = btnAddDay.getAttribute('data-date');
            const wrapper = btnAddDay.closest('.z-calendar-wrapper, .z-timegrid-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || origenTipoDefault) : origenTipoDefault;
            openNewEventModal(dateStr, '09:00', origenTipo);
            return;
        }

        const dayCell = e.target.closest('.z-calendar-day.is-creatable');
        if (dayCell && !e.target.closest('.z-calendar-card') && !e.target.closest('.z-btn-more-events')) {
            const dateStr = dayCell.getAttribute('data-date');
            const wrapper = dayCell.closest('.z-calendar-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || origenTipoDefault) : origenTipoDefault;
            openNewEventModal(dateStr, '09:00', origenTipo);
        }
    });

    function isRecurringCard(cardData) {
        if (!cardData) {
            return false;
        }
        return !!(cardData.idparent || cardData.se_repite === 1 || cardData.se_repite === '1' || cardData.se_repite === true);
    }

    /**
     * Prefiere "pendiente"; si no existe, el primer option con valor.
     */
    function defaultEstadoValue(selectEl) {
        if (!selectEl || !selectEl.options) {
            return '';
        }
        for (let i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === 'pendiente') {
                return 'pendiente';
            }
        }
        for (let j = 0; j < selectEl.options.length; j++) {
            if (selectEl.options[j].value) {
                return selectEl.options[j].value;
            }
        }
        return '';
    }

    function askRecurrenceScope(actionLabel, onSelect) {
        if (window.AuralisCalendarModal && typeof window.AuralisCalendarModal.askRecurrenceScope === 'function') {
            window.AuralisCalendarModal.askRecurrenceScope(actionLabel, onSelect);
        } else {
            const answer = window.prompt(actionLabel + '\n\n1) Solo este evento\n2) Este y los futuros', '1');
            if (typeof onSelect === 'function') {
                onSelect(answer === '2' ? 'esta_y_futuras' : (answer === '1' ? 'esta' : null));
            }
        }
    }

    function toggleRepEndFields() {
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.toggleRepEndFields();
        }
    }

    function toggleRecurrenceFields() {
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.syncRecurrence();
        }
    }

    function resetRecurrenceFields() {
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.resetRecurrenceFields();
        }
    }

    function toggleTodoDiaInputs() {
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.syncTodoDia();
        }
    }

    function openNewEventModal(dateStr, hourStr, origenTipo) {
        const modalEl = document.getElementById('auralisNewEventModal');
        if (!modalEl) {
            return;
        }

        const modalTitle = document.getElementById('auralisNewEventModalLabel');
        if (modalTitle) {
            modalTitle.innerHTML = '<i class="fa-solid fa-calendar-plus me-2 text-primary"></i>Nuevo evento';
        }

        const fId = document.getElementById('zEvId');
        const fFecha = document.getElementById('zEvFecha');
        const fHora = document.getElementById('zEvHoraInicio');
        const fOrigen = document.getElementById('zEvOrigenTipo');
        const fTitulo = document.getElementById('zEvTitulo');
        const fDesc = document.getElementById('zEvDescripcion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        const btnDelete = document.getElementById('btnDeleteAuralisEvent');
        const btnDocVenta = document.getElementById('auralisEventDocVenta');
        const fFechaFin = document.getElementById('zEvFechaFin');

        if (fId) fId.value = '';
        if (fFecha) fFecha.value = dateStr || '';
        if (fFechaFin) fFechaFin.value = dateStr || '';
        if (fHora) {
            fHora.disabled = false;
            if (window.AuralisCalendarModal && window.AuralisCalendarModal.rememberStartTime) {
                window.AuralisCalendarModal.rememberStartTime(hourStr || '09:00');
            } else {
                fHora.value = hourStr || '09:00';
            }
        }
        if (fOrigen) fOrigen.value = origenTipo || origenTipoDefault;
        if (fTitulo) fTitulo.value = '';
        if (fDesc) fDesc.value = '';
        const fDuracionNew = document.getElementById('zEvDuracion');
        if (fDuracionNew) {
            fDuracionNew.disabled = false;
            fDuracionNew.value = String(
                window.AuralisCalendarModal && window.AuralisCalendarModal.getDefaultDuration
                    ? window.AuralisCalendarModal.getDefaultDuration()
                    : 60
            );
        }
        const fCodagente = document.getElementById('zEvCodagente');
        if (fCodagente) fCodagente.value = '';
        const fIdsala = document.getElementById('zEvIdsala');
        if (fIdsala) fIdsala.value = '';
        const fCodalmacen = document.getElementById('zEvCodalmacen');
        if (fCodalmacen) fCodalmacen.value = '';
        const fIdtipo = document.getElementById('zEvIdtipo');
        if (fIdtipo) fIdtipo.value = '';
        const fEstado = document.getElementById('zEvEstado');
        if (fEstado) {
            fEstado.value = '';
        }
        // Prefill polimórfico desde URL (?origen_id=&codcliente=) o vacío en alta libre.
        const urlParams = new URLSearchParams(window.location.search);
        const fOrigenId = document.getElementById('zEvOrigenId');
        const fCodcliente = document.getElementById('zEvCodcliente');
        if (fOrigenId) {
            fOrigenId.value = urlParams.get('origen_id') || urlParams.get('idmascota') || '';
        }
        if (fCodcliente) {
            fCodcliente.value = urlParams.get('codcliente') || '';
        }
        if (fTodoDia) fTodoDia.checked = false;
        if (btnDelete) btnDelete.classList.add('d-none');
        // Doc venta: oculto en alta (requiere evento guardado); settings controlan si existe en DOM.
        if (btnDocVenta) btnDocVenta.classList.add('d-none');
        modalEl.setAttribute('data-is-recurring', '0');
        resetRecurrenceFields();
        toggleTodoDiaInputs();
        if (window.AuralisCalendarModal && window.AuralisCalendarModal.syncEndFromDuration) {
            window.AuralisCalendarModal.syncEndFromDuration();
        }
        if (window.AuralisCalendarParticipants) {
            window.AuralisCalendarParticipants.clearParticipants();
        }
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.setColor('#4285f4');
        }

        if (window.AuralisCalendarNotifications) {
            window.AuralisCalendarNotifications.loadDefaults(origenTipo || origenTipoDefault);
        }

        if (window.AuralisCalendarEventDoc && typeof window.AuralisCalendarEventDoc.syncButton === 'function') {
            window.AuralisCalendarEventDoc.syncButton(null);
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        document.dispatchEvent(new CustomEvent('auralis-calendar-new-event', {
            detail: {
                origen_tipo: origenTipo || origenTipoDefault,
                origen_id: fOrigenId ? fOrigenId.value : '',
                codcliente: fCodcliente ? fCodcliente.value : '',
                fecha: dateStr || '',
                hora: hourStr || ''
            }
        }));
    }

    function openEditEventModal(cardData) {
        // Lateral de calendarios: los «ocupado» no se abren y los de módulo van a su página.
        if (window.AuralisCalendarSidebar && typeof window.AuralisCalendarSidebar.interceptarEvento === 'function'
            && window.AuralisCalendarSidebar.interceptarEvento(cardData)) {
            return;
        }

        const dayModalEl = document.getElementById('auralisCalendarDayModal');
        if (dayModalEl) {
            const dayModalInstance = bootstrap.Modal.getInstance(dayModalEl);
            if (dayModalInstance) {
                dayModalInstance.hide();
            }
        }

        const modalEl = document.getElementById('auralisNewEventModal');
        if (!modalEl) {
            return;
        }

        const modalTitle = document.getElementById('auralisNewEventModalLabel');
        if (modalTitle) {
            modalTitle.innerHTML = '<i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar evento';
        }

        const fId = document.getElementById('zEvId');
        const fFecha = document.getElementById('zEvFecha');
        const fFechaFin = document.getElementById('zEvFechaFin');
        const fHora = document.getElementById('zEvHoraInicio');
        const fTitulo = document.getElementById('zEvTitulo');
        const fDesc = document.getElementById('zEvDescripcion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        const btnDelete = document.getElementById('btnDeleteAuralisEvent');
        const btnDocVenta = document.getElementById('auralisEventDocVenta');
        const fDuracion = document.getElementById('zEvDuracion');
        const fAsignado = document.getElementById('zEvAsignado');
        const fOrigen = document.getElementById('zEvOrigenTipo');

        if (fId) fId.value = cardData.id || '';
        if (fTitulo) fTitulo.value = cardData.title || cardData.titulo || '';
        if (fFecha) fFecha.value = cardData.fecha || cardData.date || '';
        if (fFechaFin) fFechaFin.value = cardData.fecha_fin || cardData.fecha || '';
        if (fTodoDia) {
            fTodoDia.checked = (cardData.todo_dia === true || cardData.todo_dia === 1 || cardData.todo_dia === '1');
        }
        toggleTodoDiaInputs();
        if (fHora) {
            if (window.AuralisCalendarModal && window.AuralisCalendarModal.rememberStartTime) {
                window.AuralisCalendarModal.rememberStartTime(cardData.hora_inicio || '09:00');
            } else {
                fHora.value = cardData.hora_inicio || '09:00';
            }
        }
        if (fDuracion) fDuracion.value = (cardData.duracion !== undefined && cardData.duracion !== null)
            ? cardData.duracion
            : (window.AuralisCalendarModal && window.AuralisCalendarModal.getDefaultDuration
                ? window.AuralisCalendarModal.getDefaultDuration()
                : 60);
        if (window.AuralisCalendarModal && window.AuralisCalendarModal.syncEndFromDuration) {
            window.AuralisCalendarModal.syncEndFromDuration();
        }
        // Solo notas reales: nunca subtitle (incluye franja HH:MM-HH:MM de presentación).
        if (fDesc) fDesc.value = cardData.descripcion || '';
        if (fAsignado && cardData.asignado) fAsignado.value = cardData.asignado;
        if (fOrigen) fOrigen.value = cardData.origen_tipo || origenTipoDefault;
        const fOrigenIdEdit = document.getElementById('zEvOrigenId');
        if (fOrigenIdEdit) {
            fOrigenIdEdit.value = cardData.origen_id ? String(cardData.origen_id) : '';
        }
        const fCodclienteEdit = document.getElementById('zEvCodcliente');
        if (fCodclienteEdit) {
            fCodclienteEdit.value = cardData.codcliente ? String(cardData.codcliente) : '';
        }
        if (btnDelete) btnDelete.classList.remove('d-none');
        // Doc venta: visible al editar solo si el setting lo pintó en DOM (calendar_event_doc_show).
        if (btnDocVenta) btnDocVenta.classList.remove('d-none');
        if (window.AuralisCalendarParticipants) {
            window.AuralisCalendarParticipants.setParticipants(cardData.participants || []);
        }

        const fCodagente = document.getElementById('zEvCodagente');
        if (fCodagente) fCodagente.value = cardData.codagente || '';
        const fIdsala = document.getElementById('zEvIdsala');
        if (fIdsala) fIdsala.value = cardData.idsala ? String(cardData.idsala) : '';
        const fCodalmacen = document.getElementById('zEvCodalmacen');
        if (fCodalmacen) fCodalmacen.value = cardData.codalmacen || '';
        const fIdtipo = document.getElementById('zEvIdtipo');
        if (fIdtipo) fIdtipo.value = cardData.idtipo ? String(cardData.idtipo) : '';
        const fEstado = document.getElementById('zEvEstado');
        if (fEstado) {
            const desired = cardData.estado || '';
            fEstado.value = desired;
            // Si el valor no coincide con ninguna option, Bootstrap/HTML deja el select en blanco.
            if (desired && fEstado.value !== desired) {
                fEstado.value = defaultEstadoValue(fEstado);
            }
        }

        const fSeRepite = document.getElementById('zEvSeRepite');
        const isParentSeries = !cardData.idparent && (cardData.se_repite === 1 || cardData.se_repite === '1' || cardData.se_repite === true);
        if (fSeRepite) fSeRepite.checked = isParentSeries || !!cardData.idparent;

        const fIntervalo = document.getElementById('zEvRepIntervalo');
        if (fIntervalo) fIntervalo.value = cardData.rep_intervalo || 1;
        const fUnidad = document.getElementById('zEvRepUnidad');
        if (fUnidad) fUnidad.value = cardData.rep_unidad || 'week';
        const fFinTipo = document.getElementById('zEvRepFinTipo');
        if (fFinTipo) fFinTipo.value = cardData.rep_fin_tipo || 'nunca';
        const fFinFecha = document.getElementById('zEvRepFinFecha');
        if (fFinFecha) fFinFecha.value = cardData.rep_fin_fecha || '';
        const fFinCount = document.getElementById('zEvRepFinCount');
        if (fFinCount) fFinCount.value = cardData.rep_fin_count || 10;

        modalEl.setAttribute('data-is-recurring', isRecurringCard(cardData) ? '1' : '0');
        toggleRecurrenceFields();

        if (cardData.bg_color && window.AuralisCalendarModal) {
            window.AuralisCalendarModal.setColor(cardData.bg_color);
        }

        if (window.AuralisCalendarNotifications) {
            const notifs = cardData.notifications || [];
            window.AuralisCalendarNotifications.loadFromEvent(notifs);
        }

        if (window.AuralisCalendarEventDoc && typeof window.AuralisCalendarEventDoc.syncButton === 'function') {
            window.AuralisCalendarEventDoc.syncButton(cardData || null);
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        document.dispatchEvent(new CustomEvent('auralis-calendar-edit-event', {
            detail: {
                origen_tipo: cardData.origen_tipo || origenTipoDefault,
                origen_id: cardData.origen_id || '',
                codcliente: cardData.codcliente || '',
                id: cardData.id || '',
                card: cardData
            }
        }));
    }

    function updateCsrfToken(newToken) {
        if (!newToken) {
            return;
        }
        document.querySelectorAll('input[name="multireqtoken"]').forEach(input => {
            input.value = newToken;
        });
    }

    const btnDeleteEvent = document.getElementById('btnDeleteAuralisEvent');
    if (btnDeleteEvent) {
        btnDeleteEvent.addEventListener('click', function () {
            const eventId = document.getElementById('zEvId').value;
            if (!eventId) {
                return;
            }

            const modalEl = document.getElementById('auralisNewEventModal');
            const isRecurring = modalEl && modalEl.getAttribute('data-is-recurring') === '1';

            function doDelete(scope) {
                const form = document.getElementById('formAuralisCalendarEvent');
                const params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
                params.set('action', 'delete-event');
                params.set('id', eventId);
                params.set('scope', scope || 'esta');

                fetch(window.location.pathname + '?action=delete-event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.newToken) updateCsrfToken(data.newToken);
                        if (data.success) {
                            if (modalEl) {
                                const modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                                modalInstance.hide();
                            }
                            loadCalendarAjax();
                        } else {
                            alert(data.message || 'Error al eliminar el evento');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error al eliminar el evento');
                    });
            }

            if (isRecurring) {
                askRecurrenceScope('Eliminar Evento Recurrente', function (chosen) {
                    if (!chosen) return;
                    doDelete(chosen);
                });
            } else if (confirm('¿Estás seguro de que deseas eliminar este evento?')) {
                doDelete('esta');
            }
        });
    }

    const formEvent = document.getElementById('formAuralisCalendarEvent');
    if (formEvent) {
        formEvent.addEventListener('submit', function (e) {
            e.preventDefault();

            const form = this;
            const btnSave = document.getElementById('btnSaveAuralisEvent');
            const eventId = document.getElementById('zEvId').value;
            const modalEl = document.getElementById('auralisNewEventModal');
            const isRecurring = modalEl && modalEl.getAttribute('data-is-recurring') === '1';

            function doSave(scope) {
                if (btnSave) btnSave.disabled = true;

                const formData = new FormData(form);
                formData.set('scope', scope || 'esta');
                formData.set('origen_tipo', origenTipoDefault);

                const todoDiaEl = document.getElementById('zEvTodoDia');
                if (todoDiaEl) {
                    const isAllDay = todoDiaEl.checked;
                    formData.set('todo_dia', isAllDay ? '1' : '0');
                    if (isAllDay) {
                        formData.set('hora_inicio', '00:00');
                        formData.set('duracion', '0');
                        const fechaFinEl = document.getElementById('zEvFechaFin');
                        if (fechaFinEl && fechaFinEl.value) {
                            formData.set('fecha_fin', fechaFinEl.value);
                        }
                    } else {
                        if (window.AuralisCalendarModal && window.AuralisCalendarModal.syncDurationFromEnd) {
                            window.AuralisCalendarModal.syncDurationFromEnd();
                        }
                        const durEl = document.getElementById('zEvDuracion');
                        if (durEl) {
                            formData.set('duracion', durEl.value || '60');
                        }
                        formData.set('fecha_fin', '');
                    }
                }

                const seRepiteElForm = document.getElementById('zEvSeRepite');
                formData.set('se_repite', (seRepiteElForm && seRepiteElForm.checked) ? '1' : '0');

                if (window.AuralisCalendarNotifications) {
                    window.AuralisCalendarNotifications.syncHiddenInput();
                    formData.set('notifications_json', document.getElementById('zEvNotificationsJson')?.value || '[]');
                }
                if (window.AuralisCalendarParticipants) {
                    window.AuralisCalendarParticipants.syncHiddenJson();
                    formData.set('participants_json', document.getElementById('zEvParticipantsJson')?.value || '[]');
                }

                fetch(window.location.pathname + '?action=save-event', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(formData).toString()
                })
                    .then(res => res.json())
                    .then(data => {
                        if (data.newToken) updateCsrfToken(data.newToken);
                        if (data.success) {
                            if (data.notifications_warning) {
                                alert(data.notifications_warning);
                            }
                            if (modalEl) {
                                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                                if (modalInstance) modalInstance.hide();
                            }
                            // Aviso para el lateral (p. ej. marcar el calendario donde se guardó) antes de recargar.
                            document.dispatchEvent(new CustomEvent('auralis-calendar-event-saved', {
                                detail: { idcalendario: formData.get('idcalendario') || '', response: data }
                            }));
                            formEvent.reset();
                            resetRecurrenceFields();
                            loadCalendarAjax();
                        } else {
                            alert(data.message || 'Error al guardar el evento');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error al guardar el evento');
                    })
                    .finally(() => {
                        if (btnSave) btnSave.disabled = false;
                    });
            }

            if (eventId && isRecurring) {
                askRecurrenceScope('Guardar Evento Recurrente', function (chosen) {
                    if (!chosen) return;
                    doSave(chosen);
                });
            } else {
                doSave('esta');
            }
        });
    }

    /**
     * Deep-link desde la campana: CalendarioUsuario?idevent=123 → centrar día y abrir modal.
     */
    function openDeepLinkedEvent() {
        const params = new URLSearchParams(window.location.search);
        const eventId = parseInt(params.get('idevent') || '0', 10);
        if (!eventId) {
            return false;
        }

        const url = new URL(window.location.href);
        url.searchParams.set('action', 'get-event');
        url.searchParams.set('id', String(eventId));

        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(res => res.json())
            .then(data => {
                if (!data || !data.ok || !data.event) {
                    loadCalendarAjax();
                    return;
                }
                const ev = data.event;
                if (data.can_edit !== undefined && ev.can_edit === undefined) {
                    ev.can_edit = data.can_edit;
                }
                if (data.permiso && !ev.permiso) {
                    ev.permiso = data.permiso;
                }
                if (ev.fecha) {
                    const parts = String(ev.fecha).split('-');
                    if (parts.length === 3) {
                        calendarState.currentDate = new Date(
                            parseInt(parts[0], 10),
                            parseInt(parts[1], 10) - 1,
                            parseInt(parts[2], 10)
                        );
                    }
                }
                loadCalendarAjax().then(function () {
                    openEditEventModal(ev);
                    try {
                        const clean = new URL(window.location.href);
                        clean.searchParams.delete('idevent');
                        window.history.replaceState({}, '', clean.pathname + (clean.search || '') + clean.hash);
                    } catch (e) {
                        /* ignore */
                    }
                });
            })
            .catch(err => {
                console.error('[AuralisCalendar] deep-link idevent', err);
                loadCalendarAjax();
            });

        return true;
    }

    // API de la página para el lateral (mini-calendario, Crear y recarga al cambiar calendarios).
    window.AuralisCalendarPage = {
        reload: loadCalendarAjax,
        getDate: function () {
            return new Date(calendarState.currentDate);
        },
        goToDate: function (fecha) {
            calendarState.currentDate = new Date(fecha.getFullYear(), fecha.getMonth(), fecha.getDate());
            return loadCalendarAjax();
        },
        openNew: function (dateStr, hourStr) {
            openNewEventModal(dateStr, hourStr, origenTipoDefault);
        }
    };

    if (!openDeepLinkedEvent()) {
        loadCalendarAjax();
    }
});

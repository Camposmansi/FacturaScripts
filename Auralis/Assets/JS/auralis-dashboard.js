/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Lógica interactiva para el Dashboard de Auralis
 */

// Helper para sanitizar strings dinámicos antes de insertarlos en el DOM
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Global Error Logger for UI Debugging (Solo activo si AURALIS_DEBUG está habilitado explícitamente)
if (window.AURALIS_DEBUG) {
    window.onerror = function (message, source, lineno, colno, error) {
        console.error('[Auralis Error]', message, source, lineno, colno, error);
        return false;
    };
    window.onunhandledrejection = function (event) {
        console.error('[Auralis Promise Rejection]', event.reason);
    };
}

document.addEventListener('DOMContentLoaded', function () {
    // 1. Cargar datos embebidos del PHP
    const dataEl = document.getElementById('auralis-dash-data');
    if (!dataEl) return;
    
    const dashData = JSON.parse(dataEl.textContent);
    
    // Control de Pestañas Móviles (Analítica / Tesorería)
    const tabBtns = document.querySelectorAll('.auralis-mobile-tab-btn');
    if (tabBtns.length > 0) {
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                tabBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const tab = this.getAttribute('data-tab');
                const pAnalytics = document.querySelector('.auralis-pane-analytics');
                const pTreasury = document.querySelector('.auralis-pane-treasury');
                
                if (tab === 'treasury') {
                    if (pAnalytics) pAnalytics.style.display = 'none';
                    if (pTreasury) pTreasury.style.display = 'block';
                } else {
                    if (pAnalytics) pAnalytics.style.display = 'block';
                    if (pTreasury) pTreasury.style.display = 'none';
                }
            });
        });
    }
    
    // 2. Inicializar gráfico de evolución configurable
    const ctxTrend = document.getElementById('zChartEvolution');
    if (ctxTrend && dashData.evolution) {
        // Obtener el color primario del tema dinámicamente
        const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--v-primary').trim() ||
                            getComputedStyle(document.documentElement).getPropertyValue('--bs-primary').trim() ||
                            '#10B981';

        const evolution = dashData.evolution;
        const datasets = [
            {
                label: evolution.salesLabel || 'Ventas',
                data: evolution.ventas,
                borderColor: primaryColor,
                backgroundColor: primaryColor.startsWith('#') ? primaryColor + '24' : 'rgba(16, 185, 129, 0.14)',
                tension: 0.3,
                fill: evolution.chartType === 'line',
                borderWidth: 2,
                pointRadius: evolution.labels.length > 40 ? 0 : 3,
                pointBackgroundColor: primaryColor
            },
            {
                label: evolution.expensesLabel || 'Compras',
                data: evolution.gastos,
                borderColor: '#EF4444',
                backgroundColor: 'rgba(239, 68, 68, 0.14)',
                tension: 0.3,
                fill: evolution.chartType === 'line',
                borderWidth: 2,
                pointRadius: evolution.labels.length > 40 ? 0 : 3,
                pointBackgroundColor: '#EF4444'
            }
        ];

        if (Array.isArray(evolution.ventasAnterior) && evolution.ventasAnterior.length) {
            datasets.push({
                label: (evolution.salesLabel || 'Ventas') + ' · periodo anterior',
                data: evolution.ventasAnterior,
                borderColor: primaryColor,
                backgroundColor: 'transparent',
                borderDash: [7, 5],
                tension: 0.25,
                fill: false,
                borderWidth: 1.5,
                pointRadius: 0
            });
            datasets.push({
                label: (evolution.expensesLabel || 'Compras') + ' · periodo anterior',
                data: evolution.gastosAnterior,
                borderColor: '#EF4444',
                backgroundColor: 'transparent',
                borderDash: [7, 5],
                tension: 0.25,
                fill: false,
                borderWidth: 1.5,
                pointRadius: 0
            });
        }

        new Chart(ctxTrend, {
            type: evolution.chartType || 'line',
            data: {
                labels: evolution.labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += new Intl.NumberFormat('es-ES', { style: 'currency', currency: dashData.currencyCode }).format(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: function(value) {
                                return new Intl.NumberFormat('es-ES', { notation: 'compact', style: 'currency', currency: dashData.currencyCode }).format(value);
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxTicksLimit: 10,
                            autoSkip: true
                        }
                    }
                }
            }
        });
    }

    const evolutionSelectors = {
        evolutionDocument: 'evo_doc',
        evolutionGranularity: 'evo_granularity',
        evolutionMetric: 'evo_metric',
        evolutionChart: 'evo_chart'
    };
    Object.entries(evolutionSelectors).forEach(([id, parameter]) => {
        const selector = document.getElementById(id);
        if (!selector) return;
        selector.addEventListener('change', function () {
            const url = new URL(window.location.href);
            url.searchParams.set(parameter, this.value);
            window.location.href = url.toString();
        });
    });


    // 4. Lógica de Filtros Temporales (Páginas)
    const filterButtons = document.querySelectorAll('.auralis-filter-btn[data-rango]');
    filterButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const rango = this.getAttribute('data-rango');
            if (rango !== 'custom') {
                const url = new URL(window.location);
                url.searchParams.set('rango', rango);
                url.searchParams.delete('desde');
                url.searchParams.delete('hasta');
                window.location.href = url.toString();
            }
        });
    });

    // Checkbox vs Anterior
    const vsAntCheck = document.getElementById('vsAnterior');
    if (vsAntCheck) {
        vsAntCheck.addEventListener('change', function () {
            const url = new URL(window.location);
            if (this.checked) {
                url.searchParams.set('vs_anterior', '1');
            } else {
                url.searchParams.delete('vs_anterior');
            }
            window.location.href = url.toString();
        });
    }

    // Filtro de Empresa (Multiempresa)
    const filterEmpresa = document.getElementById('filterEmpresa');
    if (filterEmpresa) {
        filterEmpresa.addEventListener('change', function () {
            const url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('idempresa', this.value);
            } else {
                url.searchParams.delete('idempresa');
            }
            window.location.href = url.toString();
        });
    }

    // Aplicar Rango Personalizado desde inputs nativos
    const btnApplyDates = document.getElementById('btnApplyCustomDates');
    if (btnApplyDates) {
        btnApplyDates.addEventListener('click', function () {
            const desde = document.getElementById('filterDesde').value;
            const hasta = document.getElementById('filterHasta').value;
            if (desde && hasta) {
                const url = new URL(window.location.href);
                url.searchParams.set('rango', 'custom');
                url.searchParams.set('desde', desde);
                url.searchParams.set('hasta', hasta);
                window.location.href = url.toString();
            }
        });
    }

    function getReceiptsPageSize(container) {
        const size = parseInt(container.getAttribute('data-receipts-page-size') || '5', 10);
        return Number.isFinite(size) && size > 0 ? size : 5;
    }

    function applyReceiptsPage(pane, page) {
        const container = pane.querySelector('.z-receipts-list-group');
        const pager = pane.querySelector('.z-receipts-pager');
        if (!container) return;

        const items = Array.from(container.querySelectorAll('.z-receipt-item'));
        const pageSize = getReceiptsPageSize(container);
        const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
        const current = Math.min(Math.max(1, page), totalPages);

        items.forEach((item, idx) => {
            const itemPage = Math.floor(idx / pageSize) + 1;
            item.classList.toggle('d-none', itemPage !== current);
        });

        if (pager) {
            pager.setAttribute('data-current-page', String(current));
            const label = pager.querySelector('.z-receipts-page-label');
            const tpl = (window.AURALIS_I18N && window.AURALIS_I18N.rankingPageOf) || 'Página %current% de %total%';
            if (label) {
                label.textContent = tpl.replace('%current%', String(current)).replace('%total%', String(totalPages));
            }
            const prev = pager.querySelector('.z-receipts-prev');
            const next = pager.querySelector('.z-receipts-next');
            if (prev) prev.disabled = current <= 1;
            if (next) next.disabled = current >= totalPages;
            pager.classList.toggle('d-none', items.length <= pageSize);
        }
    }

    // Función para ordenar dinámicamente los elementos dentro de cada grupo de recibos
    function sortReceiptsInDOM(sortMode) {
        document.querySelectorAll('#auralisReceiptsDetailModal .z-receipts-list-group').forEach(container => {
            const items = Array.from(container.querySelectorAll('.z-receipt-item'));
            items.sort((a, b) => {
                const valA_imp = parseFloat(a.getAttribute('data-importe') || '0');
                const valB_imp = parseFloat(b.getAttribute('data-importe') || '0');
                const valA_dias = parseInt(a.getAttribute('data-dias') || '0', 10);
                const valB_dias = parseInt(b.getAttribute('data-dias') || '0', 10);

                switch (sortMode) {
                    case 'dias-asc':
                        return valB_dias - valA_dias; // Próximos a vencer
                    case 'importe-desc':
                        return valB_imp - valA_imp; // Importe mayor a menor
                    case 'importe-asc':
                        return valA_imp - valB_imp; // Importe menor a mayor
                    case 'dias-desc':
                    default:
                        return valA_dias - valB_dias; // Más antiguos/vencidos primero
                }
            });
            items.forEach(item => container.appendChild(item));
            const pane = container.closest('.tab-pane');
            if (pane) {
                applyReceiptsPage(pane, 1);
            }
        });
    }

    // Ordenación Dinámica de Recibos dentro del Modal (Sin recarga de página)
    document.querySelectorAll('.z-sort-receipts-select').forEach(sel => {
        sel.addEventListener('change', function () {
            sortReceiptsInDOM(this.value);
        });
    });

    // Ordenación del modal de descuadre recibos vs factura
    function sortDiffInDOM(sortMode) {
        document.querySelectorAll('#auralisReceiptsDiffModal .z-receipts-list-group').forEach(container => {
            const items = Array.from(container.querySelectorAll('.z-receipt-item'));
            items.sort((a, b) => {
                const valA_imp = parseFloat(a.getAttribute('data-importe') || '0');
                const valB_imp = parseFloat(b.getAttribute('data-importe') || '0');
                const valA_dias = parseInt(a.getAttribute('data-dias') || '0', 10);
                const valB_dias = parseInt(b.getAttribute('data-dias') || '0', 10);

                switch (sortMode) {
                    case 'fecha-asc':
                        return valB_dias - valA_dias; // Más cercanas a hoy
                    case 'fecha-desc':
                        return valA_dias - valB_dias; // Más antiguas primero
                    case 'importe-asc':
                        return valA_imp - valB_imp;
                    case 'importe-desc':
                    default:
                        return valB_imp - valA_imp;
                }
            });
            items.forEach(item => container.appendChild(item));
            const pane = container.closest('.tab-pane');
            if (pane) {
                applyReceiptsPage(pane, 1);
            }
        });
    }

    document.querySelectorAll('.z-sort-diff-select').forEach(sel => {
        sel.addEventListener('change', function () {
            sortDiffInDOM(this.value);
        });
    });

    document.querySelectorAll('.z-receipts-pager').forEach(pager => {
        const targetId = pager.getAttribute('data-receipts-target');
        const pane = targetId ? document.getElementById(targetId) : pager.closest('.tab-pane');
        if (!pane) return;

        applyReceiptsPage(pane, 1);

        const prev = pager.querySelector('.z-receipts-prev');
        const next = pager.querySelector('.z-receipts-next');
        if (prev) {
            prev.addEventListener('click', function () {
                const current = parseInt(pager.getAttribute('data-current-page') || '1', 10);
                applyReceiptsPage(pane, current - 1);
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                const current = parseInt(pager.getAttribute('data-current-page') || '1', 10);
                applyReceiptsPage(pane, current + 1);
            });
        }
    });

    function getRankingPageSize(container) {
        const size = parseInt(container.getAttribute('data-ranking-page-size') || '5', 10);
        return Number.isFinite(size) && size > 0 ? size : 5;
    }

    function applyRankingPage(pane, page) {
        const container = pane.querySelector('.z-ranking-list-group');
        const pager = pane.querySelector('.z-ranking-pager');
        if (!container) return;

        const items = Array.from(container.querySelectorAll('.z-ranking-item'));
        const pageSize = getRankingPageSize(container);
        const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
        const current = Math.min(Math.max(1, page), totalPages);

        items.forEach((item, idx) => {
            const itemPage = Math.floor(idx / pageSize) + 1;
            item.classList.toggle('d-none', itemPage !== current);
        });

        if (pager) {
            pager.setAttribute('data-current-page', String(current));
            const label = pager.querySelector('.z-ranking-page-label');
            const tpl = (window.AURALIS_I18N && window.AURALIS_I18N.rankingPageOf) || 'Página %current% de %total%';
            if (label) {
                label.textContent = tpl.replace('%current%', String(current)).replace('%total%', String(totalPages));
            }
            const prev = pager.querySelector('.z-ranking-prev');
            const next = pager.querySelector('.z-ranking-next');
            if (prev) prev.disabled = current <= 1;
            if (next) next.disabled = current >= totalPages;
        }
    }

    function sortRankingInDOM(sortMode) {
        document.querySelectorAll('.z-ranking-list-group').forEach(container => {
            const items = Array.from(container.querySelectorAll('.z-ranking-item'));
            items.sort((a, b) => {
                const valA_imp = parseFloat(a.getAttribute('data-importe') || '0');
                const valB_imp = parseFloat(b.getAttribute('data-importe') || '0');
                const rawA_ciclo = a.getAttribute('data-ciclo');
                const rawB_ciclo = b.getAttribute('data-ciclo');
                const rawA_ret = a.getAttribute('data-retraso');
                const rawB_ret = b.getAttribute('data-retraso');
                const valA_ciclo = rawA_ciclo === '' || rawA_ciclo === null ? Number.NEGATIVE_INFINITY : parseFloat(rawA_ciclo);
                const valB_ciclo = rawB_ciclo === '' || rawB_ciclo === null ? Number.NEGATIVE_INFINITY : parseFloat(rawB_ciclo);
                const valA_ret = rawA_ret === '' || rawA_ret === null ? Number.NEGATIVE_INFINITY : parseFloat(rawA_ret);
                const valB_ret = rawB_ret === '' || rawB_ret === null ? Number.NEGATIVE_INFINITY : parseFloat(rawB_ret);

                switch (sortMode) {
                    case 'ciclo-asc':
                        return valA_ciclo - valB_ciclo;
                    case 'retraso-desc':
                        return valB_ret - valA_ret;
                    case 'retraso-asc':
                        return valA_ret - valB_ret;
                    case 'importe-desc':
                        return valB_imp - valA_imp;
                    case 'importe-asc':
                        return valA_imp - valB_imp;
                    case 'ciclo-desc':
                    default:
                        return valB_ciclo - valA_ciclo;
                }
            });
            items.forEach(item => container.appendChild(item));
            const pane = container.closest('.tab-pane');
            if (pane) {
                applyRankingPage(pane, 1);
            }
        });
    }

    document.querySelectorAll('.z-sort-ranking-select').forEach(sel => {
        sel.addEventListener('change', function () {
            sortRankingInDOM(this.value);
        });
    });

    document.querySelectorAll('.z-ranking-pager').forEach(pager => {
        const targetId = pager.getAttribute('data-ranking-target');
        const pane = targetId ? document.getElementById(targetId) : pager.closest('.tab-pane');
        if (!pane) return;

        applyRankingPage(pane, 1);

        const prev = pager.querySelector('.z-ranking-prev');
        const next = pager.querySelector('.z-ranking-next');
        if (prev) {
            prev.addEventListener('click', function () {
                const current = parseInt(pager.getAttribute('data-current-page') || '1', 10);
                applyRankingPage(pane, current - 1);
            });
        }
        if (next) {
            next.addEventListener('click', function () {
                const current = parseInt(pager.getAttribute('data-current-page') || '1', 10);
                applyRankingPage(pane, current + 1);
            });
        }
    });

    const handleDateInputKey = (e) => {
        if (e.key === 'Enter') {
            if (btnApplyDates) btnApplyDates.click();
        }
    };
    const fd = document.getElementById('filterDesde');
    const fh = document.getElementById('filterHasta');
    if (fd) fd.addEventListener('keypress', handleDateInputKey);
    if (fh) fh.addEventListener('keypress', handleDateInputKey);

    // 5. Gestión del Estado del Calendario (AJAX) — SOLO receipts (tesorería)
    // DashBoardAuralis NO es el calendario interactivo de eventos (timegrid / save-event / recurrencia).
    const isNarrowPhone = window.innerWidth < 576;
    const isMobileView = window.innerWidth < 992;
    let calendarState = {
        currentDate: new Date(dashData.calendarCenterDate || dashData.fechaDesde),
        viewType: isNarrowPhone ? '1day' : (isMobileView ? '3days' : 'week'), // '1day', '3days', 'week', 'month'
        calMode: 'receipts',
        filterCobros: true,
        filterPagos: true
    };

    /** true solo si la vista incluye el modal de crear/editar eventos (timegrid interactivo). */
    function isInteractiveEventCalendar() {
        return !!document.getElementById('auralisNewEventModal');
    }

    // Actualizar filtros desde DOM
    const checkCobro = document.getElementById('calCobros');
    const checkPago = document.getElementById('calPagos');
    
    if (checkCobro) {
        checkCobro.addEventListener('change', function() {
            calendarState.filterCobros = this.checked;
            loadCalendarAjax();
        });
    }
    if (checkPago) {
        checkPago.addEventListener('change', function() {
            calendarState.filterPagos = this.checked;
            loadCalendarAjax();
        });
    }

    // Navegación del Calendario
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

    // Formato/Vista del Calendario (receipts: todas las vistas disponibles, incluido mes)
    document.querySelectorAll('[data-cal-view]').forEach(btn => {
        if (btn.getAttribute('data-cal-view') === calendarState.viewType) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }

        btn.addEventListener('click', function() {
            if (this.disabled) return;
            document.querySelectorAll('[data-cal-view]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            calendarState.viewType = this.getAttribute('data-cal-view');
            loadCalendarAjax();
        });
    });

    // Ir a una fecha específica al hacer clic en el título del calendario
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
            if (this.value) {
                const parts = this.value.split('-');
                if (parts.length === 3) {
                    calendarState.currentDate = new Date(
                        parseInt(parts[0], 10),
                        parseInt(parts[1], 10) - 1,
                        parseInt(parts[2], 10)
                    );
                    loadCalendarAjax();
                }
            }
        });
    }

    function shiftCalendarDate(direction) {
        const days = (calendarState.viewType === '1day') ? 1 : ((calendarState.viewType === '3days') ? 3 : (calendarState.viewType === 'week' ? 7 : 30));
        
        if (calendarState.viewType === 'month') {
            calendarState.currentDate.setMonth(calendarState.currentDate.getMonth() + direction);
        } else {
            calendarState.currentDate.setDate(calendarState.currentDate.getDate() + (direction * days));
        }
        loadCalendarAjax();
    }

    function loadCalendarAjax() {
        const container = document.querySelector('.z-calendar-container');
        if (!container) return;

        // Añadir efecto de opacidad cargando
        container.style.opacity = '0.5';

        // Calcular rango de fechas basado en vista y fecha central
        let start = new Date(calendarState.currentDate);
        let end = new Date(calendarState.currentDate);

        if (calendarState.viewType === '1day') {
            // Mismo día (1 día)
        } else if (calendarState.viewType === '3days') {
            // El primer día es HOY (currentDate)
            end.setDate(end.getDate() + 2);
        } else if (calendarState.viewType === 'week') {
            // Lunes a Domingo
            const dayOfWeek = start.getDay(); // 0 is Sunday
            const distanceToMonday = (dayOfWeek === 0 ? -6 : 1 - dayOfWeek);
            start.setDate(start.getDate() + distanceToMonday);
            end = new Date(start);
            end.setDate(start.getDate() + 6);
        } else {
            // Mes completo
            start.setDate(1);
            end.setMonth(end.getMonth() + 1);
            end.setDate(0); // Último día del mes
        }

        const fmtStart = formatDateString(start);
        const fmtEnd = formatDateString(end);

        // Actualizar título del calendario en pantalla
        const titleEl = document.getElementById('calTitleRange');
        if (titleEl) {
            titleEl.textContent = (calendarState.viewType === '1day')
                ? formatLabelDate(start)
                : `${formatLabelDate(start)} - ${formatLabelDate(end)}`;
        }

        // Siempre receipts: nunca calendar-events / timegrid desde el dashboard
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'receipts');
        url.searchParams.set('view', calendarState.viewType);
        url.searchParams.set('calmode', 'receipts');
        url.searchParams.set('desde', fmtStart);
        url.searchParams.set('hasta', fmtEnd);
        url.searchParams.set('cobros', calendarState.filterCobros ? '1' : '0');
        url.searchParams.set('pagos', calendarState.filterPagos ? '1' : '0');

        const empFilter = document.getElementById('filterEmpresa');
        if (empFilter && empFilter.value) {
            url.searchParams.set('idempresa', empFilter.value);
        }

        console.log('[AuralisCalendar] AJAX URL:', url.toString());

        fetch(url.toString())
            .then(res => {
                const contentType = res.headers.get('Content-Type') || '';
                if (!res.ok || !contentType.includes('application/json')) {
                    return res.text().then(text => {
                        console.error('[AuralisCalendar] Respuesta no-JSON recibida. Status:', res.status, '\nPrimeros 300 chars:', text.substring(0, 300));
                        container.innerHTML = '<div class="alert alert-danger m-3"><i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Error AJAX:</strong> El servidor no devolvió JSON. Status: ' + res.status + '<br><small>Revisa la consola del navegador (F12) para más detalles.</small></div>';
                        throw new Error('Respuesta no-JSON: ' + res.status);
                    });
                }
                return res.json();
            })
            .then(data => {
                if (!data) return;
                if (data.html !== undefined) {
                    container.innerHTML = data.html || '<div class="p-3 text-muted text-center"><i class="fa-solid fa-calendar-xmark me-2"></i>' + (window.AURALIS_I18N?.noEventsPeriod || 'Sin eventos en este período') + '</div>';
                }

                // Actualizar totales previstos en los labels de la cabecera
                const totalCobrosEl = document.getElementById('calTotalCobros');
                const totalPagosEl = document.getElementById('calTotalPagos');
                if (totalCobrosEl && data.totalCobrosFormatted) {
                    totalCobrosEl.textContent = ` (+${data.totalCobrosFormatted})`;
                }
                if (totalPagosEl && data.totalPagosFormatted) {
                    totalPagosEl.textContent = ` (-${data.totalPagosFormatted})`;
                }
            })
            .catch(err => {
                console.error('[AuralisCalendar] Error fetch:', err);
            })
            .finally(() => {
                container.style.opacity = '1';
            });
    }

    function formatDateString(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function formatLabelDate(date) {
        return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Intercept clic en "+ X más" para ver todos los recibos/eventos de un día
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.z-btn-more-events');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const dateStr = btn.getAttribute('data-date');
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
                        console.error('Error al abrir modal de eventos del día:', err);
                    }
                }
            }
            if (window.AuralisCalendarModal && typeof window.AuralisCalendarModal.openDayOverflow === 'function') {
                window.AuralisCalendarModal.openDayOverflow({
                    dateStr: dateStr || '',
                    events: events,
                    mode: 'receipts',
                    allowCreate: false,
                    onEventClick: function (card) {
                        const rawUrl = String(card.url || '#');
                        const safe = (rawUrl.startsWith('#')
                            || rawUrl.startsWith('/')
                            || /^[a-zA-Z0-9_-]+\?/.test(rawUrl));
                        if (safe && rawUrl !== '#') {
                            window.location.href = rawUrl;
                        }
                    }
                });
            }
        }
    });

    // 6. Modal de eventos: lógica compartida en auralis-calendar-modal.js (AuralisCalendarModal)

    // 7. Click-to-Create y Edición/Borrado — solo con modal de eventos (timegrid interactivo)
    document.addEventListener('click', function (e) {
        if (!isInteractiveEventCalendar()) {
            return;
        }

        // A) Click en tarjeta de evento existente para editar/borrar
        const eventEl = e.target.closest('[data-event-json]');
        if (eventEl && eventEl.getAttribute('data-event-id')) {
            const eventId = eventEl.getAttribute('data-event-id');
            const eventJson = eventEl.getAttribute('data-event-json');
            if (eventId && eventJson) {
                e.preventDefault();
                e.stopPropagation();
                try {
                    const cardData = JSON.parse(eventJson);
                    openEditEventModal(cardData);
                    return;
                } catch (err) {
                    console.error('Error al abrir evento para editar:', err);
                }
            }
        }

        // B) Click en Timegrid (recupera fecha y hora exacta)
        const hourCell = e.target.closest('.z-timegrid-hour-cell.is-creatable');
        if (hourCell) {
            const dateStr = hourCell.getAttribute('data-date');
            const hourStr = hourCell.getAttribute('data-hour');
            const wrapper = hourCell.closest('.z-timegrid-wrapper, .z-calendar-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || 'general') : 'general';

            openNewEventModal(dateStr, hourStr, origenTipo);
            return;
        }

        // C) Click en Botón '+' de Celda de Día en Modo Base / Mes
        const btnAddDay = e.target.closest('.z-btn-add-day-event');
        if (btnAddDay) {
            e.preventDefault();
            e.stopPropagation();
            const dateStr = btnAddDay.getAttribute('data-date');
            const wrapper = btnAddDay.closest('.z-calendar-wrapper, .z-timegrid-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || 'general') : 'general';

            openNewEventModal(dateStr, '09:00', origenTipo);
            return;
        }

        // D) Click en cualquier zona de la celda de día en Modo Base (General)
        const dayCell = e.target.closest('.z-calendar-day.is-creatable');
        if (dayCell && !e.target.closest('.z-calendar-card') && !e.target.closest('.z-btn-more-events')) {
            const dateStr = dayCell.getAttribute('data-date');
            const wrapper = dayCell.closest('.z-calendar-wrapper');
            const origenTipo = wrapper ? (wrapper.getAttribute('data-origen-tipo') || 'general') : 'general';

            openNewEventModal(dateStr, '09:00', origenTipo);
            return;
        }
    });

    function isRecurringCard(cardData) {
        if (!cardData) return false;
        return !!(cardData.idparent || cardData.se_repite === 1 || cardData.se_repite === '1' || cardData.se_repite === true);
    }

    function askRecurrenceScope(actionLabel) {
        const i18n = window.AURALIS_I18N || {};
        const msg = actionLabel + '\n\n'
            + '1) ' + (i18n.scopeThis || 'Solo este evento') + '\n'
            + '2) ' + (i18n.scopeFuture || 'Este y los futuros') + '\n\n'
            + (i18n.scopePromptFooter || 'Escribe 1 o 2 (Cancelar para abortar):');
        const answer = window.prompt(msg, '1');
        if (answer === null) {
            return null;
        }
        const normalized = String(answer).trim();
        if (normalized === '2') {
            return 'esta_y_futuras';
        }
        return 'esta';
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

    function toggleRecurrenceFields() {
        if (window.AuralisCalendarModal) {
            window.AuralisCalendarModal.syncRecurrence();
        }
    }

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

    function openNewEventModal(dateStr, hourStr, origenTipo) {
        const modalEl = document.getElementById('auralisNewEventModal');
        if (!modalEl) return;

        const modalTitle = document.getElementById('auralisNewEventModalLabel');
        if (modalTitle) modalTitle.innerHTML = '<i class="fa-solid fa-calendar-plus me-2 text-primary"></i>' + (window.AURALIS_I18N?.newEvent || 'Nuevo evento');

        const fId = document.getElementById('zEvId');
        const fFecha = document.getElementById('zEvFecha');
        const fHora = document.getElementById('zEvHoraInicio');
        const fOrigen = document.getElementById('zEvOrigenTipo');
        const fTitulo = document.getElementById('zEvTitulo');
        const fDesc = document.getElementById('zEvDescripcion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        const btnDelete = document.getElementById('btnDeleteAuralisEvent');

        const fFechaFin = document.getElementById('zEvFechaFin');
        if (fId) fId.value = '';
        if (fFecha) fFecha.value = dateStr || '';
        if (fFechaFin) fFechaFin.value = dateStr || '';
        if (fHora) {
            if (window.AuralisCalendarModal && window.AuralisCalendarModal.rememberStartTime) {
                window.AuralisCalendarModal.rememberStartTime(hourStr || '09:00');
            } else {
                fHora.value = hourStr || '09:00';
            }
        }
        if (fOrigen) fOrigen.value = origenTipo || 'general';
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
        if (fTodoDia) fTodoDia.checked = false;
        if (btnDelete) btnDelete.classList.add('d-none');
        const fEstadoNew = document.getElementById('zEvEstado');
        if (fEstadoNew) fEstadoNew.value = '';

        resetRecurrenceFields();
        toggleTodoDiaInputs();

        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
    }

    function openEditEventModal(cardData) {
        const dayModalEl = document.getElementById('auralisCalendarDayModal');
        if (dayModalEl) {
            const dayModalInstance = bootstrap.Modal.getInstance(dayModalEl);
            if (dayModalInstance) dayModalInstance.hide();
        }

        const modalEl = document.getElementById('auralisNewEventModal');
        if (!modalEl) return;

        const modalTitle = document.getElementById('auralisNewEventModalLabel');
        if (modalTitle) modalTitle.innerHTML = '<i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar Evento';

        const fId = document.getElementById('zEvId');
        const fFecha = document.getElementById('zEvFecha');
        const fFechaFin = document.getElementById('zEvFechaFin');
        const fHora = document.getElementById('zEvHoraInicio');
        const fTitulo = document.getElementById('zEvTitulo');
        const fDesc = document.getElementById('zEvDescripcion');
        const fTodoDia = document.getElementById('zEvTodoDia');
        const btnDelete = document.getElementById('btnDeleteAuralisEvent');

        const fDuracion = document.getElementById('zEvDuracion');

        if (fId) fId.value = cardData.id || '';
        if (fTitulo) fTitulo.value = cardData.title || '';
        if (fFecha && cardData.fecha) {
            let dateVal = String(cardData.fecha);
            if (dateVal.includes('-')) {
                const parts = dateVal.split('-');
                if (parts[0].length === 2 && parts[2].length === 4) {
                    dateVal = `${parts[2]}-${parts[1]}-${parts[0]}`;
                }
            }
            fFecha.value = dateVal;
        }
        if (fFechaFin) {
            let dateFinVal = String(cardData.fecha_fin || cardData.fecha || '');
            if (dateFinVal.includes('-')) {
                const parts = dateFinVal.split('-');
                if (parts[0].length === 2 && parts[2].length === 4) {
                    dateFinVal = `${parts[2]}-${parts[1]}-${parts[0]}`;
                }
            }
            fFechaFin.value = dateFinVal;
        }
        if (fTodoDia) fTodoDia.checked = (cardData.todo_dia === true || cardData.todo_dia === 1 || cardData.todo_dia === '1' || cardData.todo_dia === 'true');
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
        if (fDesc) fDesc.value = cardData.descripcion || '';
        if (btnDelete) btnDelete.classList.remove('d-none');

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

        const fScope = document.getElementById('zEvScope');
        if (fScope) fScope.value = 'esta';
        modalEl.setAttribute('data-is-recurring', isRecurringCard(cardData) ? '1' : '0');

        toggleRecurrenceFields();
        toggleTodoDiaInputs();

        const fAsignado = document.getElementById('zEvAsignado');
        if (fAsignado && (cardData.asignado || cardData.nick)) {
            fAsignado.value = cardData.asignado || cardData.nick;
        }

        // Seleccionar color si existe
        if (cardData.bg_color && window.AuralisCalendarModal) {
            window.AuralisCalendarModal.setColor(cardData.bg_color);
        }

        const bsModal = new bootstrap.Modal(modalEl);
        bsModal.show();
    }

    function updateCsrfToken(newToken) {
        if (!newToken) return;
        document.querySelectorAll('input[name="multireqtoken"]').forEach(input => {
            input.value = newToken;
        });
    }

    // 8. Borrado de Evento por AJAX
    const btnDeleteEvent = document.getElementById('btnDeleteAuralisEvent');
    if (btnDeleteEvent) {
        btnDeleteEvent.addEventListener('click', function () {
            const eventId = document.getElementById('zEvId').value;
            if (!eventId) return;

            const modalEl = document.getElementById('auralisNewEventModal');
            const isRecurring = modalEl && modalEl.getAttribute('data-is-recurring') === '1';
            let scope = 'esta';

            if (isRecurring) {
                const i18n = window.AURALIS_I18N || {};
                const chosen = askRecurrenceScope(i18n.recurrenceDeletePrompt || '¿Cómo deseas eliminar este evento recurrente?');
                if (chosen === null) {
                    return;
                }
                scope = chosen;
            } else if (!confirm((window.AURALIS_I18N && window.AURALIS_I18N.deleteEventConfirm) || '¿Estás seguro de que deseas eliminar este evento?')) {
                return;
            }

            const form = document.getElementById('formAuralisCalendarEvent');
            const params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
            params.set('action', 'delete-event');
            params.set('id', eventId);
            params.set('scope', scope);

            fetch(window.location.pathname + '?action=delete-event', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
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
                console.error('Error al eliminar evento:', err);
                alert('Error al eliminar el evento');
            });
        });
    }

    // 9. Envío de Formulario de Evento por AJAX
    const formEvent = document.getElementById('formAuralisCalendarEvent');
    if (formEvent) {
        formEvent.addEventListener('submit', function (e) {
            e.preventDefault();

            const btnSave = document.getElementById('btnSaveAuralisEvent');
            if (btnSave) btnSave.disabled = true;

            const formData = new FormData(this);
            const eventId = formData.get('id');
            const modalEl = document.getElementById('auralisNewEventModal');
            const isRecurring = modalEl && modalEl.getAttribute('data-is-recurring') === '1';

            let scope = 'esta';
            if (eventId && isRecurring) {
                const i18n = window.AURALIS_I18N || {};
                const chosen = askRecurrenceScope(i18n.recurrenceSavePrompt || '¿Cómo deseas guardar los cambios de este evento recurrente?');
                if (chosen === null) {
                    if (btnSave) btnSave.disabled = false;
                    return;
                }
                scope = chosen;
            }
            formData.set('scope', scope);

            // Fix: los inputs disabled no se incluyen en FormData por estándar HTML.
            // Leemos el estado real del toggle y forzamos todo_dia, hora_inicio y duracion.
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
                    formData.set('fecha_fin', '');
                }
            }

            const seRepiteEl = document.getElementById('zEvSeRepite');
            formData.set('se_repite', (seRepiteEl && seRepiteEl.checked) ? '1' : '0');

            const params = new URLSearchParams(formData);

            fetch(window.location.pathname + '?action=save-event', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
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
                    formEvent.reset();
                    resetRecurrenceFields();
                    loadCalendarAjax();
                } else {
                    alert(data.message || 'Error al guardar el evento');
                }
            })
            .catch(err => {
                console.error('Error al guardar evento:', err);
                alert('Error al guardar el evento');
            })
            .finally(() => {
                if (btnSave) btnSave.disabled = false;
            });
        });
    }

    // Carga inicial del calendario
    loadCalendarAjax();
});

/**
 * Abrir modal de detalles de recibos activando la pestaña correspondiente
 */
window.openReceiptsModal = function (targetTab) {
    const modalEl = document.getElementById('auralisReceiptsDetailModal');
    if (!modalEl) return;

    const tabBtn = document.getElementById('tab-' + targetTab);
    if (tabBtn) {
        const bsTab = new bootstrap.Tab(tabBtn);
        bsTab.show();
    }
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
};

/**
 * Abrir modal de ranking de pagadores (clientes | proveedores)
 */
window.openPayerRankingModal = function (targetTab) {
    const modalEl = document.getElementById('auralisPayerRankingModal');
    if (!modalEl) return;

    const tabKey = targetTab === 'proveedores' ? 'proveedores' : 'clientes';
    const tabBtn = document.getElementById('tab-ranking-' + tabKey);
    if (tabBtn) {
        const bsTab = new bootstrap.Tab(tabBtn);
        bsTab.show();
    }
    const bsModal = new bootstrap.Modal(modalEl);
    bsModal.show();
};

/**
 * Función global para expandir/colapsar listados de recibos de cobros y pagos
 */
window.toggleReceiptsList = function (type, btn) {
    const extras = document.querySelectorAll('.z-receipt-extra-' + type);
    const isHidden = extras.length > 0 && extras[0].classList.contains('d-none');
    extras.forEach(el => {
        if (isHidden) {
            el.classList.remove('d-none');
        } else {
            el.classList.add('d-none');
        }
    });
    if (btn) {
        const count = extras.length;
        if (isHidden) {
            btn.innerHTML = '<i class="fa-solid fa-chevron-up me-1"></i>Mostrar menos';
        } else {
            btn.innerHTML = '<i class="fa-solid fa-chevron-down me-1"></i>Mostrar ' + count + ' más';
        }
    }
};

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Scroll inicial, Drag & Drop y re-layout de columnas para eventos coincidentes en el timegrid 24h de Auralis.
 */

(function (global) {
    'use strict';

    const DEFAULT_SLOT_HEIGHT = 50;
    let draggedEventEl = null;
    let originalParent = null;
    let originalTop = null;
    let originalLeft = null;
    let originalWidth = null;
    let originalDate = null;
    let nowMarkerTimer = null;

    function parseScrollHour(wrapper) {
        const raw = wrapper.getAttribute('data-scroll-hour');
        const hour = parseInt(raw || '7', 10);
        if (Number.isNaN(hour)) {
            return 7;
        }
        return Math.max(0, Math.min(23, hour));
    }

    /**
     * Tramo de horas que pinta la rejilla. auralis-calendar.js lo fija en las agendas de página completa
     * (data-rango-inicio / data-rango-horas); sin ellos es el día entero, como siempre.
     */
    function rangoDe(el) {
        const wrapper = el && el.closest ? el.closest('.z-timegrid-wrapper') : null;
        const inicio = wrapper ? parseInt(wrapper.getAttribute('data-rango-inicio') || '0', 10) || 0 : 0;
        const horas = wrapper ? parseInt(wrapper.getAttribute('data-rango-horas') || '24', 10) || 24 : 24;
        return { inicio: inicio, horas: horas };
    }

    function getSlotHeight(body) {
        const sample = body.querySelector('.z-timegrid-hour-cell');
        if (sample && sample.offsetHeight > 0) {
            return sample.offsetHeight;
        }
        return DEFAULT_SLOT_HEIGHT;
    }

    /**
     * Scroll inicial: menor entre hora laboral configurada y (hora actual − 1).
     */
    function resolveScrollHour(wrapper) {
        const laborHour = parseScrollHour(wrapper);
        const now = new Date();
        const nowMinusOne = Math.max(0, now.getHours() - 1);
        return Math.min(laborHour, nowMinusOne);
    }

    function updateNowMarkerInWrapper(wrapper) {
        if (!wrapper) {
            return;
        }
        const body = wrapper.querySelector('.z-timegrid-body');
        if (!body) {
            return;
        }

        wrapper.querySelectorAll('.z-timegrid-now-line').forEach(function (el) {
            el.remove();
        });

        const todayCol = wrapper.querySelector('.z-timegrid-day-column.is-today');
        if (!todayCol) {
            return;
        }

        const now = new Date();
        const minutes = (now.getHours() * 60) + now.getMinutes();
        const rango = rangoDe(body);
        if (minutes < rango.inicio * 60 || minutes >= (rango.inicio + rango.horas) * 60) {
            return; // fuera del horario visible
        }
        // En % del tramo visible, igual que los eventos (misma columna de referencia): un cambio de
        // --z-slot-h (ajustarHorario, resize) no descoloca la línea aunque no se repinte a tiempo.
        const topPct = (((minutes / 60) - rango.inicio) / rango.horas) * 100;

        const line = document.createElement('div');
        line.className = 'z-timegrid-now-line';
        line.setAttribute('aria-hidden', 'true');
        line.style.top = topPct + '%';
        todayCol.appendChild(line);
    }

    /**
     * Repinta la línea «ahora» en todas las rejillas del ámbito (root o el documento entero).
     * Devuelve cuántas rejillas había, para que el temporizador sepa si sigue haciendo falta.
     */
    function updateNowMarker(root) {
        const scope = root && root.querySelectorAll ? root : document;
        const wrappers = [];
        if (scope.matches && scope.matches('.z-timegrid-wrapper[data-scroll-hour]')) {
            wrappers.push(scope);
        }
        scope.querySelectorAll('.z-timegrid-wrapper[data-scroll-hour]').forEach(function (wrapper) {
            wrappers.push(wrapper);
        });
        wrappers.forEach(function (wrapper) {
            updateNowMarkerInWrapper(wrapper);
        });
        return wrappers.length;
    }

    function ensureNowMarkerTimer() {
        if (nowMarkerTimer !== null) {
            return;
        }
        // Pinta ya, sin esperar al primer tick.
        updateNowMarker();
        nowMarkerTimer = window.setInterval(function () {
            if (!updateNowMarker()) {
                window.clearInterval(nowMarkerTimer);
                nowMarkerTimer = null;
            }
        }, 60000);
    }

    // Al volver a la pestaña: el navegador frena los setInterval en segundo plano.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            updateNowMarker();
        }
    });

    function initScrollInWrapper(wrapper) {
        if (!wrapper || wrapper.dataset.timegridScrollInit === '1') {
            return;
        }

        const body = wrapper.querySelector('.z-timegrid-body');
        if (!body) {
            return;
        }

        const scrollHour = resolveScrollHour(wrapper);
        const slotHeight = getSlotHeight(body);
        body.scrollTop = Math.max(0, scrollHour - rangoDe(body).inicio) * slotHeight;
        wrapper.dataset.timegridScrollInit = '1';
        updateNowMarkerInWrapper(wrapper);
        ensureNowMarkerTimer();
    }

    function getCsrfToken() {
        const input = document.querySelector('input[name="multireqtoken"]');
        return input ? input.value : '';
    }

    function updateCsrfTokens(newToken) {
        if (!newToken) return;
        document.querySelectorAll('input[name="multireqtoken"]').forEach(function (input) {
            input.value = newToken;
        });
    }

    function parseMinutesFromTime(hora) {
        if (!hora || typeof hora !== 'string') {
            return 0;
        }
        const parts = hora.split(':');
        return (parseInt(parts[0] || '0', 10) * 60) + parseInt(parts[1] || '0', 10);
    }

    function minutesFromTopPct(topPct, rango) {
        const pct = parseFloat(String(topPct || '0').replace('%', '')) || 0;
        const r = rango || { inicio: 0, horas: 24 };
        return Math.round((r.inicio * 60) + ((pct / 100) * r.horas * 60));
    }

    function updateEventJsonHour(eventEl, formattedHour) {
        try {
            const raw = eventEl.getAttribute('data-event-json');
            if (!raw) return;
            const data = JSON.parse(raw);
            data.hora_inicio = formattedHour;
            eventEl.setAttribute('data-event-json', JSON.stringify(data));
        } catch (e) {
            /* ignore */
        }
    }

    /**
     * Packing horizontal (lanes) alineado con AuralisCalendarEvent::aplicarLayoutSolapes().
     */
    function relayoutDayColumn(colEl) {
        if (!colEl) return;

        const eventEls = Array.prototype.slice.call(colEl.querySelectorAll('.z-timegrid-event'));
        if (eventEls.length === 0) {
            return;
        }

        if (eventEls.length === 1) {
            eventEls[0].style.left = '0%';
            eventEls[0].style.width = '100%';
            return;
        }

        const items = eventEls.map(function (el, pos) {
            let start = minutesFromTopPct(el.style.top, rangoDe(colEl));
            let duracion = 60;
            try {
                const raw = el.getAttribute('data-event-json');
                if (raw) {
                    const data = JSON.parse(raw);
                    if (data.hora_inicio) {
                        start = parseMinutesFromTime(String(data.hora_inicio));
                    }
                    if (data.duracion) {
                        duracion = Math.max(1, parseInt(data.duracion, 10) || 60);
                    }
                }
            } catch (e) {
                /* fallback top % */
            }
            return {
                el: el,
                pos: pos,
                start: start,
                end: start + duracion,
                duracion: duracion
            };
        });

        items.sort(function (a, b) {
            if (a.start !== b.start) {
                return a.start - b.start;
            }
            return b.duracion - a.duracion;
        });

        const assigned = items.map(function () { return false; });
        const clusters = [];

        for (let i = 0; i < items.length; i++) {
            if (assigned[i]) continue;

            const cluster = [i];
            assigned[i] = true;
            const queue = [i];

            while (queue.length > 0) {
                const cur = queue.shift();
                const curItem = items[cur];
                for (let j = 0; j < items.length; j++) {
                    if (assigned[j]) continue;
                    const other = items[j];
                    if (curItem.start < other.end && other.start < curItem.end) {
                        assigned[j] = true;
                        cluster.push(j);
                        queue.push(j);
                    }
                }
            }
            clusters.push(cluster);
        }

        clusters.forEach(function (cluster) {
            const clusterItems = cluster.map(function (idx) { return items[idx]; });
            clusterItems.sort(function (a, b) {
                if (a.start !== b.start) {
                    return a.start - b.start;
                }
                return b.duracion - a.duracion;
            });

            const colEnds = [];
            const colByEl = [];

            clusterItems.forEach(function (ci) {
                let col = 0;
                while (typeof colEnds[col] !== 'undefined' && colEnds[col] > ci.start) {
                    col++;
                }
                colEnds[col] = ci.end;
                colByEl.push({ el: ci.el, col: col });
            });

            const colCount = Math.max(1, colEnds.length);
            const widthPct = Math.round((100 / colCount) * 100) / 100;

            colByEl.forEach(function (entry) {
                entry.el.style.left = (Math.round(entry.col * widthPct * 100) / 100) + '%';
                entry.el.style.width = widthPct + '%';
            });
        });
    }

    function moveEventAjax(eventId, newDate, newHour, scope, wrapper, onComplete) {
        const token = getCsrfToken();
        if (!eventId || !newDate || !newHour) {
            if (typeof onComplete === 'function') onComplete(false);
            return;
        }

        const formData = new FormData();
        formData.append('action', 'move-event');
        formData.append('id', eventId);
        formData.append('fecha', newDate);
        formData.append('hora_inicio', newHour);
        formData.append('scope', scope || 'esta');
        if (token) {
            formData.append('multireqtoken', token);
        }

        const targetUrl = new URL(window.location.href);
        targetUrl.searchParams.set('action', 'move-event');

        fetch(targetUrl.toString(), {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.newToken) {
                updateCsrfTokens(data.newToken);
            }

            if (data.success) {
                if (typeof onComplete === 'function') onComplete(true, scope);

                if (scope === 'esta_y_futuras') {
                    if (window.AuralisCalendar && typeof window.AuralisCalendar.reload === 'function') {
                        window.AuralisCalendar.reload();
                    } else if (typeof window.loadCalendarAjax === 'function') {
                        window.loadCalendarAjax();
                    } else {
                        window.location.reload();
                    }
                }
            } else {
                alert(data.message || 'Error al mover el evento');
                if (typeof onComplete === 'function') onComplete(false);
            }
        })
        .catch(function (err) {
            console.error('[Auralis Timegrid DragDrop Error]', err);
            alert('Error de conexión al mover el evento');
            if (typeof onComplete === 'function') onComplete(false);
        });
    }

    function isEventRecurring(eventEl) {
        if (!eventEl) return false;
        try {
            const raw = eventEl.getAttribute('data-event-json');
            if (!raw) return false;
            const data = JSON.parse(raw);
            return !!(data.se_repite || data.idparent || data.is_recurring);
        } catch (e) {
            return false;
        }
    }

    function askRecurrenceScope(onSelect) {
        const i18n = window.AURALIS_I18N || {};
        const moveTitle = i18n.recurrenceMovePrompt || 'Mover evento recurrente';
        if (window.AuralisCalendarModal && typeof window.AuralisCalendarModal.askRecurrenceScope === 'function') {
            window.AuralisCalendarModal.askRecurrenceScope(moveTitle, onSelect);
            return;
        }

        const scopeThisLabel = i18n.scopeThis || 'Solo este evento';
        const scopeFutureLabel = i18n.scopeFuture || 'Este y los futuros';
        const footer = i18n.scopePromptFooter || 'Escribe 1 o 2 (Cancelar para abortar):';
        const answer = window.prompt(
            moveTitle + '\n\n1) ' + scopeThisLabel + '\n2) ' + scopeFutureLabel + '\n\n' + footer,
            '1'
        );
        if (typeof onSelect === 'function') {
            onSelect(answer === '2' ? 'esta_y_futuras' : (answer === '1' ? 'esta' : null));
        }
    }

    function updateDragPreview(cell, e) {
        const cellRect = cell.getBoundingClientRect();
        const clickY = e.clientY - cellRect.top;
        const cellHeight = cellRect.height || DEFAULT_SLOT_HEIGHT;
        const ratio = Math.max(0, Math.min(0.99, clickY / cellHeight));

        let minutes = 0;
        if (ratio >= 0.75) {
            minutes = 45;
        } else if (ratio >= 0.50) {
            minutes = 30;
        } else if (ratio >= 0.25) {
            minutes = 15;
        } else {
            minutes = 0;
        }

        const rawHour = cell.getAttribute('data-hour');
        const baseHour = parseInt(cell.getAttribute('data-hour-num') || rawHour || '0', 10);
        const formattedHour = (baseHour < 10 ? '0' + baseHour : baseHour) + ':' + (minutes < 10 ? '0' + minutes : minutes);

        let lineEl = cell.querySelector('.z-timegrid-drag-preview-line');
        if (!lineEl) {
            lineEl = document.createElement('div');
            lineEl.className = 'z-timegrid-drag-preview-line';
            lineEl.innerHTML = '<span class="z-timegrid-drag-preview-badge"></span>';
            cell.appendChild(lineEl);
        }

        const badgeEl = lineEl.querySelector('.z-timegrid-drag-preview-badge');
        if (badgeEl) {
            badgeEl.textContent = formattedHour;
        }

        const topPct = (minutes / 60) * 100;
        lineEl.style.top = topPct + '%';

        return { minutes: minutes, formattedHour: formattedHour, baseHour: baseHour };
    }

    function removeDragPreview(scope) {
        const targetScope = scope || document;
        targetScope.querySelectorAll('.z-timegrid-drag-preview-line').forEach(function (el) {
            el.remove();
        });
    }

    function initDragAndDropInWrapper(wrapper) {
        if (!wrapper || wrapper.dataset.timegridDragInit === '1') {
            return;
        }
        wrapper.dataset.timegridDragInit = '1';

        const events = wrapper.querySelectorAll('.z-timegrid-event');
        events.forEach(function (ev) {
            ev.setAttribute('draggable', 'true');

            ev.addEventListener('dragstart', function (e) {
                draggedEventEl = this;
                originalParent = this.parentElement;
                originalTop = this.style.top;
                originalLeft = this.style.left;
                originalWidth = this.style.width;
                originalDate = originalParent ? originalParent.getAttribute('data-date') : null;

                const eventId = this.getAttribute('data-event-id');
                if (!eventId) {
                    e.preventDefault();
                    return;
                }

                e.dataTransfer.setData('text/plain', eventId);
                e.dataTransfer.effectAllowed = 'move';

                setTimeout(function () {
                    if (draggedEventEl) {
                        draggedEventEl.classList.add('is-dragging');
                        draggedEventEl.style.opacity = '0.4';
                    }
                }, 0);
            });

            ev.addEventListener('dragend', function () {
                this.classList.remove('is-dragging');
                this.style.opacity = '1';

                wrapper.querySelectorAll('.is-drag-over').forEach(function (cell) {
                    cell.classList.remove('is-drag-over');
                });
                removeDragPreview(wrapper);
            });
        });

        const cells = wrapper.querySelectorAll('.z-timegrid-hour-cell');
        cells.forEach(function (cell) {
            cell.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('is-drag-over');
                updateDragPreview(this, e);
            });

            cell.addEventListener('dragenter', function (e) {
                e.preventDefault();
                this.classList.add('is-drag-over');
                updateDragPreview(this, e);
            });

            cell.addEventListener('dragleave', function () {
                this.classList.remove('is-drag-over');
                removeDragPreview(this);
            });

            cell.addEventListener('drop', function (e) {
                e.preventDefault();
                this.classList.remove('is-drag-over');

                const previewInfo = updateDragPreview(this, e);
                removeDragPreview(wrapper);

                const eventEl = draggedEventEl;
                if (!eventEl) return;

                const eventId = eventEl.getAttribute('data-event-id');
                const newDate = this.getAttribute('data-date');
                const formattedHour = previewInfo.formattedHour;
                const baseHour = previewInfo.baseHour;
                const minutes = previewInfo.minutes;

                if (!eventId || !newDate || !formattedHour) return;

                const targetCol = wrapper.querySelector('.z-timegrid-day-column[data-date="' + newDate + '"]');
                if (!targetCol) return;

                const prevParent = eventEl.parentElement;
                const prevTop = eventEl.style.top;
                const prevLeft = eventEl.style.left;
                const prevWidth = eventEl.style.width;
                let prevHourInicio = null;
                try {
                    const rawPrev = eventEl.getAttribute('data-event-json');
                    if (rawPrev) {
                        prevHourInicio = JSON.parse(rawPrev).hora_inicio || null;
                    }
                } catch (err) {
                    prevHourInicio = null;
                }

                const exactHours = baseHour + (minutes / 60);
                const rangoDestino = rangoDe(targetCol);
                const newTopPct = ((exactHours - rangoDestino.inicio) / rangoDestino.horas) * 100;

                targetCol.appendChild(eventEl);
                eventEl.style.transition = 'top 0.2s cubic-bezier(0.16, 1, 0.3, 1)';
                eventEl.style.top = newTopPct + '%';
                updateEventJsonHour(eventEl, formattedHour);
                relayoutDayColumn(targetCol);
                if (prevParent && prevParent !== targetCol) {
                    relayoutDayColumn(prevParent);
                }

                function revertDOM() {
                    if (prevParent) {
                        prevParent.appendChild(eventEl);
                        eventEl.style.top = prevTop;
                        eventEl.style.left = prevLeft;
                        eventEl.style.width = prevWidth;
                        if (prevHourInicio) {
                            updateEventJsonHour(eventEl, prevHourInicio);
                        }
                        relayoutDayColumn(prevParent);
                        if (targetCol !== prevParent) {
                            relayoutDayColumn(targetCol);
                        }
                    }
                }

                function proceedMove(scope) {
                    moveEventAjax(eventId, newDate, formattedHour, scope, wrapper, function (success) {
                        if (!success) {
                            revertDOM();
                        }
                    });
                }

                if (isEventRecurring(eventEl)) {
                    askRecurrenceScope(function (chosenScope) {
                        if (!chosenScope) {
                            revertDOM();
                            return;
                        }
                        proceedMove(chosenScope);
                    });
                } else {
                    proceedMove('esta');
                }
            });
        });
    }

    function initScroll(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.z-timegrid-wrapper[data-scroll-hour]').forEach(function (wrapper) {
            initScrollInWrapper(wrapper);
            initDragAndDropInWrapper(wrapper);
            wrapper.querySelectorAll('.z-timegrid-day-column').forEach(function (col) {
                relayoutDayColumn(col);
            });
        });
    }

    function resetScroll(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.z-timegrid-wrapper[data-scroll-hour]').forEach(function (wrapper) {
            delete wrapper.dataset.timegridScrollInit;
            delete wrapper.dataset.timegridDragInit;
        });
        initScroll(scope);
    }

    global.AuralisTimegrid = {
        initScroll: initScroll,
        resetScroll: resetScroll,
        relayoutDayColumn: relayoutDayColumn,
        updateNowMarker: updateNowMarker
    };

    document.addEventListener('DOMContentLoaded', function () {
        initScroll();
    });
})(typeof window !== 'undefined' ? window : this);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Heartbeat JIT, Novedades + Offcanvas y popups Thunderbird de la campana Auralis.
 */
(function () {
    'use strict';

    var cfg = window.AURALIS_NOTIF || {};
    if (!cfg.enabled) {
        return;
    }

    var canCalendar = cfg.canCalendar !== false;
    var endpoint = cfg.endpoint || 'AuralisNotificationsAjax';
    var token = cfg.token || '';
    var pollSeconds = Math.max(60, Math.min(90, parseInt(cfg.pollSeconds || 75, 10) || 75));
    var lastCount = parseInt(cfg.initialCount || 0, 10) || 0;
    var loaded = false;
    var loading = false;
    var timer = null;
    var alarmTimer = null;
    var shownPopupIds = {};
    var insistence = {};
    var titleTimer = null;
    var originalTitle = null;
    var titleOn = false;
    var justPlayedAt = {};
    var newsHistoryLoaded = false;
    var hasUnreadNews = !!cfg.initialHasUnreadNews;

    var i18n = cfg.i18n || {};

    function t(key, fallback) {
        return i18n[key] || fallback || key;
    }

    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** Misma política que auralis-search.js: bloquea javascript:/data: y caracteres peligrosos. */
    function sanitizeNotifUrl(url) {
        var u = String(url || '').trim();
        if (u === '' || u === '#') {
            return '';
        }
        if (/^(javascript|data|vbscript):/i.test(u)) {
            return '';
        }
        if (/["'<>\\]/.test(u)) {
            return '';
        }
        return u;
    }

    function syncBellUnreadClass() {
        var bellOn = lastCount > 0 || hasUnreadNews;
        qsa('.auralis-notif-bell-wrap').forEach(function (el) {
            if (bellOn) {
                el.classList.add('has-unread');
            } else {
                el.classList.remove('has-unread');
            }
        });
    }

    function revealActivity() {
        var tab = qs('#auralis-notif-tab-act');
        var li = tab ? tab.closest('.nav-item') : null;
        var pane = qs('#auralis-notif-pane-act');
        if (li) {
            li.classList.remove('d-none');
        }
        if (pane) {
            pane.classList.remove('d-none');
        }
    }

    function updateBadges(count) {
        lastCount = count;
        qsa('[data-auralis-notif-badge]').forEach(function (el) {
            el.textContent = count > 99 ? '99+' : String(count);
            if (count > 0) {
                el.classList.remove('is-empty');
                el.removeAttribute('hidden');
            } else {
                el.classList.add('is-empty');
                el.setAttribute('hidden', 'hidden');
            }
        });
        // «Marcar todo como leído» solo si hay actividad pendiente.
        qsa('[data-auralis-notif-mark-all]').forEach(function (el) {
            if (count > 0) {
                el.classList.remove('d-none');
                el.removeAttribute('hidden');
                revealActivity();
            } else {
                el.classList.add('d-none');
                el.setAttribute('hidden', 'hidden');
            }
        });
        // Punto de novedades en campana: solo si no hay badge numérico encima.
        updateNewsDot(hasUnreadNews);
    }

    function updateNewsDot(unread) {
        hasUnreadNews = !!unread;
        qsa('[data-auralis-notif-news-dot]').forEach(function (el) {
            var onBell = el.getAttribute('data-auralis-notif-bell-news') === '1'
                || !!el.closest('.auralis-notif-bell-wrap');
            // En campana: ocultar el punto si ya hay burbuja de Actividad.
            var show = hasUnreadNews && (!onBell || lastCount === 0);
            if (show) {
                el.classList.remove('is-empty');
                el.removeAttribute('hidden');
            } else {
                el.classList.add('is-empty');
                el.setAttribute('hidden', 'hidden');
            }
        });
        syncBellUnreadClass();
    }

    function nextToken(payload) {
        if (payload && payload.newToken) {
            token = payload.newToken;
            qsa('[data-auralis-notif-token]').forEach(function (el) {
                el.value = token;
            });
        }
    }

    function request(action, data, method) {
        method = method || 'GET';
        var url = endpoint + '?action=' + encodeURIComponent(action);
        if (method === 'GET' && data) {
            Object.keys(data).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            });
        }
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (method === 'POST') {
            var body = new FormData();
            body.append('action', action);
            body.append('multireqtoken', token);
            Object.keys(data || {}).forEach(function (k) {
                body.append(k, data[k]);
            });
            opts.body = body;
        }

        return fetch(url, opts).then(function (res) {
            return res.json().then(function (json) {
                nextToken(json);
                return json;
            });
        });
    }

    function scheduleAlarm(seconds) {
        if (alarmTimer) {
            clearTimeout(alarmTimer);
            alarmTimer = null;
        }
        var sec = parseInt(seconds, 10) || 0;
        if (sec <= 0 || document.hidden) {
            return;
        }
        // Cap práctico: horizonte de 2 horas.
        sec = Math.min(7200, sec);
        alarmTimer = setTimeout(function () {
            alarmTimer = null;
            triggerThunderbirdPopup();
        }, sec * 1000);
    }

    function playAlertSound() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return;
            }
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.08;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            setTimeout(function () {
                try {
                    osc.stop();
                    ctx.close();
                } catch (e) {
                    /* ignore */
                }
            }, 220);
        } catch (e) {
            /* silencio si el navegador bloquea audio */
        }
    }

    function popupStillShown(id) {
        return !!qs('.auralis-notif-popup[data-id="' + String(id) + '"]');
    }

    function stopBurst(id) {
        var rec = insistence[String(id)];
        if (!rec || !rec.burst) {
            return;
        }
        clearTimeout(rec.burst.timer);
        try {
            rec.burst.ctx.close();
        } catch (e) {
            /* ignore */
        }
        rec.burst = null;
    }

    function playBurst(id) {
        var rec = insistence[String(id)];
        if (!rec || document.hidden) {
            return;
        }
        stopBurst(id);
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                return;
            }
            var ctx = new Ctx();
            var total = Math.max(1, Math.min(30, rec.seconds || 1)) * 1000;
            var step = 450;
            var beep = 0.22;
            var count = Math.max(1, Math.ceil(total / step));
            var t0 = ctx.currentTime + 0.02;
            var i;
            for (i = 0; i < count; i++) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                var start = t0 + (i * step) / 1000;
                osc.type = 'sine';
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.08, start);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(start);
                osc.stop(start + beep);
            }
            rec.burst = {
                ctx: ctx,
                timer: setTimeout(function () {
                    try {
                        ctx.close();
                    } catch (e) {
                        /* ignore */
                    }
                    if (insistence[String(id)] && insistence[String(id)].burst && insistence[String(id)].burst.ctx === ctx) {
                        insistence[String(id)].burst = null;
                    }
                }, total + 300)
            };
            justPlayedAt[String(id)] = Date.now();
        } catch (e) {
            /* silencio si el navegador bloquea audio */
        }
    }

    function scheduleNext(id) {
        var rec = insistence[String(id)];
        if (!rec) {
            return;
        }
        if (rec.timer) {
            clearTimeout(rec.timer);
        }
        rec.nextAt = Date.now() + rec.minutes * 60000;
        rec.timer = setTimeout(function () {
            onInsistenceDue(id);
        }, rec.minutes * 60000);
    }

    function onInsistenceDue(id) {
        var key = String(id);
        var rec = insistence[key];
        if (!rec) {
            return;
        }
        if (rec.timer) {
            clearTimeout(rec.timer);
            rec.timer = null;
        }
        if (!popupStillShown(key)) {
            stopInsistence(key);
            return;
        }
        if (document.hidden) {
            rec.due = true;
            return;
        }
        rec.due = false;
        playBurst(key);
        scheduleNext(key);
    }

    function stopInsistence(id) {
        var key = String(id);
        var rec = insistence[key];
        if (!rec) {
            syncTitleBlink();
            return;
        }
        if (rec.timer) {
            clearTimeout(rec.timer);
        }
        stopBurst(key);
        delete insistence[key];
        delete justPlayedAt[key];
        syncTitleBlink();
    }

    function armInsistence(id, item) {
        var key = String(id);
        if (!item || !item.insistente) {
            stopInsistence(key);
            return;
        }
        var minutes = Math.max(1, Math.min(60, parseInt(item.intervalo_minutos || 0, 10) || 1));
        var seconds = Math.max(1, Math.min(30, parseInt(item.duracion_segundos || 0, 10) || 1));
        stopInsistence(key);
        insistence[key] = {
            minutes: minutes,
            seconds: seconds,
            timer: null,
            burst: null,
            due: false,
            nextAt: Date.now()
        };
        if (document.hidden) {
            insistence[key].due = true;
        } else {
            playBurst(key);
            scheduleNext(key);
        }
        syncTitleBlink();
    }

    function syncTitleBlink() {
        var ids = Object.keys(insistence);
        if (!ids.length) {
            if (titleTimer) {
                clearInterval(titleTimer);
                titleTimer = null;
            }
            if (originalTitle !== null) {
                document.title = originalTitle;
                originalTitle = null;
            }
            titleOn = false;
            return;
        }
        if (titleTimer) {
            return;
        }
        if (originalTitle === null) {
            originalTitle = document.title;
        }
        var label = t('insistenteTab', 'Aviso pendiente');
        titleTimer = setInterval(function () {
            if (!Object.keys(insistence).length) {
                syncTitleBlink();
                return;
            }
            titleOn = !titleOn;
            document.title = titleOn ? ('\u26A0 ' + label) : originalTitle;
        }, 1000);
    }

    function insistenceJustPlayed() {
        var now = Date.now();
        var id;
        for (id in justPlayedAt) {
            if (Object.prototype.hasOwnProperty.call(justPlayedAt, id) && now - justPlayedAt[id] < 2000) {
                return true;
            }
        }
        return false;
    }

    function triggerThunderbirdPopup() {
        if (document.hidden) {
            return;
        }
        loadAll(true).then(function () {
            if (!insistenceJustPlayed()) {
                playAlertSound();
            }
        });
    }

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function parseFsDateTime(raw) {
        if (!raw) {
            return null;
        }
        var s = String(raw).trim();
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!m) {
            var alt = Date.parse(s);
            return isNaN(alt) ? null : new Date(alt);
        }
        return new Date(
            parseInt(m[1], 10),
            parseInt(m[2], 10) - 1,
            parseInt(m[3], 10),
            parseInt(m[4] || '0', 10),
            parseInt(m[5] || '0', 10),
            parseInt(m[6] || '0', 10)
        );
    }

    function formatClock(d) {
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function formatRelativeDateTime(raw) {
        var d = parseFsDateTime(raw);
        if (!d) {
            return escapeHtml(raw || '');
        }
        var now = new Date();
        var diffMs = now.getTime() - d.getTime();
        var diffMin = Math.floor(diffMs / 60000);
        var timeStr = formatClock(d);

        if (diffMin >= 0 && diffMin < 60) {
            var n = Math.max(1, diffMin || 1);
            return escapeHtml(
                t('relativeMinutes', 'Hace %n% min').replace('%n%', String(n))
            );
        }

        var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var startYday = new Date(startToday.getTime() - 86400000);
        var startDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());

        if (startDay.getTime() === startToday.getTime()) {
            return escapeHtml(
                t('relativeToday', 'Hoy, %time%').replace('%time%', timeStr)
            );
        }
        if (startDay.getTime() === startYday.getTime()) {
            return escapeHtml(
                t('relativeYesterday', 'Ayer, %time%').replace('%time%', timeStr)
            );
        }

        var months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var month = months[d.getMonth()] || '';
        month = month.charAt(0).toUpperCase() + month.slice(1);
        return escapeHtml(d.getDate() + ' ' + month + ' ' + d.getFullYear());
    }

    function renderActivity(items) {
        var box = qs('#auralis-notif-activity-list');
        if (!box) {
            return;
        }
        if (!items || !items.length) {
            box.innerHTML = '<div class="auralis-notif-empty">' + escapeHtml(t('emptyActivity', 'Sin actividad')) + '</div>';
            return;
        }

        box.innerHTML = items.map(function (item) {
            var isRead = !!item.leida;
            var isMissed = !!item.missed;
            var unread = isRead ? ' is-read' : ' is-unread';
            if (isMissed) {
                unread += ' is-missed';
            }
            var safeUrl = sanitizeNotifUrl(item.url);
            var link = safeUrl
                ? '<a class="btn btn-sm btn-outline-secondary" href="' + escapeHtml(safeUrl) + '">' +
                  escapeHtml(t('open', 'Abrir')) +
                  '</a>'
                : '';
            var missedLabel = isMissed
                ? '<span class="auralis-notif-missed-label">' + escapeHtml(t('missed', 'Perdido')) + '</span>'
                : '';
            var readLabel = isRead
                ? '<span class="auralis-notif-read-label">' + escapeHtml(t('read', 'Leído')) + '</span>'
                : '<button type="button" class="btn btn-sm btn-link p-0 auralis-notif-mark" data-id="' +
                  escapeHtml(item.id) +
                  '">' +
                  escapeHtml(t('markRead', 'Marcar leído')) +
                  '</button>';
            return (
                '<article class="auralis-notif-item' + unread + '" data-id="' + escapeHtml(item.id) + '">' +
                '<div class="auralis-notif-date">' + formatRelativeDateTime(item.created_at || '') + '</div>' +
                '<div class="auralis-notif-title">' + escapeHtml(item.titulo || '') + '</div>' +
                '<div class="auralis-notif-body text-muted">' + escapeHtml(item.cuerpo || '') + '</div>' +
                '<div class="auralis-notif-item-actions">' +
                link +
                missedLabel +
                readLabel +
                '</div></article>'
            );
        }).join('');
    }

    function formatNewsDate(isoDate) {
        if (!isoDate) {
            return '';
        }
        var parts = String(isoDate).split('-');
        if (parts.length !== 3) {
            return escapeHtml(isoDate);
        }
        var months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        var m = parseInt(parts[1], 10) - 1;
        var day = parseInt(parts[2], 10);
        var month = months[m] || parts[1];
        return escapeHtml(day + ' ' + month.charAt(0).toUpperCase() + month.slice(1) + ' ' + parts[0]);
    }

    function badgeClass(type) {
        var map = {
            feature: 'is-feature',
            improvement: 'is-improvement',
            legal: 'is-legal',
            tip: 'is-tip'
        };
        return map[type] || 'is-feature';
    }

    function newsCardHtml(entry, mode) {
        mode = mode || 'full';
        var meta = formatNewsDate(entry.date || '');
        if (entry.plugin || entry.version) {
            meta += (meta ? ' · ' : '') + escapeHtml([entry.plugin, entry.version].filter(Boolean).join(' '));
        }
        var badges = (entry.badges || entry.tags || []).map(function (badge) {
            if (typeof badge === 'string') {
                return '<span class="auralis-notif-tag is-feature">' + escapeHtml(badge) + '</span>';
            }
            return (
                '<span class="auralis-notif-tag ' + badgeClass(badge.type) + '">' +
                escapeHtml(badge.label || '') +
                '</span>'
            );
        }).join('');

        var summaryClass = mode === 'digest'
            ? 'auralis-notif-body auralis-notif-summary-clamp text-muted'
            : 'auralis-notif-body';

        var itemsHtml = '';
        if (mode === 'full') {
            var items = (entry.items || []).slice(0, 6).map(function (it) {
                if (typeof it === 'string') {
                    return '<li>' + escapeHtml(it) + '</li>';
                }
                var label = it.label || '';
                var text = it.text || '';
                if (label && text) {
                    return '<li><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(text) + '</li>';
                }
                return '<li>' + escapeHtml(label || text) + '</li>';
            }).join('');
            if (items) {
                itemsHtml =
                    '<div class="fw-semibold small mb-1">' +
                    escapeHtml(t('whatsNew', '¿Qué hay de nuevo?')) +
                    '</div><ul class="auralis-notif-news-list small mb-0">' +
                    items +
                    '</ul>';
            }
        }

        return (
            '<article class="auralis-notif-item auralis-notif-news-card">' +
            '<div class="auralis-notif-date">' + meta + '</div>' +
            (badges ? '<div class="auralis-notif-badges">' + badges + '</div>' : '') +
            '<div class="auralis-notif-title">' + escapeHtml(entry.title || '') + '</div>' +
            '<div class="' + summaryClass + '">' + escapeHtml(entry.summary || '') + '</div>' +
            itemsHtml +
            '</article>'
        );
    }

    function renderChangelog(entries, targetSelector, mode) {
        mode = mode || 'full';
        var box = qs(targetSelector || '#auralis-notif-changelog-list');
        if (!box) {
            return;
        }
        if (!entries || !entries.length) {
            box.innerHTML = '<div class="auralis-notif-empty">' + escapeHtml(t('emptyChangelog', 'Sin novedades')) + '</div>';
            return;
        }
        var list = mode === 'digest' ? entries.slice(0, 1) : entries;
        box.innerHTML = list.map(function (entry) {
            return newsCardHtml(entry, mode);
        }).join('');
    }

    function ensurePopupStack() {
        var stack = qs('#auralis-notif-popup-stack');
        if (stack) {
            return stack;
        }
        stack = document.createElement('div');
        stack.id = 'auralis-notif-popup-stack';
        stack.className = 'auralis-notif-popup-stack';
        document.body.appendChild(stack);
        return stack;
    }

    function renderPopups(popups) {
        popups = popups || [];
        var incoming = {};
        popups.forEach(function (item) {
            incoming[String(item.id)] = true;
        });
        qsa('.auralis-notif-popup').forEach(function (el) {
            var id = String(el.getAttribute('data-id') || '');
            if (!incoming[id]) {
                el.remove();
                delete shownPopupIds[id];
                stopInsistence(id);
            }
        });
        if (!popups.length) {
            syncTitleBlink();
            return;
        }
        var stack = ensurePopupStack();
        popups.forEach(function (item) {
            var id = String(item.id);
            if (shownPopupIds[id]) {
                return;
            }
            shownPopupIds[id] = true;

            var el = document.createElement('div');
            el.className = 'auralis-notif-popup';
            el.dataset.id = id;
            el.innerHTML =
                '<div class="auralis-notif-popup-header">' +
                '<div><div class="auralis-notif-popup-title"><i class="fa-solid fa-bell me-1"></i>' +
                escapeHtml(item.titulo || '') +
                '</div><div class="auralis-notif-popup-meta">' +
                escapeHtml(item.cuerpo || '') +
                '</div></div></div>' +
                '<div class="auralis-notif-popup-actions">' +
                '<div class="btn-group dropup">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">' +
                escapeHtml(t('snooze', 'Posponer')) +
                '</button>' +
                '<ul class="dropdown-menu dropdown-menu-end">' +
                '<li><button type="button" class="dropdown-item auralis-notif-snooze" data-id="' + id + '" data-minutes="5">5 ' +
                escapeHtml(t('minutes', 'min')) +
                '</button></li>' +
                '<li><button type="button" class="dropdown-item auralis-notif-snooze" data-id="' + id + '" data-minutes="15">15 ' +
                escapeHtml(t('minutes', 'min')) +
                '</button></li>' +
                '<li><button type="button" class="dropdown-item auralis-notif-snooze" data-id="' + id + '" data-minutes="60">1 ' +
                escapeHtml(t('hour', 'h')) +
                '</button></li>' +
                '</ul></div>' +
                '<button type="button" class="btn btn-sm btn-primary auralis-notif-dismiss" data-id="' + id + '">' +
                escapeHtml(t('dismiss', 'Descartar')) +
                '</button></div>';
            stack.appendChild(el);
            armInsistence(id, item);
        });
        syncTitleBlink();
    }

    function removePopup(id) {
        qsa('.auralis-notif-popup[data-id="' + id + '"]').forEach(function (el) {
            el.remove();
        });
        delete shownPopupIds[String(id)];
        stopInsistence(id);
    }

    function applyPingPayload(json) {
        if (!json || !json.ok) {
            return;
        }
        if (typeof json.pollSeconds === 'number') {
            pollSeconds = Math.max(60, Math.min(90, json.pollSeconds));
        }
        if (typeof json.has_unread_news === 'boolean') {
            hasUnreadNews = json.has_unread_news;
        }
        if (typeof json.next_alarm_seconds === 'number') {
            scheduleAlarm(json.next_alarm_seconds);
        }
        if (json.push && window.AuralisPwa && typeof window.AuralisPwa.applyPushConfig === 'function') {
            window.AuralisPwa.applyPushConfig(json.push);
        }
        var count = parseInt(json.count || 0, 10) || 0;
        // updateBadges → updateNewsDot (campana + tab Novedades).
        updateBadges(count);
        return count;
    }

    // Presencia por dispositivo: si este navegador tiene suscripción push, ping/load van por POST
    // con push_endpoint (en el cuerpo, nunca en la URL). Sin suscripción, GET como siempre.
    function presenceRequest(action) {
        var pwa = window.AuralisPwa;
        var ep = (pwa && typeof pwa.currentEndpoint === 'function') ? (pwa.currentEndpoint() || '') : '';
        if (!ep) {
            return request(action);
        }
        return request(action, { push_endpoint: ep }, 'POST');
    }

    function loadAll(force) {
        if (loading) {
            return Promise.resolve();
        }
        if (loaded && !force) {
            return Promise.resolve();
        }
        loading = true;
        return presenceRequest('load')
            .then(function (json) {
                if (!json || !json.ok) {
                    return;
                }
                if (typeof json.can_calendar === 'boolean') {
                    canCalendar = json.can_calendar;
                    cfg.canCalendar = canCalendar;
                }
                loaded = true;
                applyPingPayload(json);
                renderActivity(json.items || []);
                renderPopups(json.popups || []);
                if ((json.items && json.items.length) || (json.popups && json.popups.length)) {
                    revealActivity();
                }
                renderChangelog(json.changelog || [], '#auralis-notif-changelog-list', 'digest');
            })
            .finally(function () {
                loading = false;
            });
    }

    function ping() {
        if (document.hidden) {
            return;
        }
        presenceRequest('ping').then(function (json) {
            if (!json || !json.ok) {
                return;
            }
            var prev = lastCount;
            var count = applyPingPayload(json);
            if (typeof count === 'number' && count !== prev) {
                loaded = false;
                if (count > prev) {
                    loadAll(true);
                }
            }
        }).catch(function () {
            /* silencioso: no saturar logs en red inestable */
        });
    }

    function schedule() {
        if (timer) {
            clearInterval(timer);
        }
        timer = setInterval(function () {
            if (!document.hidden) {
                ping();
            }
        }, pollSeconds * 1000);
    }

    function markNewsSeen() {
        if (!hasUnreadNews) {
            updateNewsDot(false);
            return;
        }
        request('mark-news-seen', {}, 'POST').then(function (json) {
            if (json && json.ok) {
                updateNewsDot(false);
            }
        }).catch(function () {
            /* ignore */
        });
    }

    function openNewsHistory() {
        var el = qs('#auralis-notif-news-offcanvas');
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
            return;
        }
        // Cerrar el desplegable de la campana para que no se solape con el Offcanvas.
        closePanel();
        markNewsSeen();
        var oc = bootstrap.Offcanvas.getOrCreateInstance(el);
        oc.show();
        if (newsHistoryLoaded) {
            return;
        }
        request('changelog', { all: '1' }).then(function (json) {
            if (json && json.ok) {
                renderChangelog(json.entries || [], '#auralis-notif-changelog-all', 'full');
                newsHistoryLoaded = true;
            }
        });
    }

    function onMarkRead(id) {
        request('mark-read', { id: id }, 'POST').then(function (json) {
            if (json && json.ok) {
                applyPingPayload(json);
                loaded = false;
                loadAll(true);
                removePopup(id);
            }
        });
    }

    function onDismiss(id) {
        request('dismiss', { id: id }, 'POST').then(function (json) {
            if (json && json.ok) {
                applyPingPayload(json);
                removePopup(id);
                loaded = false;
                loadAll(true);
            }
        });
    }

    function onMarkAll() {
        request('mark-all-read', {}, 'POST').then(function (json) {
            if (json && json.ok) {
                applyPingPayload(Object.assign({}, json, { count: 0 }));
                loaded = false;
                loadAll(true);
                qsa('.auralis-notif-popup').forEach(function (el) {
                    el.remove();
                });
                shownPopupIds = {};
                Object.keys(insistence).forEach(stopInsistence);
            }
        });
    }

    function onSnooze(id, minutes) {
        request('snooze', { id: id, minutes: minutes }, 'POST').then(function (json) {
            if (json && json.ok) {
                applyPingPayload(json);
                removePopup(id);
                loaded = false;
                ping();
            }
        });
    }

    function panelEl() {
        return qs('#auralis-notif-shared-panel');
    }

    function closePanel() {
        var panel = panelEl();
        if (!panel) {
            return;
        }
        panel.classList.remove('is-open');
        panel.setAttribute('hidden', 'hidden');
        qsa('[data-auralis-notif-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function openPanel(anchor) {
        var panel = panelEl();
        if (!panel) {
            return;
        }
        if (panel.parentElement !== document.body) {
            document.body.appendChild(panel);
        }
        panel.removeAttribute('hidden');
        panel.classList.add('is-open');

        var rect = anchor.getBoundingClientRect();
        var width = panel.offsetWidth || 360;
        var left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.right - width));
        var top = Math.min(window.innerHeight - 80, rect.bottom + 8);
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';

        qsa('[data-auralis-notif-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', btn === anchor ? 'true' : 'false');
        });
        loadAll(true);
    }

    function togglePanel(anchor) {
        var panel = panelEl();
        if (!panel) {
            return;
        }
        if (panel.classList.contains('is-open')) {
            closePanel();
        } else {
            openPanel(anchor);
        }
    }

    function bindEvents() {
        document.addEventListener('click', function (ev) {
            var toggle = ev.target.closest('[data-auralis-notif-toggle]');
            if (toggle) {
                ev.preventDefault();
                togglePanel(toggle);
                return;
            }
            var mark = ev.target.closest('.auralis-notif-mark');
            if (mark) {
                ev.preventDefault();
                onMarkRead(mark.getAttribute('data-id'));
                return;
            }
            var dismiss = ev.target.closest('.auralis-notif-dismiss');
            if (dismiss) {
                ev.preventDefault();
                onDismiss(dismiss.getAttribute('data-id'));
                return;
            }
            var snooze = ev.target.closest('.auralis-notif-snooze');
            if (snooze) {
                ev.preventDefault();
                onSnooze(snooze.getAttribute('data-id'), parseInt(snooze.getAttribute('data-minutes') || '5', 10));
                return;
            }
            var markAll = ev.target.closest('[data-auralis-notif-mark-all]');
            if (markAll) {
                ev.preventDefault();
                onMarkAll();
                return;
            }
            var viewAll = ev.target.closest('#zBtnVerTodoChangelog');
            if (viewAll) {
                ev.preventDefault();
                openNewsHistory();
                return;
            }
            var panel = panelEl();
            if (panel && panel.classList.contains('is-open') && !ev.target.closest('#auralis-notif-shared-panel')
                && !ev.target.closest('#auralis-notif-news-offcanvas')) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                closePanel();
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                Object.keys(insistence).forEach(function (id) {
                    var rec = insistence[id];
                    if (!rec || !popupStillShown(id)) {
                        stopInsistence(id);
                        return;
                    }
                    if (rec.due || Date.now() >= rec.nextAt) {
                        rec.due = false;
                        playBurst(id);
                        scheduleNext(id);
                    }
                });
                ping();
            } else {
                Object.keys(insistence).forEach(stopBurst);
                if (alarmTimer) {
                    clearTimeout(alarmTimer);
                    alarmTimer = null;
                }
            }
        });

        var newsTab = qs('#auralis-notif-tab-news');
        if (newsTab) {
            newsTab.addEventListener('shown.bs.tab', function () {
                markNewsSeen();
                syncFooterNewsLink(true);
            });
        }

        var actTab = qs('#auralis-notif-tab-act');
        if (actTab) {
            actTab.addEventListener('shown.bs.tab', function () {
                syncFooterNewsLink(false);
            });
        }
    }

    function syncFooterNewsLink(show) {
        var row = qs('[data-auralis-footer-news]');
        if (!row) {
            return;
        }
        if (show) {
            row.classList.remove('d-none');
        } else {
            row.classList.add('d-none');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Mover Offcanvas al body para evitar clonar IDs en menús responsive [VIS-052].
        var offcanvas = qs('#auralis-notif-news-offcanvas');
        if (offcanvas && offcanvas.parentElement !== document.body) {
            document.body.appendChild(offcanvas);
        }

        updateBadges(lastCount);
        bindEvents();
        schedule();
        ping();
        if (canCalendar && lastCount > 0) {
            loadAll(true);
        } else if (!canCalendar) {
            // Sin agenda: novedades al entrar. Actividad solo si el load trae avisos.
            loadAll(true);
            if (lastCount <= 0) {
                syncFooterNewsLink(true);
            }
        }
    });
})();

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Registro PWA (manifest + SW) y suscripción Web Push (canal separado del in-app).
 */
(function () {
    'use strict';

    var cfg = window.AURALIS_NOTIF || {};
    var pushCfg = cfg.push || null;
    // Endpoint de la suscripción push de ESTE navegador (solo memoria). Lo lee la campana
    // para la presencia por dispositivo; nunca se guarda en storage ni se pinta.
    var currentEndpointCache = '';

    function endpoint() {
        return cfg.endpoint || 'AuralisAgendaNotificationsAjax';
    }

    function token() {
        return cfg.token || '';
    }

    function urlB64ToUint8(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(base64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    }

    function postForm(action, fields) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('multireqtoken', token());
        Object.keys(fields || {}).forEach(function (k) {
            body.set(k, fields[k]);
        });
        return fetch(endpoint(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function injectManifest(url) {
        if (document.querySelector('link[rel="manifest"][data-auralis-pwa]')) {
            return;
        }
        var link = document.createElement('link');
        link.rel = 'manifest';
        link.href = url;
        link.setAttribute('data-auralis-pwa', '1');
        link.crossOrigin = 'use-credentials';
        document.head.appendChild(link);
    }

    function registerSw(swUrl, scope) {
        if (!('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }
        return navigator.serviceWorker.register(swUrl, { scope: scope || './' }).catch(function () {
            return null;
        });
    }

    function subscribePush(reg, vapidPublic) {
        if (!reg || !vapidPublic || !('PushManager' in window)) {
            return Promise.resolve(null);
        }
        return reg.pushManager.getSubscription().then(function (existing) {
            if (existing) {
                return existing;
            }
            currentEndpointCache = '';
            return Notification.requestPermission().then(function (perm) {
                if (perm !== 'granted') {
                    return null;
                }
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8(vapidPublic)
                });
            });
        }).then(function (sub) {
            if (!sub) {
                return null;
            }
            var json = sub.toJSON();
            currentEndpointCache = json.endpoint || '';
            return postForm('push-subscribe', {
                endpoint: json.endpoint || '',
                p256dh: (json.keys && json.keys.p256dh) || '',
                auth: (json.keys && json.keys.auth) || ''
            }).then(function (data) {
                if (data && data.newToken) {
                    cfg.token = data.newToken;
                }
                return sub;
            });
        }).catch(function () {
            return null;
        });
    }

    function boot(push) {
        pushCfg = push || pushCfg;
        if (!pushCfg || !pushCfg.enabled) {
            return;
        }
        if (pushCfg.manifestUrl) {
            injectManifest(pushCfg.manifestUrl);
        }
        if (!pushCfg.https && location.protocol !== 'https:'
            && location.hostname !== 'localhost'
            && location.hostname !== '127.0.0.1') {
            return;
        }
        registerSw(pushCfg.swUrl || 'AuralisPwaSw', pushCfg.scope || './').then(function (reg) {
            if (!reg || !pushCfg.vapidPublic) {
                return;
            }
            return subscribePush(reg, pushCfg.vapidPublic);
        });
    }

    window.AuralisPwa = {
        boot: boot,
        // Barato: sin promesas ni llamadas al PushManager. Vacío si no hay suscripción conocida.
        currentEndpoint: function () {
            return currentEndpointCache;
        },
        applyPushConfig: function (push) {
            if (push) {
                cfg.push = push;
                boot(push);
            }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(cfg.push); });
    } else {
        boot(cfg.push);
    }
})();

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Service Worker PWA Auralis: push + click (sin cache agresivo de PHP).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPwaPaths;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;

class AuralisPwaSw extends Controller
{
    /** @var bool */
    protected $requiresAuth = false;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'auralis-pwa-sw';
        $data['icon'] = 'fa-solid fa-mobile-screen';
        $data['showonmenu'] = false;

        return $data;
    }

    public function run(): void
    {
        parent::run();

        $response = $this->response();
        $response->headers->set('Content-Type', 'application/javascript; charset=UTF-8');
        $response->headers->set('Service-Worker-Allowed', AuralisPwaPaths::scope());
        $response->headers->set('Cache-Control', 'no-cache');
        $response->setContent($this->js());
        $response->send();
    }

    private function js(): string
    {
        $base = AuralisPwaPaths::scope();
        $icon = AuralisPushSettings::pwaIconUrl('192');

        $cabecera = 'const AURALIS_PWA_BASE = ' . json_encode($base) . ";\n"
            . 'const AURALIS_PWA_ICON = ' . json_encode($icon) . ";\n";

        return $cabecera . <<<'JS'
/* Auralis PWA SW: push nativo. No cachea HTML/PHP del ERP (evita versiones pegadas). */
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

function auralisPwaUrl(url) {
    if (!url || url === '#') { return AURALIS_PWA_BASE; }
    // Solo same-origin: rechaza open-redirect a otros hosts.
    if (/^([a-z]+:)?\/\//i.test(url)) {
        try {
            var abs = new URL(url, self.location.origin);
            if (abs.origin !== self.location.origin) { return AURALIS_PWA_BASE; }
            return abs.pathname + abs.search + abs.hash;
        } catch (err) { return AURALIS_PWA_BASE; }
    }
    if (url.charAt(0) !== '/') { return AURALIS_PWA_BASE + url; }
    if (AURALIS_PWA_BASE !== '/' && url.indexOf(AURALIS_PWA_BASE) === 0) { return url; }
    return AURALIS_PWA_BASE + url.replace(/^\//, '');
}

self.addEventListener('push', function (e) {
    var data = {};
    try { data = e.data ? e.data.json() : {}; }
    catch (x) { data = { title: 'Auralis', body: e.data ? e.data.text() : '' }; }

    var title = data.title || 'Auralis';
    var opts = {
        body: data.body || '',
        icon: AURALIS_PWA_ICON,
        badge: AURALIS_PWA_ICON,
        data: { url: data.url || AURALIS_PWA_BASE }
    };

    // Si hay ventana visible del ERP, el canal in-app basta: no duplicar toast.
    e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        var visible = list.some(function (c) { return c.visibilityState === 'visible'; });
        if (visible) { return; }
        return self.registration.showNotification(title, opts);
    }));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var url = auralisPwaUrl(e.notification.data && e.notification.data.url);
    e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            if ('focus' in c) {
                if (c.navigate) { c.navigate(url); }
                return c.focus();
            }
        }
        if (clients.openWindow) { return clients.openWindow(url); }
    }));
});
JS;
    }
}

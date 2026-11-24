<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Sirve el manifest.webmanifest de la PWA Auralis (sin auth: el navegador lo pide al instalar).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPwaPaths;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;

class AuralisPwaManifest extends Controller
{
    /** @var bool */
    protected $requiresAuth = false;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = 'auralis-pwa-manifest';
        $data['icon'] = 'fa-solid fa-mobile-screen';
        $data['showonmenu'] = false;

        return $data;
    }

    public function run(): void
    {
        parent::run();

        $scope = AuralisPwaPaths::scope();
        $name = (string)(Tools::settings('auralis', 'app_nombre', '') ?: 'Auralis');
        $color = (string)Tools::settings('auralis', 'tema_color_hex', '#10B981');
        if ($color === '' || ($color[0] ?? '') !== '#') {
            $color = '#10B981';
        }

        $manifest = [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'start_url' => $scope,
            'scope' => $scope,
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $color,
            'icons' => [
                [
                    'src' => AuralisPushSettings::pwaIconUrl('192'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => AuralisPushSettings::pwaIconUrl('512'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ];

        $response = $this->response();
        $response->headers->set('Content-Type', 'application/manifest+json; charset=UTF-8');
        $response->setContent((string)json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->send();
    }
}

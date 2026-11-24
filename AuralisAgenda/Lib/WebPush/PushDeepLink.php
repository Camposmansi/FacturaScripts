<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Normaliza deep-links del payload push a rutas same-origin del ERP.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

use FacturaScripts\Core\Tools;

final class PushDeepLink
{
    /**
     * Devuelve ruta relativa (path+query+fragment) o vacío si es origen externo.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return '';
        }

        // Ruta relativa del ERP: se acepta tal cual (el SW la ancla al scope).
        if (1 !== preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $url)) {
            return mb_substr($url, 0, 500);
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }

        $site = Tools::siteUrl();
        $siteParts = parse_url($site);
        $siteHost = strtolower((string)($siteParts['host'] ?? ''));
        $urlHost = strtolower((string)$parts['host']);
        if ($siteHost === '' || $urlHost !== $siteHost) {
            return '';
        }

        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $frag = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return mb_substr($path . $query . $frag, 0, 500);
    }
}

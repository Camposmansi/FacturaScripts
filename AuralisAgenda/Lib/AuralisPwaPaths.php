<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Prefijo real de la instalación FS (subcarpeta) para scope PWA/SW.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;

final class AuralisPwaPaths
{
    /**
     * Prefijo sin barra final. '' en raíz; '/facturas' si FS cuelga ahí.
     * Evita el fallo clásico de pedir /Manifest en el dominio cuando FS está en subcarpeta.
     */
    public static function base(): string
    {
        $ruta = (string)Tools::config('route', '');
        if ($ruta === '') {
            $ruta = (string)parse_url(Tools::siteUrl(), PHP_URL_PATH);
        }

        $ruta = rtrim(trim($ruta), '/');
        if ($ruta === '' || $ruta === '/') {
            return '';
        }

        return '/' . ltrim($ruta, '/');
    }

    /** Ámbito del Service Worker y de la PWA. Siempre acaba en barra. */
    public static function scope(): string
    {
        $base = self::base();
        return $base === '' ? '/' : $base . '/';
    }

    /** URL absoluta bajo el prefijo de instalación. */
    public static function url(string $relativo): string
    {
        $relativo = ltrim(str_replace('\\', '/', $relativo), '/');
        return self::base() . '/' . $relativo;
    }

    public static function isHttps(): bool
    {
        $scheme = (string)parse_url(Tools::siteUrl(), PHP_URL_SCHEME);
        if (strtolower($scheme) === 'https') {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https';
    }
}

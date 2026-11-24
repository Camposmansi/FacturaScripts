<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Codec base64url (RFC 4648 §5) para material Web Push / VAPID.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $text = strtr(trim($text), '-_', '+/');
        $pad = strlen($text) % 4;
        if ($pad > 0) {
            $text .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode($text, true);

        return $bin === false ? '' : $bin;
    }
}

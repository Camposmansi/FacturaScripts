<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Capacidad OpenSSL para Web Push (probe cacheado en proceso PHP).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class PushCryptoCapability
{
    /** @var bool|null */
    private static $ready = null;

    public static function isReady(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        if (false === function_exists('openssl_pkey_new')
            || false === function_exists('openssl_pkey_export')
            || false === function_exists('openssl_sign')
            || false === function_exists('hash_hkdf')
            || false === in_array('aes-128-gcm', openssl_get_cipher_methods(), true)
            || false === in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            self::$ready = false;

            return false;
        }

        $probe = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::$ready = (bool)$probe;

        return self::$ready;
    }

    /** @internal tests */
    public static function resetCache(): void
    {
        self::$ready = null;
    }
}

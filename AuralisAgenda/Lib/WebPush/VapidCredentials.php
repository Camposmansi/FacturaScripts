<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Generación de credenciales VAPID vía PEM OpenSSL (RFC 8292).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class VapidCredentials
{
    /**
     * @return array{publicKey: string, privatePem: string}|null
     *   publicKey = punto P-256 sin comprimir en base64url (applicationServerKey)
     *   privatePem = PEM EC PRIVATE KEY (nunca loguear)
     */
    public static function generate(): ?array
    {
        if (false === PushCryptoCapability::isReady()) {
            return null;
        }

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($resource === false) {
            return null;
        }

        $pem = '';
        if (false === openssl_pkey_export($resource, $pem) || $pem === '') {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false
            || empty($details['ec']['x'])
            || empty($details['ec']['y'])) {
            return null;
        }

        $x = str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $uncompressed = "\x04" . $x . $y;

        return [
            'publicKey' => Base64Url::encode($uncompressed),
            'privatePem' => $pem,
        ];
    }

    /** @return \OpenSSLAsymmetricKey|resource|false */
    public static function loadPrivatePem(string $pem)
    {
        $pem = trim($pem);
        if ($pem === '' || false === str_contains($pem, 'PRIVATE KEY')) {
            return false;
        }

        return openssl_pkey_get_private($pem);
    }
}

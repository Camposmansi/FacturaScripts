<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Cifrado de contenido Web Push AES-128-GCM (RFC 8291).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class Aes128GcmEncoder
{
    private const RECORD_SIZE = 4096;
    private const CEK_INFO = "Content-Encoding: aes128gcm\0";
    private const NONCE_INFO = "Content-Encoding: nonce\0";

    /**
     * @return array{body: string, contentEncoding: string}|null
     */
    public static function seal(string $plaintext, string $p256dhB64, string $authB64): ?array
    {
        if (false === PushCryptoCapability::isReady()) {
            return null;
        }

        $receiverPublic = Base64Url::decode($p256dhB64);
        $authSecret = Base64Url::decode($authB64);
        if (strlen($receiverPublic) !== 65 || ($receiverPublic[0] ?? '') !== "\x04" || strlen($authSecret) !== 16) {
            return null;
        }

        $local = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($local === false) {
            return null;
        }

        $localDetails = openssl_pkey_get_details($local);
        if ($localDetails === false || empty($localDetails['ec']['x']) || empty($localDetails['ec']['y'])) {
            return null;
        }

        $localX = str_pad((string)$localDetails['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $localY = str_pad((string)$localDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $localPublic = "\x04" . $localX . $localY;

        $peerPem = self::uncompressedPointToPem($receiverPublic);
        if ($peerPem === null) {
            return null;
        }

        $peerKey = openssl_pkey_get_public($peerPem);
        if ($peerKey === false) {
            return null;
        }

        $shared = openssl_pkey_derive($peerKey, $local);
        if ($shared === false || $shared === '') {
            return null;
        }

        $ikm = hash_hkdf('sha256', $shared, 32, 'WebPush: info' . "\0" . $receiverPublic . $localPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, self::CEK_INFO, $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, self::NONCE_INFO, $salt);

        $padded = $plaintext . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            return null;
        }

        $header = $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($localPublic))
            . $localPublic;

        return [
            'body' => $header . $cipher . $tag,
            'contentEncoding' => 'aes128gcm',
        ];
    }

    private static function uncompressedPointToPem(string $point): ?string
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }

        // SubjectPublicKeyInfo EC P-256 (OID 1.2.840.10045.3.1.7) + BIT STRING del punto.
        $spki = hex2bin(
            '3059301306072a8648ce3d020106082a8648ce3d030107034200'
        );
        if ($spki === false) {
            return null;
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki . $point), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}

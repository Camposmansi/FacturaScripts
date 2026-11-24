<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description JWT ES256 para cabecera Authorization VAPID (RFC 8292).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class VapidJwt
{
    /**
     * @param \OpenSSLAsymmetricKey|resource $privateKey
     */
    public static function issue(string $audience, string $subject, $privateKey, int $ttlHours = 12): ?string
    {
        $audience = rtrim($audience, '/');
        if ($audience === '' || $subject === '') {
            return null;
        }

        $header = Base64Url::encode((string)json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
        $body = Base64Url::encode((string)json_encode([
            'aud' => $audience,
            'exp' => time() + max(1, $ttlHours) * 3600,
            'sub' => $subject,
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header . '.' . $body;
        $derSignature = '';
        if (false === openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $raw = self::ecdsaDerToJose($derSignature);
        if ($raw === null) {
            return null;
        }

        return $signingInput . '.' . Base64Url::encode($raw);
    }

    /** Convierte firma ECDSA DER a r||s (64 bytes) para JWS. */
    private static function ecdsaDerToJose(string $der): ?string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            return null;
        }

        $seqLen = ord($der[$offset++] ?? "\0");
        if ($seqLen & 0x80) {
            $n = $seqLen & 0x7f;
            $offset += $n;
        }

        $readInt = static function () use ($der, &$offset): ?string {
            if (($der[$offset++] ?? '') !== "\x02") {
                return null;
            }
            $len = ord($der[$offset++] ?? "\0");
            $value = substr($der, $offset, $len);
            $offset += $len;
            $value = ltrim($value, "\0");

            return str_pad($value, 32, "\0", STR_PAD_LEFT);
        };

        $r = $readInt();
        $s = $readInt();

        return ($r === null || $s === null) ? null : $r . $s;
    }
}

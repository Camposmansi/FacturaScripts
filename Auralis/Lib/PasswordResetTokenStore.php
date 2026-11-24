<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Token de recuperación cifrado (AES-256-GCM) y rate-limit por IP.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;

final class PasswordResetTokenStore
{
    public const QUERY_PARAM = 'ref';

    private const CACHE_PREFIX = 'auralis_pwd_reset_';

    private const RATE_PREFIX = 'auralis_pwd_rl_';

    public static function forget(string $ref): void
    {
        $ref = self::normalizeRef($ref);
        if ($ref === null) {
            return;
        }

        Cache::delete(self::CACHE_PREFIX . $ref);
    }

    public static function retrieve(string $ref): ?string
    {
        $ref = self::normalizeRef($ref);
        if ($ref === null) {
            return null;
        }

        $payload = Cache::get(self::CACHE_PREFIX . $ref);
        if (false === is_string($payload) || $payload === '') {
            return null;
        }

        return self::decrypt($payload);
    }

    public static function store(string $tokenRaw): string
    {
        $encrypted = self::encrypt($tokenRaw);
        if ($encrypted === null) {
            Tools::log()->error('auralis-reset-token-encrypt-failed');

            return '';
        }

        $ref = bin2hex(random_bytes(16));
        Cache::set(self::CACHE_PREFIX . $ref, $encrypted);

        return $ref;
    }

    /**
     * Rate-limit de solicitudes (incluye correos inexistentes). true = bloquear.
     */
    public static function tooManyAttempts(string $ip, int $limit = 10, int $window = 900): bool
    {
        $ipClean = trim(Tools::noHtml($ip));
        if ($ipClean === '') {
            $ipClean = 'unknown';
        }

        $key = self::RATE_PREFIX . hash('sha256', $ipClean);
        $data = Cache::get($key);
        $now = time();
        $window = max(1, $window);
        $limit = max(1, $limit);

        if (false === is_array($data)
            || false === isset($data['t'], $data['n'])
            || (int) $data['t'] < ($now - $window)
        ) {
            $data = ['t' => $now, 'n' => 0];
        }

        if ((int) $data['n'] >= $limit) {
            return true;
        }

        $data['n'] = (int) $data['n'] + 1;
        Cache::set($key, $data);

        return false;
    }

    private static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        // Longitud mínima en bytes binarios: IV (12) + Tag (16) + datos cifrados (mínimo 1 byte) = 29 bytes
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === is_string($plain) || $plain === '') {
            return null;
        }

        return $plain;
    }

    private static function encrypt(string $plain): ?string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || $tag === '') {
            return null;
        }

        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Clave de la instalación (FS_APP_KEY o derivada). Sin compatibilidad con la v2:
     * los tokens en caché caducan en 15 minutos.
     */
    private static function encryptionKey(): string
    {
        return AuralisSecurity::claveInstalacion('auralis-pwd-reset-v3');
    }

    private static function normalizeRef(string $ref): ?string
    {
        $ref = trim(Tools::noHtml($ref));
        if ($ref === '' || preg_match('/^[a-f0-9]{32}$/', $ref) !== 1) {
            return null;
        }

        return $ref;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Política SSRF para endpoints Web Push (allowlist + redes privadas).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class PushEndpointPolicy
{
    public const MAX_ENDPOINT_LENGTH = 2048;

    /** Hosts conocidos de push (coincidencia exacta o sufijo). */
    private const KNOWN_SUFFIXES = [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'web.push.apple.com',
        'notify.windows.com',
        'push.apple.com',
    ];

    public static function assertSendable(string $endpoint): bool
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '' || mb_strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            return false;
        }

        $parts = parse_url($endpoint);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $host = trim($host, '[]');

        if ($scheme === 'http') {
            // Solo hostname "localhost" (no 127.0.0.1) para mocks locales; evita SSRF a /admin o /api.
            if ($host !== 'localhost') {
                return false;
            }

            $path = strtolower((string)($parts['path'] ?? ''));
            return !str_starts_with($path, '/admin') && !str_starts_with($path, '/api');
        }

        if ($scheme !== 'https') {
            return false;
        }

        // Solo permitir proveedores push estándar de navegadores o localhost para pruebas;
        // rechazar hosts arbitrarios desconocidos para evitar SSRF y DNS rebinding (TOCTOU).
        return self::isKnownPushHost($host);
    }

    public static function isValidSubscriptionKey(string $p256dh, string $auth): bool
    {
        $pub = Base64Url::decode($p256dh);
        $secret = Base64Url::decode($auth);

        return strlen($pub) === 65
            && ($pub[0] ?? '') === "\x04"
            && strlen($secret) === 16
            && mb_strlen($p256dh) <= 200
            && mb_strlen($auth) <= 64;
    }

    private static function isKnownPushHost(string $host): bool
    {
        foreach (self::KNOWN_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function resolvesToBlockedAddress(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isBlockedIp($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records === false || $records === []) {
            $ipv4 = gethostbyname($host);
            if ($ipv4 === $host) {
                return true;
            }

            return self::isBlockedIp($ipv4);
        }

        foreach ($records as $row) {
            if (!empty($row['ip']) && self::isBlockedIp((string)$row['ip'])) {
                return true;
            }
            if (!empty($row['ipv6']) && self::isBlockedIp((string)$row['ipv6'])) {
                return true;
            }
        }

        return false;
    }

    private static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            // 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 0.0.0.0/8
            $ranges = [
                [ip2long('127.0.0.0'), ip2long('127.255.255.255')],
                [ip2long('10.0.0.0'), ip2long('10.255.255.255')],
                [ip2long('172.16.0.0'), ip2long('172.31.255.255')],
                [ip2long('192.168.0.0'), ip2long('192.168.255.255')],
                [ip2long('169.254.0.0'), ip2long('169.254.255.255')],
                [ip2long('0.0.0.0'), ip2long('0.255.255.255')],
            ];
            foreach ($ranges as [$from, $to]) {
                if ($from !== false && $to !== false && $long >= $from && $long <= $to) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);
            if ($normalized === '::1' || str_starts_with($normalized, 'fe80:') || str_starts_with($normalized, 'fc')
                || str_starts_with($normalized, 'fd')) {
                return true;
            }
        }

        return true;
    }
}

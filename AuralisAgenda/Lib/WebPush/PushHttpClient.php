<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Cliente HTTP POST para entrega Web Push (HTTPS, timeouts cortos).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

final class PushHttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string}
     */
    public static function post(string $url, string $body, array $headers, int $timeoutSeconds = 5): array
    {
        if (false === PushEndpointPolicy::assertSendable($url)) {
            return ['ok' => false, 'status' => 0, 'body' => 'endpoint_policy_rejected'];
        }

        if (false === function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => 'curl_missing'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'curl_init_failed'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => min(3, max(1, $timeoutSeconds)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return ['ok' => false, 'status' => $status, 'body' => 'curl_error'];
        }

        $ok = $status >= 200 && $status < 300;

        return ['ok' => $ok, 'status' => $status, 'body' => (string)$responseBody];
    }
}

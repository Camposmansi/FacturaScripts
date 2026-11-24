<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Fachada de envío Web Push clean-room (RFC 8291/8292 + OpenSSL).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisPushSubscription;
use FacturaScripts\Plugins\AuralisAgenda\Lib\NotificationDeliveryPolicy;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use Throwable;

final class PushSender
{
    public const TTL = 86400;

    public const HTTP_TIMEOUT = 5;

    /** @var string */
    private $vapidPublic = '';

    /** @var string PEM descifrado en memoria (nunca loguear) */
    private $vapidPrivatePem = '';

    /** @var \OpenSSLAsymmetricKey|resource|null */
    private $privateKey = null;

    public function __construct(?string $vapidPublic = null, ?string $vapidPrivatePem = null)
    {
        $this->vapidPublic = $vapidPublic ?? AuralisPushSettings::vapidPublic();
        $this->vapidPrivatePem = $vapidPrivatePem ?? AuralisPushSettings::vapidPrivatePem();
        if ($this->vapidPrivatePem !== '') {
            $key = VapidCredentials::loadPrivatePem($this->vapidPrivatePem);
            $this->privateKey = $key === false ? null : $key;
        }
    }

    public function isReady(): bool
    {
        return PushCryptoCapability::isReady()
            && $this->vapidPublic !== ''
            && $this->privateKey !== null;
    }

    /**
     * Envía push a las suscripciones del nick. Best-effort: no lanza.
     *
     * destinos: suscripciones a las que llegó a salir la petición HTTP (no incluye las omitidas).
     * codigo: primer código HTTP reintentable (429/5xx); si no hubo, el último fallo; 0 sin fallos.
     * host: host del servicio de ese código. Nunca el endpoint completo.
     * omitidas: suscripciones saltadas por presencia (solo con $omitirPresentes). Todas omitidas:
     * omitidas > 0, destinos = 0 y entregas = 0.
     *
     * $omitirPresentes: salta cada suscripción cuyo navegador hizo ping hace ≤ skipIfOnlineSeconds
     * (last_ping_at). El resto del mismo nick sí recibe. El push de prueba no lo usa.
     *
     * @return array{entregas: int, reintentable: bool, borradas: int, destinos: int, codigo: int, host: string, omitidas: int}
     */
    public function enviarAUsuario(
        string $nick,
        string $titulo,
        string $mensaje,
        string $url = '',
        bool $omitirPresentes = false,
        ?int $nowTs = null
    ): array {
        $vacio = [
            'entregas' => 0,
            'reintentable' => false,
            'borradas' => 0,
            'destinos' => 0,
            'codigo' => 0,
            'host' => '',
            'omitidas' => 0,
        ];
        $nick = trim($nick);
        if ($nick === '' || false === AuralisPushSettings::isPushEnabled() || false === $this->isReady()) {
            return $vacio;
        }

        $subs = AuralisPushSubscription::all(
            [Where::eq('nick', $nick)],
            ['created_at' => 'DESC'],
            0,
            AuralisPushSettings::maxSubsPerUser()
        );
        if ($subs === []) {
            return $vacio;
        }

        $safeUrl = PushDeepLink::normalize($url);
        $payload = (string)json_encode([
            'title' => $titulo,
            'body' => $mensaje,
            'url' => $safeUrl,
        ], JSON_UNESCAPED_UNICODE);

        $entregas = 0;
        $reintentable = false;
        $borradas = 0;
        $destinos = 0;
        $codigo = 0;
        $host = '';
        $omitidas = 0;
        $nowTs = $nowTs ?? time();
        foreach ($subs as $sub) {
            // Ese navegador tiene la campana viva: el in-app basta en él. Los demás siguen.
            if ($omitirPresentes && AuralisPushSettings::isSubscriptionPresent($sub, $nowTs)) {
                ++$omitidas;
                continue;
            }

            $resultado = $this->enviarASuscripcion($sub, $payload);
            $entregas += $resultado['entregas'];
            $borradas += $resultado['borradas'];
            $destinos += $resultado['destinos'];
            if ($resultado['reintentable'] && false === $reintentable) {
                $reintentable = true;
                $codigo = $resultado['codigo'];
                $host = $resultado['host'];
            } elseif (false === $reintentable && $resultado['codigo'] > 0 && $resultado['entregas'] === 0) {
                $codigo = $resultado['codigo'];
                $host = $resultado['host'];
            }
        }

        return [
            'entregas' => $entregas,
            'reintentable' => $reintentable,
            'borradas' => $borradas,
            'destinos' => $destinos,
            'codigo' => $codigo,
            'host' => $host,
            'omitidas' => $omitidas,
        ];
    }

    /** Atajo estático (JIT / callers simples). */
    public static function push(string $nick, string $titulo, string $mensaje, string $url = ''): int
    {
        return (new self())->enviarAUsuario($nick, $titulo, $mensaje, $url)['entregas'];
    }

    /**
     * @return array{entregas: int, reintentable: bool, borradas: int, destinos: int, codigo: int, host: string}
     */
    private function enviarASuscripcion(AuralisPushSubscription $sub, string $payload): array
    {
        $nada = [
            'entregas' => 0,
            'reintentable' => false,
            'borradas' => 0,
            'destinos' => 0,
            'codigo' => 0,
            'host' => '',
        ];
        $endpoint = (string)$sub->endpoint;
        if (false === PushEndpointPolicy::assertSendable($endpoint)) {
            return $nada;
        }

        $sealed = Aes128GcmEncoder::seal($payload, (string)$sub->p256dh, (string)$sub->auth);
        if ($sealed === null) {
            return $nada;
        }

        $partes = parse_url($endpoint);
        $audiencia = ($partes['scheme'] ?? 'https') . '://' . ($partes['host'] ?? '');
        $token = VapidJwt::issue($audiencia, self::sujeto(), $this->privateKey);
        if ($token === null) {
            return $nada;
        }

        $result = PushHttpClient::post(
            $endpoint,
            $sealed['body'],
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => $sealed['contentEncoding'],
                'Content-Length' => (string)strlen($sealed['body']),
                'TTL' => (string)self::TTL,
                'Urgency' => 'normal',
                'Authorization' => 'vapid t=' . $token . ', k=' . $this->vapidPublic,
            ],
            self::HTTP_TIMEOUT
        );

        $codigo = (int)$result['status'];
        $host = (string)($partes['host'] ?? '');
        if ($codigo === 404 || $codigo === 410) {
            $sub->delete();

            return [
                'entregas' => 0,
                'reintentable' => false,
                'borradas' => 1,
                'destinos' => 1,
                'codigo' => $codigo,
                'host' => $host,
            ];
        }

        if ($result['ok']) {
            self::anotarExito($sub);

            return [
                'entregas' => 1,
                'reintentable' => false,
                'borradas' => 0,
                'destinos' => 1,
                'codigo' => $codigo,
                'host' => $host,
            ];
        }

        if ($codigo > 0) {
            Tools::log('Auralis')->warning('auralis-push-http-' . $codigo . '-' . ($host !== '' ? $host : '?'));
        }

        return [
            'entregas' => 0,
            'reintentable' => NotificationDeliveryPolicy::httpReintentable($codigo),
            'borradas' => 0,
            'destinos' => 1,
            'codigo' => $codigo,
            'host' => $host,
        ];
    }

    /**
     * Anota el último 2xx de esa suscripción (solo la columna last_success_at, sin pasar por test()).
     * Best-effort: un fallo de escritura no debe tirar el envío. No loguea el endpoint.
     */
    private static function anotarExito(AuralisPushSubscription $sub): void
    {
        $id = (int)$sub->id();
        if ($id <= 0) {
            return;
        }

        $ahora = Tools::dateTime();
        try {
            DbQuery::table(AuralisPushSubscription::tableName())
                ->whereEq(AuralisPushSubscription::primaryColumn(), $id)
                ->update(['last_success_at' => $ahora]);
            $sub->last_success_at = $ahora;
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('auralis-push-last-success-fail');
        }
    }

    private static function sujeto(): string
    {
        $email = (string)Tools::settings('email', 'email', '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'mailto:' . $email;
        }

        return Tools::siteUrl();
    }
}

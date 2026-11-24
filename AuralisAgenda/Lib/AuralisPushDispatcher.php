<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Despacho de push pendientes con batch, presencia, reintento diferido y push_estado.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisNotification;
use FacturaScripts\Dinamic\Model\AuralisPushSubscription;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushSender;

final class AuralisPushDispatcher
{
    /**
     * Envía push de avisos pendientes sin saturar el Cron.
     * Solo un 2xx deja el aviso como enviado. En línea o sin suscripción se aplaza
     * con las esperas de NotificationDeliveryPolicy y el quinto intento cierra como agotado.
     *
     * @return array{
     *     sent: int,
     *     skipped_online: int,
     *     skipped_disabled: int,
     *     examined: int,
     *     deferred_online: int,
     *     deferred_no_sub: int,
     *     deferred_retry: int,
     *     closed_sin_permiso: int,
     *     closed_tipo_sin_push: int,
     *     closed_missed: int,
     *     closed_agotado: int,
     *     closed_caducado: int
     * }
     */
    public static function dispatchPending(?int $batchLimit = null): array
    {
        $stats = self::emptyStats();

        if (false === AuralisPushSettings::isPushEnabled()) {
            $stats['skipped_disabled'] = 1;

            return $stats;
        }

        if (false === AuralisNotificationManager::tableReady()
            && false === AuralisNotificationManager::ensureSchema()) {
            return $stats;
        }

        // Asegura tabla de suscripciones (DbUpdater vía ModelClass).
        try {
            new AuralisPushSubscription();
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-push-schema: ' . $e->getMessage());

            return $stats;
        }

        $limit = $batchLimit ?? AuralisPushSettings::batchLimit();
        $limit = max(1, min(200, $limit));
        $now = Tools::dateTime();
        $nowTs = time();
        $horasMax = AuralisPushSettings::maxRetryHours();

        // Enviados y cerrados llevan push_sent_at: el primer filtro ya los deja fuera.
        // Null en push_estado es una fila anterior a la columna y se trata como pendiente.
        $candidatos = AuralisNotification::all(
            [
                Where::isNull('push_sent_at'),
                Where::eq('leida', false),
                Where::eq('missed', false),
                Where::sub([
                    Where::isNull('push_estado'),
                    Where::orIn('push_estado', NotificationDeliveryPolicy::estadosPushCandidatos()),
                ]),
                Where::sub([
                    Where::isNull('push_retry_at'),
                    Where::orLte('push_retry_at', $now),
                ]),
            ],
            ['id' => 'ASC'],
            0,
            $limit
        );

        // Un solo PushSender / claves descifradas por tick de Cron.
        $sender = new PushSender();

        foreach ($candidatos as $row) {
            ++$stats['examined'];

            if ($row->isMissed()) {
                self::contarCierre($stats, self::markPushClosed($row, AuralisNotification::PUSH_MISSED));
                continue;
            }

            // Caducidad antes de cualquier intento: un aviso viejo no se manda tarde.
            if (NotificationDeliveryPolicy::pushCaducado((string)($row->created_at ?? ''), $horasMax, $nowTs)) {
                self::contarCierre($stats, self::markPushClosed($row, AuralisNotification::PUSH_CADUCADO));
                continue;
            }

            if ($row->isSnoozed($now)) {
                continue;
            }

            if ((int)($row->push_attempts ?? 0) >= NotificationDeliveryPolicy::MAX_INTENTOS_PUSH) {
                self::contarCierre($stats, self::markPushClosed($row, AuralisNotification::PUSH_AGOTADO));
                continue;
            }

            $tipo = NotificationTypeRegistry::get((string)$row->tipo);
            if ($tipo === null || false === NotificationDeliveryPolicy::admitePush($tipo)) {
                self::contarCierre($stats, self::markPushClosed($row, AuralisNotification::PUSH_TIPO_SIN_PUSH));
                continue;
            }

            $nick = trim((string)$row->nick);
            if ($nick === '' || false === AuralisNotificationManager::destinatarioPuedeRecibir($nick, $tipo->permiso())) {
                self::contarCierre($stats, self::markPushClosed($row, AuralisNotification::PUSH_SIN_PERMISO));
                continue;
            }

            // Presencia por dispositivo: PushSender salta cada suscripción cuyo navegador tiene
            // la campana viva y envía al resto. La presencia por nick (settings) ya no decide:
            // con suscripciones manda el filtro por sub; sin ellas no hay a quién enviar.
            $texto = NotificationDeliveryPolicy::textoPush($tipo);
            $url = (string)(AuralisNotificationManager::toArray($row)['url'] ?? '');
            $resultado = $sender->enviarAUsuario($nick, $texto['titulo'], $texto['cuerpo'], $url, true, $nowTs);
            if ((int)($resultado['omitidas'] ?? 0) > 0) {
                // Avisos con al menos un aparato saltado por presencia (no cuenta suscripciones).
                ++$stats['skipped_online'];
            }

            $estado = self::aplicarResultado($row, $resultado);
            if ($estado === AuralisNotification::PUSH_ENVIADO) {
                ++$stats['sent'];
            } else {
                self::contarAplazamiento($stats, $estado);
            }
        }

        return $stats;
    }

    /**
     * Tras crear una notificación nueva. Cierra lo que nunca tendrá push (missed, tipo sin push)
     * y, si el usuario está en línea, aplaza como pendiente_online: nunca lo da por enviado.
     * Si está ausente, se deja pendiente para su despacho asíncrono seguro vía Cron (DispatchCalendarPushNotifications),
     * evitando bloquear el hilo de la petición HTTP web con llamadas cURL externas síncronas.
     */
    public static function tryPushForNotification(AuralisNotification $row): void
    {
        if (!empty($row->push_sent_at)) {
            return;
        }

        // Missed reminders: solo Actividad (sin push de catch-up).
        if ($row->isMissed()) {
            self::markPushClosed($row, AuralisNotification::PUSH_MISSED);
            return;
        }

        $tipo = NotificationTypeRegistry::get((string)$row->tipo);
        if (false === NotificationDeliveryPolicy::admitePush($tipo)) {
            self::markPushClosed($row, AuralisNotification::PUSH_TIPO_SIN_PUSH);
            return;
        }

        if (false === AuralisPushSettings::isPushEnabled()) {
            return;
        }

        $nick = trim((string)$row->nick);
        if ($nick === '') {
            return;
        }

        // Todos sus aparatos con la campana viva: el in-app basta ahora, pero el push queda
        // pendiente por si cierra el ERP. Basta un aparato ausente (o ninguna suscripción) para
        // dejarlo al Cron, que omite los presentes y envía al resto (o anota sin_suscripcion).
        if (AuralisPushSettings::allSubscriptionsPresent($nick)) {
            self::scheduleSkipRetry($row, AuralisNotification::PUSH_PENDIENTE_ONLINE);
        }

        // Si no, se deja push_sent_at = null para que el CronJob
        // DispatchCalendarPushNotifications lo envíe de forma asíncrona con lote y timeout seguros.
    }

    /**
     * Hubo al menos una entrega 2xx. Es el único camino a enviado.
     * B2 anota last_success_at en cada suscripción con 2xx dentro de PushSender.
     */
    public static function markPushDelivered(AuralisNotification $row): bool
    {
        $row->push_estado = AuralisNotification::PUSH_ENVIADO;
        $row->push_sent_at = Tools::dateTime();
        $row->push_retry_at = null;

        return $row->save();
    }

    /**
     * Cierre sin entrega. push_sent_at hace de candado para lecturas antiguas que solo miran esa fecha.
     * Un estado que no sea de cierre se guarda como agotado.
     *
     * @return string estado guardado
     */
    public static function markPushClosed(AuralisNotification $row, string $estado): string
    {
        if (false === NotificationDeliveryPolicy::esEstadoPushCierre($estado)) {
            $estado = AuralisNotification::PUSH_AGOTADO;
        }

        $row->push_estado = $estado;
        $row->push_sent_at = Tools::dateTime();
        $row->push_retry_at = null;
        $row->save();

        // Solo se trazan los cierres que pierden un push que sí tocaba.
        if ($estado === AuralisNotification::PUSH_AGOTADO || $estado === AuralisNotification::PUSH_CADUCADO) {
            self::log('warning', $row, '');
        }

        return $estado;
    }

    /**
     * Aplaza sin HTTP o tras un fallo transitorio: suma un intento, programa push_retry_at
     * y deja push_sent_at a null. El quinto intento cierra como agotado.
     *
     * @return string estado guardado
     */
    public static function scheduleSkipRetry(AuralisNotification $row, string $estado, string $host = ''): string
    {
        $decision = NotificationDeliveryPolicy::decidirAplazamiento($estado, (int)($row->push_attempts ?? 0));
        $row->push_attempts = $decision['intentos'];
        if ($decision['cerrar']) {
            return self::markPushClosed($row, $decision['estado']);
        }

        $row->push_estado = $decision['estado'];
        $row->push_retry_at = self::retryAt($decision['espera_minutos']);
        $row->push_sent_at = null;
        $row->save();
        self::log('notice', $row, $host);

        return $decision['estado'];
    }

    /**
     * Traduce el resultado de PushSender a un estado y lo guarda.
     *
     * Orden: 2xx → enviado; 429/5xx → reintento; algún aparato omitido por presencia (y ninguno
     * entregó) → pendiente_online; 0 destinos o todos borrados → sin_suscripcion; red → reintento;
     * 4xx permanente → cierre.
     *
     * @param array{entregas?: int, reintentable?: bool, borradas?: int, destinos?: int, codigo?: int, host?: string, omitidas?: int} $resultado
     * @return string estado guardado
     */
    private static function aplicarResultado(AuralisNotification $row, array $resultado): string
    {
        $entregas = (int)($resultado['entregas'] ?? 0);
        $destinos = (int)($resultado['destinos'] ?? 0);
        $borradas = (int)($resultado['borradas'] ?? 0);
        $omitidas = (int)($resultado['omitidas'] ?? 0);
        $codigo = (int)($resultado['codigo'] ?? 0);
        $host = (string)($resultado['host'] ?? '');

        if ($entregas > 0) {
            self::markPushDelivered($row);

            return AuralisNotification::PUSH_ENVIADO;
        }

        if (!empty($resultado['reintentable'])) {
            return self::programarReintento($row, $codigo, $host);
        }

        // Todas omitidas por presencia, o las no presentes fallaron sin remedio (404/410, 4xx, red):
        // queda un aparato vivo del nick, así que se aplaza en vez de cerrar.
        if ($omitidas > 0) {
            return self::scheduleSkipRetry($row, AuralisNotification::PUSH_PENDIENTE_ONLINE, $host);
        }

        // Sin suscripciones, sin destino válido o todas respondieron 404/410 (ya borradas).
        if ($destinos === 0 || $borradas >= $destinos) {
            return self::scheduleSkipRetry($row, AuralisNotification::PUSH_SIN_SUSCRIPCION);
        }

        // Sin código HTTP: red o timeout. Transitorio, con el mismo techo de intentos.
        if ($codigo === 0) {
            return self::scheduleSkipRetry($row, AuralisNotification::PUSH_REINTENTO, $host);
        }

        // 4xx permanente (400, 403, 413…): decidirReintento lo cierra.
        return self::programarReintento($row, $codigo, $host);
    }

    /**
     * Un 429 o 5xx no cierra el aviso; un código permanente sí. Al quinto fallo, agotado.
     *
     * @return string estado guardado
     */
    private static function programarReintento(AuralisNotification $row, int $codigo, string $host = ''): string
    {
        $decision = NotificationDeliveryPolicy::decidirReintento($codigo, (int)($row->push_attempts ?? 0));
        $row->push_attempts = $decision['intentos'];
        if ($decision['marcar_enviado']) {
            Tools::log('Auralis')->warning(
                'auralis-push-cierre-http-' . $codigo . ' id=' . (int)$row->id
                . ' host=' . ($host !== '' ? $host : '-')
            );

            return self::markPushClosed($row, AuralisNotification::PUSH_AGOTADO);
        }

        $row->push_estado = AuralisNotification::PUSH_REINTENTO;
        $row->push_retry_at = self::retryAt($decision['espera_minutos']);
        $row->push_sent_at = null;
        $row->save();
        Tools::log('Auralis')->warning(
            'auralis-push-reintento-' . $decision['intentos'] . '-espera-' . $decision['espera_minutos']
            . ' http=' . $codigo . ' nick=' . trim((string)$row->nick) . ' id=' . (int)$row->id
            . ' host=' . ($host !== '' ? $host : '-')
        );

        return AuralisNotification::PUSH_REINTENTO;
    }

    private static function retryAt(int $minutos): string
    {
        try {
            $until = (new \DateTimeImmutable('now'))->modify('+' . max(0, $minutos) . ' minutes');

            return $until->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return Tools::dateTime();
        }
    }

    /**
     * @return array<string, int>
     */
    private static function emptyStats(): array
    {
        return [
            'sent' => 0,
            'skipped_online' => 0,
            'skipped_disabled' => 0,
            'examined' => 0,
            'deferred_online' => 0,
            'deferred_no_sub' => 0,
            'deferred_retry' => 0,
            'closed_sin_permiso' => 0,
            'closed_tipo_sin_push' => 0,
            'closed_missed' => 0,
            'closed_agotado' => 0,
            'closed_caducado' => 0,
        ];
    }

    /**
     * @param array<string, int> $stats
     */
    private static function contarCierre(array &$stats, string $estado): void
    {
        $clave = 'closed_' . $estado;
        if (isset($stats[$clave])) {
            ++$stats[$clave];
        }
    }

    /**
     * Un aplazamiento que llegó al techo vuelve como agotado y cuenta como cierre.
     *
     * @param array<string, int> $stats
     */
    private static function contarAplazamiento(array &$stats, string $estado): void
    {
        if ($estado === AuralisNotification::PUSH_PENDIENTE_ONLINE) {
            ++$stats['deferred_online'];
        } elseif ($estado === AuralisNotification::PUSH_SIN_SUSCRIPCION) {
            ++$stats['deferred_no_sub'];
        } elseif ($estado === AuralisNotification::PUSH_REINTENTO) {
            ++$stats['deferred_retry'];
        } else {
            self::contarCierre($stats, $estado);
        }
    }

    /**
     * Traza nick + id + push_estado + host. Nunca endpoint, claves de la suscripción ni VAPID.
     */
    private static function log(string $nivel, AuralisNotification $row, string $host): void
    {
        $mensaje = 'auralis-push-estado-' . (string)$row->push_estado
            . ' nick=' . trim((string)$row->nick)
            . ' id=' . (int)$row->id
            . ' intentos=' . (int)($row->push_attempts ?? 0)
            . ' host=' . ($host !== '' ? $host : '-');

        if ($nivel === 'warning') {
            Tools::log('Auralis')->warning($mensaje);

            return;
        }

        Tools::log('Auralis')->notice($mensaje);
    }
}

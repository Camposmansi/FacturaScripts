<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description CronJob: envía recordatorios de calendario vencidos (catch-up, no minuto exacto).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\CronJob;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarNotificationSender;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;

final class SendCalendarNotifications extends CronJobClass
{
    public const JOB_NAME = 'auralis-send-calendar-notifications';

    public const BATCH_LIMIT = 100;

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        $now = new \DateTimeImmutable('now');
        $nowStr = $now->format('Y-m-d H:i:s');

        $pending = AuralisCalendarEventNotification::all(
            [
                Where::eq('sent', 0),
                Where::lte('scheduled_at', $nowStr),
            ],
            ['scheduled_at' => 'ASC', 'id' => 'ASC'],
            0,
            self::BATCH_LIMIT
        );

        // Legacy: filas sin scheduled_at (antes de la columna).
        if (count($pending) < self::BATCH_LIMIT) {
            $legacy = AuralisCalendarEventNotification::all(
                [
                    Where::eq('sent', 0),
                    Where::isNull('scheduled_at'),
                ],
                ['id' => 'ASC'],
                0,
                self::BATCH_LIMIT - count($pending)
            );
            $pending = array_merge($pending, $legacy);
        }

        $sentCount = 0;
        $skipped = 0;

        foreach ($pending as $notif) {
            $event = new AuralisCalendarEvent();
            if (false === $event->load((int)$notif->idevent)) {
                ++$skipped;
                continue;
            }

            $eventStart = AuralisCalendarEventNotification::eventDateTime($event);
            if ($eventStart === null || $eventStart <= $now) {
                // Evento ya iniciado: no tiene sentido enviar el recordatorio.
                ++$skipped;
                continue;
            }

            $sendAt = null;
            if (!empty($notif->scheduled_at)) {
                try {
                    $sendAt = new \DateTimeImmutable((string)$notif->scheduled_at);
                } catch (\Exception) {
                    $sendAt = null;
                }
            }
            if ($sendAt === null) {
                $sendAt = $notif->calcularFechaEnvio($event);
                if ($sendAt !== null) {
                    $notif->scheduled_at = $sendAt->format('Y-m-d H:i:s');
                    $notif->save();
                }
            }

            if ($sendAt === null || $sendAt > $now) {
                ++$skipped;
                continue;
            }

            if (CalendarNotificationSender::send($notif)) {
                ++$sentCount;
                self::echo("\n- notif #" . (int)$notif->id . ' enviada');
            }
        }

        self::echo("\n* enviadas={$sentCount} omitidas/no-vencidas={$skipped}");
        self::saveEcho();
    }
}

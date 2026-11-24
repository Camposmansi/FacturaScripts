<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Persistencia de recordatorios de calendario; el CronJob se encarga del envío diferido.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;

class CalendarNotificationScheduler
{
    /**
     * Sincroniza las notificaciones del evento.
     *
     * @param array<int, array{notification_name: string, offset_value: int, offset_unit: string}> $notificaciones
     *
     * @return array{saved: int, past: int}
     */
    public static function syncForEvent(AuralisCalendarEvent $event, array $notificaciones): array
    {
        $idevent = (int)($event->id ?? 0);
        if ($idevent <= 0) {
            return ['saved' => 0, 'past' => 0];
        }

        self::cancelForEvent($idevent);

        $now = new \DateTimeImmutable('now');
        $saved = 0;
        $past = 0;

        foreach ($notificaciones as $row) {
            $item = AuralisCalendarEventNotification::normalizarEntrada($row);
            if ($item === null) {
                continue;
            }

            $origen = (string)($event->origen_tipo ?? 'general');
            if (!CalendarNotificationRegistry::isNotificationAllowed($origen, $item['notification_name'])) {
                continue;
            }

            $notif = new AuralisCalendarEventNotification();
            $notif->clear();
            $notif->idevent = $idevent;
            $notif->notification_name = $item['notification_name'];
            $notif->offset_value = $item['offset_value'];
            $notif->offset_unit = $item['offset_unit'];

            $sendAt = $notif->calcularFechaEnvio($event);
            if ($sendAt !== null) {
                $notif->scheduled_at = $sendAt->format('Y-m-d H:i:s');
            }

            if (false === $notif->save()) {
                continue;
            }

            ++$saved;
            if ($sendAt === null || $sendAt <= $now) {
                ++$past;
            }
        }

        return ['saved' => $saved, 'past' => $past];
    }

    public static function cancelForEvent(int $idevent): void
    {
        if ($idevent <= 0) {
            return;
        }

        foreach (AuralisCalendarEventNotification::allByEvent($idevent) as $notif) {
            $notif->delete();
        }
    }

    public static function copyAndScheduleFromParent(AuralisCalendarEvent $parent, AuralisCalendarEvent $child): void
    {
        $parentId = (int)($parent->id ?? 0);
        $childId = (int)($child->id ?? 0);
        if ($parentId <= 0 || $childId <= 0) {
            return;
        }

        $rules = AuralisCalendarEventNotification::toArrayList($parentId);
        if ($rules === []) {
            return;
        }

        self::syncForEvent($child, $rules);
    }

    /**
     * Recalcula scheduled_at de notificaciones pendientes al mover el evento.
     */
    public static function rescheduleEvent(AuralisCalendarEvent $event): void
    {
        $idevent = (int)($event->id ?? 0);
        if ($idevent <= 0) {
            return;
        }

        foreach (AuralisCalendarEventNotification::allByEvent($idevent) as $notif) {
            if (!empty($notif->sent)) {
                continue;
            }

            $sendAt = $notif->calcularFechaEnvio($event);
            $notif->scheduled_at = $sendAt !== null ? $sendAt->format('Y-m-d H:i:s') : null;
            $notif->save();
        }
    }
}

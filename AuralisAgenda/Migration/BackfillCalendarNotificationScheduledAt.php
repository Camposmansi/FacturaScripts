<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Rellena scheduled_at en notificaciones de calendario existentes (inicio − offset).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;

class BackfillCalendarNotificationScheduledAt extends MigrationClass
{
    public const MIGRATION_NAME = 'backfill_calendar_notif_scheduled_at_v2026';

    public function run(): void
    {
        if (false === $this->db()->tableExists('auralis_calendar_event_notifications')) {
            return;
        }

        if (false === $this->db()->tableExists('auralis_calendar_events')) {
            return;
        }

        $probe = new AuralisCalendarEventNotification();
        if (false === $probe->hasColumn('scheduled_at')) {
            return;
        }

        $pending = AuralisCalendarEventNotification::all(
            [],
            ['id' => 'ASC'],
            0,
            0
        );

        foreach ($pending as $notif) {
            if (!empty($notif->scheduled_at)) {
                continue;
            }

            $event = new AuralisCalendarEvent();
            if (false === $event->load((int)$notif->idevent)) {
                continue;
            }

            $sendAt = $notif->calcularFechaEnvio($event);
            if ($sendAt === null) {
                continue;
            }

            $notif->scheduled_at = $sendAt->format('Y-m-d H:i:s');
            $notif->save();
        }
    }
}

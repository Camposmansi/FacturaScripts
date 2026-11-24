<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Backfill de participantes desde asignado/cabecera de eventos existentes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarParticipantSync;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;

class BackfillCalendarEventParticipants extends MigrationClass
{
    public const MIGRATION_NAME = 'backfill_calendar_event_participants_v2026_09';

    public function run(): void
    {
        if (false === $this->db()->tableExists(AuralisCalendarEventParticipant::tableName())) {
            return;
        }
        if (false === $this->db()->tableExists(AuralisCalendarEvent::tableName())) {
            return;
        }

        $events = AuralisCalendarEvent::all([], ['id' => 'ASC'], 0, 0);
        foreach ($events as $event) {
            CalendarParticipantSync::mirrorHeaderIntoParticipants($event);
        }
    }
}

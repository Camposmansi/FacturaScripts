<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Aviso por defecto global para nuevos eventos de calendario (valor 0 = desactivado).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;

class CalendarNotificationDefaults
{
    public const SETTING_OFFSET = 'calendar_default_notif_offset';

    public const SETTING_UNIT = 'calendar_default_notif_unit';

    /**
     * @return array<int, array{notification_name: string, offset_value: int, offset_unit: string}>
     */
    public static function getForOrigen(string $origenTipo): array
    {
        $offset = self::offsetValue();
        if ($offset <= 0) {
            return [];
        }

        $origenTipo = trim($origenTipo) !== '' ? trim($origenTipo) : 'general';
        $available = CalendarNotificationRegistry::availableNotificationsFor($origenTipo);
        if ($available === []) {
            return [];
        }

        $notificationName = (string)array_key_first($available);

        return [[
            'notification_name' => $notificationName,
            'offset_value' => $offset,
            'offset_unit' => self::offsetUnit(),
        ]];
    }

    public static function offsetValue(): int
    {
        return max(0, (int)Tools::settings(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_OFFSET,
            '0'
        ));
    }

    public static function offsetUnit(): string
    {
        return AuralisCalendarEventNotification::normalizarUnidad((string)Tools::settings(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_UNIT,
            AuralisCalendarEventNotification::UNIT_MINUTES
        ));
    }

    public static function save(int $offset, string $unit): void
    {
        Tools::settingsSet(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_OFFSET,
            (string)max(0, $offset)
        );
        Tools::settingsSet(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_UNIT,
            AuralisCalendarEventNotification::normalizarUnidad($unit)
        );
    }
}

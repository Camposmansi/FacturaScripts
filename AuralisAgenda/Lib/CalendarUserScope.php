<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Ámbito personal del calendario: filtro por creador, organizador o participante interno.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;

final class CalendarUserScope
{
    public const ORIGEN_TIPO = 'general';

    public static function nickDeUsuario(?object $usuario): string
    {
        if ($usuario === null) {
            return '';
        }

        return trim((string)($usuario->nick ?? ''));
    }

    /**
     * @return array<int, Where>
     */
    public static function whereParaUsuario(string $nick, string $hasta): array
    {
        $nick = trim($nick);
        $sub = [
            Where::eq('nick', $nick),
            Where::orEq('asignado', $nick),
        ];

        try {
            $participantIds = AuralisCalendarEventParticipant::eventIdsForUser($nick);
            if ($participantIds !== []) {
                $sub[] = Where::orIn('id', $participantIds);
            }
        } catch (\Throwable $e) {
            // Sin BD / tabla aún no creada: filtro solo nick|asignado (retrocompat).
        }

        return [
            Where::lte('fecha', $hasta),
            Where::eq('origen_tipo', self::ORIGEN_TIPO),
            Where::sub($sub),
        ];
    }

    public static function esDueno(?object $usuario, ?object $evento): bool
    {
        $nick = self::nickDeUsuario($usuario);
        if ($nick === '' || $evento === null) {
            return false;
        }

        if (trim((string)($evento->nick ?? '')) === $nick) {
            return true;
        }

        return trim((string)($evento->asignado ?? '')) === $nick;
    }

    public static function esParticipante(?object $usuario, ?object $evento): bool
    {
        $nick = self::nickDeUsuario($usuario);
        $idevent = (int)($evento->id ?? 0);
        if ($nick === '' || $idevent <= 0) {
            return false;
        }

        try {
            $ids = AuralisCalendarEventParticipant::eventIdsForUser($nick);
            return in_array($idevent, $ids, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function puedeVer(?object $usuario, ?object $evento): bool
    {
        if (self::esDueno($usuario, $evento) || self::esParticipante($usuario, $evento)) {
            return true;
        }

        // Calendario compartido con permiso ver o editar («ocupado» no abre el detalle).
        return $usuario instanceof User
            && !empty($evento->idcalendario)
            && CalendarioAcceso::puedeVerEvento($usuario, $evento);
    }
}

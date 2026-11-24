<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Generación y ampliación lazy de series recurrentes del calendario Auralis (ventana 52, sin Cron).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarNotificationScheduler;

class CalendarRecurrence
{
    public const BATCH_SIZE = 52;
    public const EXPAND_THRESHOLD = 7;

    private const SETTINGS_GROUP = 'auralis';
    private const SETTINGS_KEY = 'calendar_fechafallback';

    /**
     * Throttle global 1×/día: si fechafallback < hoy, amplía series activas y guarda hoy.
     */
    public static function maybeExpandLazy(): void
    {
        $hoy = Tools::date();
        $fallback = (string)Tools::settings(self::SETTINGS_GROUP, self::SETTINGS_KEY, '');

        if ($fallback !== '' && $fallback >= $hoy) {
            return;
        }

        self::expandActiveSeries();
        Tools::settingsSet(self::SETTINGS_GROUP, self::SETTINGS_KEY, $hoy);
        Tools::settingsSave();
    }

    /**
     * Amplía todas las series padre activas que tengan menos de EXPAND_THRESHOLD futuras.
     */
    public static function expandActiveSeries(): int
    {
        $created = 0;
        $parents = AuralisCalendarEvent::all([
            Where::isNull('idparent'),
            Where::eq('se_repite', 1),
            Where::eq('serie_activa', 1),
        ], ['id' => 'ASC']);

        foreach ($parents as $parent) {
            $created += self::expandSeries($parent);
        }

        return $created;
    }

    /**
     * Tras crear el padre, materializa hasta BATCH_SIZE ocurrencias (padre incluido).
     */
    public static function createInitialWindow(AuralisCalendarEvent $parent): int
    {
        if (empty($parent->id) || empty($parent->se_repite)) {
            return 0;
        }

        $parent->serie_activa = 1;
        $parent->rep_generados = max(1, (int)$parent->rep_generados);
        $parent->save();

        return self::fillWindow($parent, self::BATCH_SIZE - 1, false);
    }

    /**
     * Amplía una serie desde MAX(fecha) si hay menos de EXPAND_THRESHOLD futuras.
     */
    public static function expandSeries(AuralisCalendarEvent $parent): int
    {
        if (empty($parent->id) || empty($parent->se_repite) || empty($parent->serie_activa)) {
            return 0;
        }

        $hoy = Tools::date();
        $futureCount = self::countFutureOccurrences((int)$parent->id, $hoy);
        if ($futureCount >= self::EXPAND_THRESHOLD) {
            return 0;
        }

        $needed = self::BATCH_SIZE - $futureCount;
        if ($needed <= 0) {
            return 0;
        }

        return self::fillWindow($parent, $needed, true);
    }

    /**
     * ID del padre de serie (raíz).
     */
    public static function seriesRootId(AuralisCalendarEvent $event): int
    {
        if (!empty($event->idparent)) {
            return (int)$event->idparent;
        }

        return (int)($event->id ?? 0);
    }

    /**
     * Indica si el evento forma parte de una serie recurrente.
     */
    public static function isRecurring(AuralisCalendarEvent $event): bool
    {
        if (!empty($event->idparent)) {
            return true;
        }

        return !empty($event->se_repite);
    }

    /**
     * Borra esta y futuras (≥ ancla) y desactiva la serie en el padre.
     */
    public static function deleteThisAndFuture(AuralisCalendarEvent $event): bool
    {
        $rootId = self::seriesRootId($event);
        if ($rootId <= 0 || empty($event->fecha)) {
            return $event->delete();
        }

        $ancla = date('Y-m-d', strtotime((string)$event->fecha));
        $toDelete = self::seriesEventsFromDate($rootId, $ancla);

        $ok = true;
        foreach ($toDelete as $ev) {
            if (!$ev->delete()) {
                $ok = false;
            }
        }

        $parent = new AuralisCalendarEvent();
        if ($parent->load($rootId)) {
            $parent->serie_activa = 0;
            $parent->save();
        }

        return $ok;
    }

    /**
     * Actualiza esta y futuras (≥ ancla). Si cambia la regla, regenera una ventana 52.
     *
     * @param array<string, mixed> $data Campos de datos a propagar
     */
    public static function updateThisAndFuture(
        AuralisCalendarEvent $event,
        array $data,
        bool $ruleChanged
    ): bool {
        $rootId = self::seriesRootId($event);
        if ($rootId <= 0 || empty($event->fecha)) {
            return self::applyData($event, $data, true) && $event->save();
        }

        $ancla = date('Y-m-d', strtotime((string)$event->fecha));

        if ($ruleChanged) {
            return self::regenerateFromAnchor($event, $data, $rootId, $ancla);
        }

        $targets = self::seriesEventsFromDate($rootId, $ancla);
        $spanParent = new AuralisCalendarEvent();
        if ($spanParent->load($rootId)) {
            self::applyData($spanParent, $data, false);
        } else {
            $spanParent = $event;
            self::applyData($spanParent, $data, false);
        }

        $ok = true;
        foreach ($targets as $ev) {
            if (!self::applyData($ev, $data, false)) {
                $ok = false;
                continue;
            }
            self::syncAllDayEndFromSpan($ev, $spanParent);
            if (!$ev->save()) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Días de duración de un bloque todo el día (0 = un solo día).
     */
    public static function allDaySpanDays(AuralisCalendarEvent $event): int
    {
        if (empty($event->todo_dia) || empty($event->fecha)) {
            return 0;
        }

        return self::calcAllDaySpanDays(
            (string)$event->fecha,
            $event->fecha_fin !== null ? (string)$event->fecha_fin : null
        );
    }

    /**
     * Calcula el span en días entre fecha inicio y fin de un bloque todo el día.
     */
    public static function calcAllDaySpanDays(string $fecha, ?string $fechaFin): int
    {
        $inicio = date('Y-m-d', strtotime($fecha));
        $fin = !empty($fechaFin)
            ? date('Y-m-d', strtotime($fechaFin))
            : $inicio;

        if ($fin < $inicio) {
            $fin = $inicio;
        }

        $start = new \DateTime($inicio);
        $end = new \DateTime($fin);

        return (int)$start->diff($end)->days;
    }

    /**
     * Calcula fecha_fin de una ocurrencia todo el día a partir del inicio y el span del padre.
     */
    public static function allDayEndForStart(string $fechaInicio, AuralisCalendarEvent $parent): string
    {
        $span = self::allDaySpanDays($parent);

        return self::calcAllDayEndDate($fechaInicio, $span);
    }

    /**
     * Suma span días a una fecha de inicio (0 = mismo día).
     */
    public static function calcAllDayEndDate(string $fechaInicio, int $spanDays): string
    {
        if ($spanDays <= 0) {
            return date('Y-m-d', strtotime($fechaInicio));
        }

        $dt = new \DateTime(date('Y-m-d', strtotime($fechaInicio)));
        $dt->modify('+' . $spanDays . ' day');

        return $dt->format('Y-m-d');
    }

    /**
     * Calcula la siguiente fecha según intervalo y unidad.
     */
    public static function nextDate(string $fecha, int $intervalo, string $unidad): string
    {
        $intervalo = max(1, $intervalo);
        $dt = new \DateTime($fecha);

        switch ($unidad) {
            case 'day':
                $dt->modify('+' . $intervalo . ' day');
                break;
            case 'month':
                $dt->modify('+' . $intervalo . ' month');
                break;
            case 'week':
            default:
                $dt->modify('+' . ($intervalo * 7) . ' day');
                break;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * @return AuralisCalendarEvent[]
     */
    public static function seriesChildren(int $rootId): array
    {
        if ($rootId <= 0) {
            return [];
        }

        return AuralisCalendarEvent::all(
            [Where::eq('id|idparent', $rootId)],
            ['fecha' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * @return AuralisCalendarEvent[]
     */
    private static function seriesEventsFromDate(int $rootId, string $ancla): array
    {
        return AuralisCalendarEvent::all([
            Where::eq('id|idparent', $rootId),
            Where::gte('fecha', $ancla),
        ], ['fecha' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function regenerateFromAnchor(
        AuralisCalendarEvent $event,
        array $data,
        int $rootId,
        string $ancla
    ): bool {
        // Desactivar serie antigua si el padre sobrevive (eventos anteriores al ancla)
        $oldParent = new AuralisCalendarEvent();
        if ($oldParent->load($rootId)) {
            $parentFecha = date('Y-m-d', strtotime((string)$oldParent->fecha));
            if ($parentFecha < $ancla) {
                $oldParent->serie_activa = 0;
                $oldParent->save();
            }
        }

        foreach (self::seriesEventsFromDate($rootId, $ancla) as $ev) {
            $ev->delete();
        }

        $nuevo = new AuralisCalendarEvent();
        $nuevo->clear();
        self::applyData($nuevo, $data, true);
        $nuevo->fecha = $ancla;
        $nuevo->idparent = null;
        $nuevo->se_repite = 1;
        $nuevo->serie_activa = 1;
        $nuevo->rep_generados = 1;
        $nuevo->nick = $event->nick ?: $nuevo->nick;

        if (!$nuevo->save()) {
            return false;
        }

        self::createInitialWindow($nuevo);
        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function applyData(AuralisCalendarEvent $event, array $data, bool $includeRule): bool
    {
        $allowed = [
            'titulo', 'descripcion', 'hora_inicio', 'duracion', 'color', 'asignado',
            'todo_dia', 'fecha_fin', 'codagente', 'idsala', 'idempresa', 'origen_tipo',
            'origen_id', 'codcliente', 'idcalendario',
        ];

        if ($includeRule) {
            $allowed = array_merge($allowed, [
                'se_repite', 'rep_intervalo', 'rep_unidad', 'rep_fin_tipo', 'rep_fin_fecha', 'rep_fin_count',
            ]);
        }

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $event->{$field} = $data[$field];
            }
        }

        return true;
    }

    private static function fillWindow(AuralisCalendarEvent $parent, int $maxInserts, bool $fromMaxFecha): int
    {
        if ($maxInserts <= 0 || empty($parent->id)) {
            return 0;
        }

        $intervalo = max(1, (int)($parent->rep_intervalo ?? 1));
        $unidad = (string)($parent->rep_unidad ?? 'week');
        $existingDates = self::existingSeriesDates((int)$parent->id);

        if ($fromMaxFecha) {
            $maxFecha = self::maxSeriesFecha((int)$parent->id);
            $cursor = $maxFecha !== null
                ? self::nextDate($maxFecha, $intervalo, $unidad)
                : self::nextDate((string)$parent->fecha, $intervalo, $unidad);
        } else {
            $cursor = self::nextDate((string)$parent->fecha, $intervalo, $unidad);
        }

        $created = 0;
        $safety = 0;
        $maxSafety = self::BATCH_SIZE * 4;

        while ($created < $maxInserts && $safety < $maxSafety) {
            $safety++;

            if (!self::dateAllowedByEnd($parent, $cursor, (int)$parent->rep_generados + $created)) {
                $parent->serie_activa = 0;
                $parent->save();
                break;
            }

            if (isset($existingDates[$cursor])) {
                $cursor = self::nextDate($cursor, $intervalo, $unidad);
                continue;
            }

            $child = self::cloneAsChild($parent, $cursor);
            if (!$child->save()) {
                break;
            }

            CalendarNotificationScheduler::copyAndScheduleFromParent($parent, $child);

            $existingDates[$cursor] = true;
            $created++;
            $cursor = self::nextDate($cursor, $intervalo, $unidad);
        }

        if ($created > 0) {
            $parent->rep_generados = (int)$parent->rep_generados + $created;
            $parent->save();
        }

        return $created;
    }

    private static function dateAllowedByEnd(AuralisCalendarEvent $parent, string $fecha, int $projectedCount): bool
    {
        $tipo = (string)($parent->rep_fin_tipo ?? 'nunca');

        if ($tipo === 'fecha' && !empty($parent->rep_fin_fecha)) {
            return $fecha <= date('Y-m-d', strtotime((string)$parent->rep_fin_fecha));
        }

        if ($tipo === 'count' && !empty($parent->rep_fin_count)) {
            return $projectedCount <= (int)$parent->rep_fin_count;
        }

        return true;
    }

    private static function cloneAsChild(AuralisCalendarEvent $parent, string $fecha): AuralisCalendarEvent
    {
        $child = new AuralisCalendarEvent();
        $child->clear();
        $child->fecha = $fecha;
        $child->fecha_fin = null;
        $child->hora_inicio = $parent->hora_inicio;
        $child->duracion = (int)$parent->duracion;
        $child->titulo = $parent->titulo;
        $child->descripcion = $parent->descripcion;
        $child->nick = $parent->nick;
        $child->asignado = $parent->asignado;
        $child->codagente = $parent->codagente;
        $child->idsala = $parent->idsala;
        $child->todo_dia = (int)$parent->todo_dia;
        $child->origen_tipo = $parent->origen_tipo;
        $child->origen_id = $parent->origen_id;
        $child->color = $parent->color;
        $child->estado = $parent->estado;
        $child->codcliente = $parent->codcliente;
        $child->idcontacto = $parent->idcontacto;
        $child->idempresa = (int)$parent->idempresa;
        $child->idcalendario = $parent->idcalendario;
        $child->idparent = (int)$parent->id;
        $child->se_repite = 0;
        $child->rep_intervalo = null;
        $child->rep_unidad = null;
        $child->rep_fin_tipo = null;
        $child->rep_fin_fecha = null;
        $child->rep_fin_count = null;
        $child->serie_activa = 1;
        $child->rep_generados = 1;

        if (!empty($child->todo_dia)) {
            $child->fecha_fin = self::allDayEndForStart($fecha, $parent);
            $child->hora_inicio = '00:00:00';
            $child->duracion = 0;
        }

        return $child;
    }

    private static function syncAllDayEndFromSpan(AuralisCalendarEvent $event, AuralisCalendarEvent $spanSource): void
    {
        if (empty($event->todo_dia) || empty($event->fecha)) {
            return;
        }

        $event->fecha_fin = self::allDayEndForStart(
            date('Y-m-d', strtotime((string)$event->fecha)),
            $spanSource
        );
        $event->hora_inicio = '00:00:00';
        $event->duracion = 0;
    }

    /**
     * @return array<string, bool>
     */
    private static function existingSeriesDates(int $rootId): array
    {
        $dates = [];
        foreach (AuralisCalendarEvent::all([Where::eq('id|idparent', $rootId)]) as $ev) {
            if (!empty($ev->fecha)) {
                $dates[date('Y-m-d', strtotime((string)$ev->fecha))] = true;
            }
        }

        return $dates;
    }

    private static function maxSeriesFecha(int $rootId): ?string
    {
        $max = null;
        foreach (AuralisCalendarEvent::all([Where::eq('id|idparent', $rootId)]) as $ev) {
            if (empty($ev->fecha)) {
                continue;
            }
            $f = date('Y-m-d', strtotime((string)$ev->fecha));
            if ($max === null || $f > $max) {
                $max = $f;
            }
        }

        return $max;
    }

    private static function countFutureOccurrences(int $rootId, string $hoy): int
    {
        $count = 0;
        $parent = new AuralisCalendarEvent();
        if ($parent->load($rootId) && !empty($parent->fecha) && $parent->fecha >= $hoy) {
            $count++;
        }

        foreach (AuralisCalendarEvent::all([
            Where::eq('idparent', $rootId),
            Where::gte('fecha', $hoy),
        ]) as $ev) {
            $count++;
        }

        return $count;
    }
}

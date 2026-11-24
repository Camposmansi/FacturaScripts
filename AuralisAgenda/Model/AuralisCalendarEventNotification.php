<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Recordatorio programado por email para un evento de calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;

class AuralisCalendarEventNotification extends ModelClass
{
    use ModelTrait;

    public const UNIT_MINUTES = 'minutes';

    public const UNIT_HOURS = 'hours';

    public const UNIT_DAYS = 'days';

    public const UNIT_WEEKS = 'weeks';

    /** @var int|null */
    public $id;

    /** @var int */
    public $idevent;

    /** @var string */
    public $notification_name;

    /** @var int */
    public $offset_value;

    /** @var string */
    public $offset_unit;

    /** @var string|null */
    public $scheduled_at;

    /** @var int|null */
    public $idworkevent;

    /** @var int */
    public $sent;

    /** @var string|null */
    public $sent_at;

    public function clear(): void
    {
        parent::clear();
        $this->offset_value = 10;
        $this->offset_unit = self::UNIT_MINUTES;
        $this->scheduled_at = null;
        $this->sent = 0;
        $this->sent_at = null;
        $this->idworkevent = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_event_notifications';
    }

    public function test(): bool
    {
        $this->notification_name = Tools::noHtml(trim((string)($this->notification_name ?? '')));
        if ($this->notification_name === '') {
            return false;
        }

        $this->offset_value = max(1, (int)($this->offset_value ?? 1));
        $this->offset_unit = self::normalizarUnidad((string)($this->offset_unit ?? self::UNIT_MINUTES));
        $this->idevent = (int)($this->idevent ?? 0);
        $this->sent = !empty($this->sent) ? 1 : 0;

        if ($this->idevent <= 0) {
            return false;
        }

        return parent::test();
    }

    /**
     * @return self[]
     */
    public static function allByEvent(int $idevent): array
    {
        if ($idevent <= 0) {
            return [];
        }

        return static::all(
            [Where::eq('idevent', $idevent)],
            ['offset_value' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * @return array<int, array{notification_name: string, offset_value: int, offset_unit: string}>
     */
    public static function toArrayList(int $idevent): array
    {
        $list = [];
        foreach (self::allByEvent($idevent) as $row) {
            $list[] = [
                'notification_name' => (string)$row->notification_name,
                'offset_value' => (int)$row->offset_value,
                'offset_unit' => (string)$row->offset_unit,
            ];
        }

        return $list;
    }

    public function calcularFechaEnvio(AuralisCalendarEvent $event): ?\DateTimeImmutable
    {
        $eventDt = self::eventDateTime($event);
        if ($eventDt === null) {
            return null;
        }

        $intervalSpec = self::intervalSpec((int)$this->offset_value, (string)$this->offset_unit);
        if ($intervalSpec === null) {
            return null;
        }

        try {
            return $eventDt->sub(new \DateInterval($intervalSpec));
        } catch (\Exception) {
            return null;
        }
    }

    public static function eventDateTime(AuralisCalendarEvent $event): ?\DateTimeImmutable
    {
        $fecha = !empty($event->fecha) ? date('Y-m-d', strtotime((string)$event->fecha)) : null;
        if ($fecha === null) {
            return null;
        }

        if (!empty($event->todo_dia)) {
            $hora = sprintf('%02d:00:00', CalendarTimegrid::DEFAULT_HORA_INICIO);
        } else {
            $hora = (string)($event->hora_inicio ?? '09:00:00');
            if (mb_strlen($hora) === 5) {
                $hora .= ':00';
            }
        }

        try {
            return new \DateTimeImmutable($fecha . ' ' . $hora);
        } catch (\Exception) {
            return null;
        }
    }

    public static function normalizarUnidad(string $unit): string
    {
        $valid = [self::UNIT_MINUTES, self::UNIT_HOURS, self::UNIT_DAYS, self::UNIT_WEEKS];

        return in_array($unit, $valid, true) ? $unit : self::UNIT_MINUTES;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function normalizarEntrada(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        $name = strip_tags(trim((string)($row['notification_name'] ?? '')));
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        if ($name === '') {
            return null;
        }

        return [
            'notification_name' => $name,
            'offset_value' => max(1, (int)($row['offset_value'] ?? 1)),
            'offset_unit' => self::normalizarUnidad((string)($row['offset_unit'] ?? self::UNIT_MINUTES)),
        ];
    }

    private static function intervalSpec(int $value, string $unit): ?string
    {
        return match (self::normalizarUnidad($unit)) {
            self::UNIT_MINUTES => 'PT' . $value . 'M',
            self::UNIT_HOURS => 'PT' . $value . 'H',
            self::UNIT_DAYS => 'P' . $value . 'D',
            self::UNIT_WEEKS => 'P' . $value . 'W',
            default => null,
        };
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Modelo de tipos o motivos de eventos para el calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;

class AuralisCalendarEventType extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $id;

    /** @var string */
    public $nombre;

    /** @var string|null */
    public $color;

    /** @var int|null */
    public $duracion;

    /** @var string */
    public $origen_tipo;

    /** @var bool */
    public $activo;

    public function clear(): void
    {
        parent::clear();
        $this->nombre = '';
        $this->color = '#4285f4';
        $this->duracion = CalendarTimegrid::duracionDefault();
        $this->origen_tipo = 'general';
        $this->activo = true;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_event_types';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string)($this->nombre ?? '')));
        if ($this->nombre === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre']);
            return false;
        }

        $this->origen_tipo = Tools::noHtml(trim((string)($this->origen_tipo ?? 'general')));
        if ($this->origen_tipo === '') {
            $this->origen_tipo = 'general';
        }

        if (!empty($this->color)) {
            $this->color = Tools::noHtml(trim((string)$this->color));
        } else {
            $this->color = null;
        }

        if ($this->duracion !== null) {
            $this->duracion = max(5, min(1440, (int)$this->duracion));
        }

        $this->activo = !empty($this->activo);

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list') {
            return 'ConfigAuralisServiciosCalendario?activetab=ListAuralisCalendarEventType';
        }

        return parent::url($type, $list);
    }
}

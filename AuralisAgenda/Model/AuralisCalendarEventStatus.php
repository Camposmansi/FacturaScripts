<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Modelo de estados de ciclo de vida de eventos para el calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisCalendarEventStatus extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $codestado;

    /** @var string */
    public $nombre;

    /** @var int */
    public $orden;

    /** @var string */
    public $origen_tipo;

    /** @var bool */
    public $activo;

    public function clear(): void
    {
        parent::clear();
        $this->codestado = '';
        $this->nombre = '';
        $this->orden = 10;
        $this->origen_tipo = 'general';
        $this->activo = true;
    }

    public static function primaryColumn(): string
    {
        return 'codestado';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_event_statuses';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string)($this->nombre ?? '')));
        if ($this->nombre === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre']);
            return false;
        }

        $cod = mb_strtolower(Tools::noHtml(trim((string)($this->codestado ?? ''))));
        if ($cod === '') {
            $base = str_replace('-', '_', Tools::slug($this->nombre));
            $base = mb_substr($base, 0, 16);
            if ($base === '') {
                $base = 'est';
            }
            $candidate = $base;
            $i = 1;
            while (static::get($candidate) !== false) {
                $candidate = mb_substr($base, 0, 16) . '_' . $i;
                $i++;
            }
            $cod = mb_substr($candidate, 0, 20);
        }
        $this->codestado = $cod;

        $this->origen_tipo = Tools::noHtml(trim((string)($this->origen_tipo ?? 'general')));
        if ($this->origen_tipo === '') {
            $this->origen_tipo = 'general';
        }

        $this->orden = (int)($this->orden ?? 10);
        $this->activo = !empty($this->activo);

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list') {
            return 'ConfigAuralisServiciosCalendario?activetab=ListAuralisCalendarEventStatus';
        }

        return parent::url($type, $list);
    }
}

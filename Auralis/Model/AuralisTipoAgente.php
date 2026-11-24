<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Modelo de tipos o roles de agente para clasificación centralizada en Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisTipoAgente extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $idtipo;

    /** @var string|null */
    public $codigo;

    /** @var string */
    public $nombre;

    /** @var bool */
    public $activo;

    public function clear(): void
    {
        parent::clear();
        $this->codigo = null;
        $this->nombre = '';
        $this->activo = true;
    }

    public static function primaryColumn(): string
    {
        return 'idtipo';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'auralis_tipos_agentes';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string) ($this->nombre ?? '')));
        if ($this->nombre === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre']);
            return false;
        }

        if (mb_strlen($this->nombre) > 100) {
            $this->nombre = mb_substr($this->nombre, 0, 100);
        }

        if (!empty($this->codigo)) {
            $this->codigo = mb_strtoupper(Tools::noHtml(trim((string) $this->codigo)));
            if (mb_strlen($this->codigo) > 50) {
                $this->codigo = mb_substr($this->codigo, 0, 50);
            }
        } else {
            $this->codigo = null;
        }

        $this->activo = (bool) $this->activo;

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list') {
            return 'ConfigAjustesAuralis?activetab=ListTipoAgente';
        }

        return parent::url($type, $list);
    }
}

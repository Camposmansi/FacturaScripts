<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Modelo de salas asociadas a almacén para el calendario polimórfico de Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisSala extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $idsala;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $nombre;

    /** @var string|null */
    public $descripcion;

    /** @var bool */
    public $activa;

    public function clear(): void
    {
        parent::clear();
        $this->codalmacen = $this->defaultCodalmacen();
        $this->nombre = '';
        $this->descripcion = null;
        $this->activa = true;
    }

    public static function primaryColumn(): string
    {
        return 'idsala';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'auralis_salas';
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string)$this->nombre));
        $this->descripcion = empty($this->descripcion) ? null : Tools::noHtml((string)$this->descripcion);
        $this->codalmacen = Tools::noHtml(trim((string)($this->codalmacen ?? '')));
        $this->activa = (bool)$this->activa;

        if ($this->nombre === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre']);
            return false;
        }

        if ($this->codalmacen === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'codalmacen']);
            return false;
        }

        return parent::test();
    }

    /**
     * Almacén del usuario en sesión, o el de ajustes por defecto.
     */
    private function defaultCodalmacen(): string
    {
        $user = Session::user();
        if ($user && !empty($user->codalmacen)) {
            return (string)$user->codalmacen;
        }

        return (string)Tools::settings('default', 'codalmacen', '');
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list') {
            return 'ConfigAuralisServiciosCalendario?activetab=ListAuralisSala';
        }

        return parent::url($type, $list);
    }
}

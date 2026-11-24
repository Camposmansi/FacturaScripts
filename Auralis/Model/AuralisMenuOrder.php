<?php
/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Modelo para almacenar la ordenación personalizada del menú en Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisMenuOrder extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $id;

    /** @var int */
    public $nivel;

    /** @var string */
    public $nombre;

    /** @var string|null */
    public $padre;

    /** @var int */
    public $posicion;

    /** @var string */
    public $creado;

    public function clear(): void
    {
        parent::clear();
        $this->nivel = 1;
        $this->nombre = '';
        $this->padre = null;
        $this->posicion = 0;
        $this->creado = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_menu_orders';
    }
}

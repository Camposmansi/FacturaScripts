<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Extensión del modelo Agente para clasificación centralizada en Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Extension\Model;

use Closure;

class Agente
{
    /** @var int|null */
    public $idtipo_agente;

    public function test(): Closure
    {
        return function () {
            $this->idtipo_agente = empty($this->idtipo_agente) ? null : (int) $this->idtipo_agente;
        };
    }
}

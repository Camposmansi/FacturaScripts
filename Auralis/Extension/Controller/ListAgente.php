<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Filtro autocomplete por tipo de agente en el listado de agentes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Extension\Controller;

use Closure;
use FacturaScripts\Core\Where;

class ListAgente
{
    public function createViews(): Closure
    {
        return function (): void {
            $this->addFilterAutocomplete(
                'ListAgente',
                'idtipo_agente',
                'auralis-tipo-agente',
                'idtipo_agente',
                'auralis_tipos_agentes',
                'idtipo',
                'nombre',
                [Where::eq('activo', true)]
            );
        };
    }
}

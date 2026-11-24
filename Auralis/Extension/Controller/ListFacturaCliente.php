<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Hidrata las columnas % cobrado / por cobrar en el listado de facturas de cliente.
 */

namespace FacturaScripts\Plugins\Auralis\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Auralis\Lib\RecibosFacturaPctStatus;

class ListFacturaCliente
{
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ListFacturaCliente') {
                return;
            }

            RecibosFacturaPctStatus::hydrateView($view);
        };
    }
}

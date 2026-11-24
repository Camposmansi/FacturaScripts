<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Hidrata las columnas % pagado / por pagar en el listado de facturas de proveedor.
 */

namespace FacturaScripts\Plugins\Auralis\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Auralis\Lib\RecibosFacturaPctStatus;

class ListFacturaProveedor
{
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName !== 'ListFacturaProveedor') {
                return;
            }

            RecibosFacturaPctStatus::hydrateProveedorView($view);
        };
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Tras crear un albarán, vincula el evento de calendario (Session idevent).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Extension\Model;

use Closure;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarEventDocLink;

class AlbaranCliente
{
    public function saveInsert(): Closure
    {
        return function () {
            CalendarEventDocLink::linkAfterInsert($this, 'AlbaranCliente');

            return true;
        };
    }
}

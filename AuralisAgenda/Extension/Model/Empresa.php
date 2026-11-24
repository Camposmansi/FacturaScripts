<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Al dar de alta una empresa crea su calendario común «Empresa» (una sola vez; si se borra, no vuelve).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Extension\Model;

use Closure;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;

class Empresa
{
    public function saveInsert(): Closure
    {
        return function (): void {
            // Un fallo del calendario no impide dar de alta la empresa (asegurarComunEmpresa no lanza).
            CalendarioAcceso::asegurarComunEmpresa((int)$this->idempresa);
        };
    }
}

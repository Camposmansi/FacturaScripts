<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Crea el calendario común «Empresa» (acceso de toda la empresa en «ver») de cada empresa que aún no tenga ningún común. Se ejecuta una sola vez.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use RuntimeException;

class CrearComunEmpresa extends MigrationClass
{
    public const MIGRATION_NAME = 'comun_empresa_v1_76';

    public function run(): void
    {
        foreach (['auralis_calendarios', 'auralis_calendario_accesos'] as $tabla) {
            if (false === $this->db()->tableExists($tabla)) {
                throw new RuntimeException('auralis-calendarios: falta la tabla ' . $tabla);
            }
        }

        // Una empresa que falle no bloquea las demás ni las migraciones siguientes: queda en el log.
        foreach ($this->db()->select('SELECT idempresa FROM empresas ORDER BY idempresa ASC') as $fila) {
            CalendarioAcceso::asegurarComunEmpresa((int)$fila['idempresa']);
        }
    }
}

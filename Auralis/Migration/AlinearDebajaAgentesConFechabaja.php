<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Alinea debaja con fechabaja en agentes históricos inconsistentes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Migration;

use FacturaScripts\Core\Template\MigrationClass;

class AlinearDebajaAgentesConFechabaja extends MigrationClass
{
    public const MIGRATION_NAME = 'alinear_debaja_agentes_con_fechabaja_v1';

    public function run(): void
    {
        if (false === $this->db()->tableExists('agentes')) {
            return;
        }

        $columns = $this->db()->getColumns('agentes');
        if (false === isset($columns['debaja']) || false === isset($columns['fechabaja'])) {
            return;
        }

        // Con fechabaja real => de baja
        $this->db()->exec(
            "UPDATE agentes SET debaja = true"
            . " WHERE fechabaja IS NOT NULL"
            . " AND fechabaja <> ''"
            . " AND fechabaja <> '0'"
            . " AND fechabaja <> '0000-00-00'"
            . " AND (debaja = false OR debaja IS NULL)"
        );

        // Sin fechabaja útil => activo
        $this->db()->exec(
            "UPDATE agentes SET debaja = false"
            . " WHERE (fechabaja IS NULL OR fechabaja = '' OR fechabaja = '0' OR fechabaja = '0000-00-00')"
            . " AND debaja = true"
        );
    }
}

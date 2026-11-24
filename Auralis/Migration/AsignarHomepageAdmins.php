<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Asigna DashBoardAuralis como homepage nativa a admins e instalación
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Plugins\Auralis\Lib\HomepageAdmins;

class AsignarHomepageAdmins extends MigrationClass
{
    public const MIGRATION_NAME = 'asignar_homepage_admins';

    public function run(): void
    {
        HomepageAdmins::asignarMasivo(self::db());
    }
}

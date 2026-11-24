<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Edición de una sala Auralis (almacén, nombre, descripción y estado activo).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

class EditAuralisSala extends EditController
{
    public function getModelClassName(): string
    {
        return 'AuralisSala';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-sala';
        $data['icon'] = 'fa-solid fa-door-open';
        $data['showonmenu'] = false;

        return $data;
    }
}

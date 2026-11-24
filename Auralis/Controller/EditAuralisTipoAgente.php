<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Edición de un tipo o rol de agente Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

class EditAuralisTipoAgente extends EditController
{
    public function getModelClassName(): string
    {
        return 'AuralisTipoAgente';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-tipo-agente';
        $data['icon'] = 'fa-solid fa-id-badge';
        $data['showonmenu'] = false;

        return $data;
    }
}

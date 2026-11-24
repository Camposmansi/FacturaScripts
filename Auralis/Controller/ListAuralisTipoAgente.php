<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Redirección hacia el panel ConfigAjustesAuralis para tipos de agente.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Template\Controller;

class ListAuralisTipoAgente extends Controller
{
    public function privateCore(&$response, $user, $permissions)
    {
        $tab = $this->request->query('activetab', 'ListTipoAgente');
        $this->redirect('ConfigAjustesAuralis?activetab=' . urlencode($tab));
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['showonmenu'] = false;
        return $data;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Redirección de retrocompatibilidad hacia el hub ConfigAuralisServiciosCalendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Template\Controller;

class ListAuralisSala extends Controller
{
    public function privateCore(&$response, $user, $permissions)
    {
        $tab = $this->request->query('activetab', 'ListAuralisSala');
        $this->redirect('ConfigAuralisServiciosCalendario?activetab=' . urlencode($tab));
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['showonmenu'] = false;
        return $data;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Edición central de una notificación programada de calendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

class EditAuralisCalendarEventNotification extends EditController
{
    public function getModelClassName(): string
    {
        return 'Join\\AuralisCalendarEventNotification';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'calendar-notif-programada';
        $data['icon'] = 'fa-solid fa-bell';
        $data['showonmenu'] = false;

        return $data;
    }

    protected function createViews(): void
    {
        $viewName = 'EditAuralisCalendarEventNotification';
        $this->addEditView($viewName, $this->getModelClassName(), 'calendar-notif-programada', 'fa-solid fa-bell');
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'btnPrint', false);
    }
}

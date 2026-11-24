<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Edición de un tipo o motivo de evento para el calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarOrigins;

class EditAuralisCalendarEventType extends EditController
{
    public function getModelClassName(): string
    {
        return 'AuralisCalendarEventType';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-calendar-event-type';
        $data['icon'] = 'fa-solid fa-tag';
        $data['showonmenu'] = false;

        return $data;
    }

    protected function createViews(): void
    {
        parent::createViews();
        $column = $this->views[$this->mainTabName()]->columnForName('origen_tipo');
        if ($column && $column->widget) {
            $column->widget->setValuesFromArray(CalendarOrigins::getSelectOptions(), false, false);
        }
    }
}

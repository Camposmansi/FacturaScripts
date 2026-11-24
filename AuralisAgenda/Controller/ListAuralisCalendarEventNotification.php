<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Listado central de notificaciones programadas de calendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class ListAuralisCalendarEventNotification extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'calendar-notif-programadas';
        $data['icon'] = 'fa-solid fa-bell';
        $data['showonmenu'] = false;
        $data['ordernum'] = 50;

        return $data;
    }

    protected function createViews(): void
    {
        $viewName = 'ListAuralisCalendarEventNotification';
        $this->addView($viewName, 'Join\AuralisCalendarEventNotification', 'calendar-notif-programadas', 'fa-solid fa-bell')
            ->addSearchFields(['titulo', 'notification_name', 'asignado', 'origen_tipo'])
            ->addOrderBy(['scheduled_at'], 'calendar-notif-programada-para', 2)
            ->addOrderBy(['fecha', 'hora_inicio'], 'date')
            ->addOrderBy(['sent_at'], 'date')
            ->addOrderBy(['id'], 'code');

        $this->setSettings($viewName, 'btnNew', false);

        $this->addFilterPeriod($viewName, 'scheduled_at', 'calendar-notif-programada-para', 'scheduled_at');
        $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');
        $this->addFilterPeriod($viewName, 'sent_at', 'calendar-notif-enviada-el', 'sent_at');
        $this->addFilterSelect($viewName, 'origen_tipo', 'origen_tipo', 'origen_tipo', $this->codeModel->all('auralis_calendar_events', 'origen_tipo', 'origen_tipo'));
        $this->addFilterSelect($viewName, 'idsala', 'auralis-sala', 'idsala', $this->codeModel->all('auralis_salas', 'idsala', 'nombre'));
        $this->addFilterSelectWhere($viewName, 'estado_envio', [
            ['label' => Tools::trans('calendar-notif-todas'), 'where' => []],
            ['label' => '------', 'where' => []],
            ['label' => Tools::trans('calendar-notif-pendiente'), 'where' => [Where::eq('sent', 0)]],
            ['label' => Tools::trans('calendar-notif-enviada'), 'where' => [Where::eq('sent', 1)]],
        ]);
    }
}

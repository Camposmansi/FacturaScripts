<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Listado central de eventos de calendario (consumidores verticales prefiltran por origen).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;

class ListAuralisCalendarEvent extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-calendar-events';
        $data['icon'] = 'fa-solid fa-list';
        $data['showonmenu'] = false;
        $data['ordernum'] = 40;

        return $data;
    }

    protected function createViews(): void
    {
        $viewName = 'ListAuralisCalendarEvent';
        $this->addView($viewName, 'AuralisCalendarEvent', 'auralis-calendar-events', 'fa-solid fa-list')
            ->addSearchFields(['titulo', 'descripcion', 'asignado', 'nick', 'origen_tipo'])
            ->addOrderBy(['fecha', 'hora_inicio'], 'date', 2)
            ->addOrderBy(['titulo'], 'title')
            ->addOrderBy(['id'], 'code');

        $this->setSettings($viewName, 'btnNew', false);

        $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');
        $this->addFilterSelect($viewName, 'origen_tipo', 'origen_tipo', 'origen_tipo', $this->codeModel->all('auralis_calendar_events', 'origen_tipo', 'origen_tipo'));
        $this->addFilterSelect($viewName, 'idsala', 'auralis-sala', 'idsala', $this->codeModel->all('auralis_salas', 'idsala', 'nombre'));
        $this->addFilterSelect($viewName, 'asignado', 'user', 'asignado', $this->codeModel->all('users', 'nick', 'nick'));
        $this->addFilterCheckbox($viewName, 'todo_dia', 'all-day', 'todo_dia');
        $this->addFilterCheckbox($viewName, 'se_repite', 'auralis-se-repite', 'se_repite');
    }

    /**
     * Borrar desde el listado exige «editar» en cada evento (calendario o página del módulo).
     */
    protected function execPreviousAction($action)
    {
        if ($action === 'delete') {
            $codigos = $this->request->request->getArray('codes');
            $codigos[] = (string)$this->request->input('code', '');
            if (false === CalendarioAcceso::puedeEditarEventosCentral($this->user, $codigos)) {
                Tools::log()->warning('access-denied');
                return true;
            }
        }

        return parent::execPreviousAction($action);
    }

    protected function loadData($viewName, $view)
    {
        $where = $this->permissions->onlyOwnerData ? $this->getOwnerFilter($view->model) : [];
        if ($viewName === 'ListAuralisCalendarEvent') {
            // Solo los eventos que el usuario puede ver (CalendarioAcceso; también los admins).
            $where = array_merge($where, CalendarioAcceso::whereEventosCentral($this->user));
        }

        $view->loadData('', $where);
    }
}

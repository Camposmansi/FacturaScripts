<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Edición central de un evento de calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisSecurity;

class EditAuralisCalendarEvent extends EditController
{
    public function getModelClassName(): string
    {
        return 'AuralisCalendarEvent';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-calendar-event';
        $data['icon'] = 'fa-solid fa-calendar-day';
        $data['showonmenu'] = false;

        return $data;
    }

    protected function createViews(): void
    {
        $viewName = 'EditAuralisCalendarEvent';
        $this->addEditView($viewName, $this->getModelClassName(), 'auralis-calendar-event', 'fa-solid fa-calendar-day');
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'btnPrint', false);
    }

    protected function loadData($viewName, $view): void
    {
        parent::loadData($viewName, $view);

        if ($viewName !== $this->getMainViewName()) {
            return;
        }

        if (false === $view->model->exists()) {
            $this->aplicarOrigenDesdeQuery($view->model);
        }

        if ($view->model->exists() && false === AuralisSecurity::canAccessEmpresa($this->user, $view->model->idempresa)) {
            Tools::log()->error('access-denied');
            $this->redirect('ListAuralisCalendarEvent');
        }

        // Ni ajenos ni «ocupado»: la ficha enseña todo el detalle (admins incluidos).
        if ($view->model->exists() && false === $this->puedeVerEnFicha($view->model)) {
            Tools::log()->error('access-denied');
            $view->model->clear();
            $this->hasData = false;
            $this->redirect('ListAuralisCalendarEvent');
        }
    }

    private function puedeVerEnFicha(AuralisCalendarEvent $evento): bool
    {
        $permiso = CalendarioAcceso::permisoEventoCentral($this->user, $evento);
        return $permiso === CalendarioAcceso::VER || $permiso === CalendarioAcceso::EDITAR;
    }

    /**
     * Las acciones (guardar, borrar, las de otros plugins) exigen «editar» sobre el evento.
     * En un alta se mira el origen que llega del formulario: el creador edita lo suyo y un
     * evento de módulo exige poder actualizar la página de su calendario.
     */
    private function puedeEjecutar(string $action): bool
    {
        $codigos = $this->request->request->getArray('codes');
        $codigos[] = (string)$this->request->inputOrQuery('code', '');
        if (false === CalendarioAcceso::puedeEditarEventosCentral($this->user, $codigos)) {
            return false;
        }

        if ($action !== 'insert') {
            return true;
        }

        $alta = new AuralisCalendarEvent();
        $alta->nick = (string)$this->user->nick;
        $alta->asignado = (string)$this->user->nick;
        $alta->origen_tipo = trim((string)$this->request->input('origen_tipo', '')) ?: CalendarioAcceso::ORIGEN_GENERAL;

        return CalendarioAcceso::permisoEventoCentral($this->user, $alta) === CalendarioAcceso::EDITAR;
    }

    /**
     * Prefija origen_tipo / origen_id / codcliente al crear desde un vertical (p. ej. EditMascota).
     */
    private function aplicarOrigenDesdeQuery(AuralisCalendarEvent $evento): void
    {
        $origenTipo = trim((string) $this->request->query('origen_tipo', ''));
        if ($origenTipo !== '' && empty($evento->origen_tipo)) {
            $evento->origen_tipo = $origenTipo;
        }

        $origenId = (int) $this->request->query('origen_id', 0);
        if ($origenId > 0 && empty($evento->origen_id)) {
            $evento->origen_id = $origenId;
        }

        $codcliente = trim((string) $this->request->query('codcliente', ''));
        if ($codcliente !== '' && empty($evento->codcliente)) {
            $evento->codcliente = $codcliente;
        }
    }

    protected function execPreviousAction($action = '')
    {
        if ($action !== '' && $action !== 'export' && false === $this->puedeEjecutar((string)$action)) {
            Tools::log()->error('access-denied');
            $this->redirect('ListAuralisCalendarEvent');
            return false;
        }

        if (in_array($action, ['edit', 'delete'], true)) {
            $code = $this->request->get('code') ?: $this->request->get('id');
            if ($code) {
                $event = new AuralisCalendarEvent();
                if ($event->load($code) && false === AuralisSecurity::canAccessEmpresa($this->user, $event->idempresa)) {
                    Tools::log()->error('access-denied');
                    $this->redirect('ListAuralisCalendarEvent');
                    return false;
                }
            }
        }

        return parent::execPreviousAction($action);
    }
}

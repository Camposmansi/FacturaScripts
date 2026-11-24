<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Ficha de un calendario común (con pestaña de accesos) o de módulo (sin accesos: lo ve quien tiene su página). Solo para gestores de comunes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarOrigins;

class EditAuralisCalendario extends EditController
{
    private const VISTA_ACCESOS = 'EditAuralisCalendarioAcceso';

    public function getModelClassName(): string
    {
        return 'AuralisCalendario';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-calendario-comun';
        $data['icon'] = 'fa-solid fa-calendar-week';
        $data['showonmenu'] = false;

        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        // Solo gestores de comunes (admin o roles configurados): el Core responde «acceso denegado».
        if (false === CalendarioAcceso::puedeGestionarComunes($user)) {
            $permissions->allowAccess = false;
        }

        parent::privateCore($response, $user, $permissions);
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');

        $this->addEditListView(self::VISTA_ACCESOS, 'AuralisCalendarioAcceso', 'auralis-calendario-accesos', 'fa-solid fa-user-lock')
            ->setInLine(true);
    }

    protected function loadData($viewName, $view)
    {
        $mainViewName = $this->mainTabName();

        if ($viewName === self::VISTA_ACCESOS) {
            $calendario = $this->mainTab()->model;
            // Personales y módulos no usan esta pestaña (los de módulo dependen de la página de su calendario).
            if (empty($calendario->idcalendario) || false === $calendario->esComun()) {
                $this->tab($viewName)->setSettings('active', false);
                return;
            }

            $view->loadData('', [Where::eq('idcalendario', (int)$calendario->idcalendario)], ['id' => 'ASC']);
            return;
        }

        parent::loadData($viewName, $view);

        if ($viewName !== $mainViewName) {
            return;
        }

        // Los personales se gestionan desde Mi calendario, no aquí.
        if ($view->model->exists() && $view->model->esPersonal()) {
            Tools::log()->warning('auralis-calendario-personal-no-gestionable');
            $view->model->clear();
            return;
        }

        $modulo = $view->model->esModulo() ? (CalendarOrigins::MODULOS[(string)$view->model->codigo] ?? null) : null;
        if ($modulo === null) {
            $view->disableColumn('auralis-calendario-modulo-info');
            $view->disableColumn('auralis-calendario-modulo-pagina');
            return;
        }

        // Módulo: código y empresa fijos, no se borra (se desactiva); aviso de quién lo ve.
        $view->model->pagina_modulo = $modulo['controlador'];
        $view->disableColumn('code', false, 'true');
        $view->disableColumn('company');
        $view->setSettings('btnDelete', false);
    }
}

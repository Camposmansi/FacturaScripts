<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Pestaña «Calendarios» en la ficha de rol: a qué calendarios comunes y personales tiene acceso el rol y con qué permiso. Solo para gestores de comunes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\Widget\WidgetSelect;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAccesoFicha;

class EditRole
{
    public function createViews(): Closure
    {
        return function (): void {
            if (false === CalendarioAccesoFicha::pestanaVisible($this->user)) {
                return;
            }

            $vista = CalendarioAccesoFicha::VISTA_ROL;
            $this->addEditListView($vista, 'AuralisCalendarioAcceso', 'auralis-calendarios', 'fa-solid fa-calendar-check')
                ->setInLine(true);

            $columna = $this->tab($vista)->columnForName('auralis-calendario');
            if ($columna !== null && $columna->widget instanceof WidgetSelect) {
                $opciones = CalendarioAccesoFicha::opcionesCalendario(CalendarioAccesoFicha::calendariosAsignables($this->user));
                $columna->widget->setValuesFromArray($opciones, false, false, 'value', 'title', 'group');
            }

            // Altas, cambios y bajas de esta pestaña las atiende la Lib (rol fijado por la ficha, auditoría):
            // se vacía la acción para que el Core no la repita sobre la pestaña.
            $accion = (string)$this->request->inputOrQuery('action', '');
            if ($this->active !== $vista || false === in_array($accion, CalendarioAccesoFicha::ACCIONES, true)) {
                return;
            }

            $this->request->request->set('action', '');
            if ($this->validateFormToken()) {
                $codrole = (string)$this->request->query('code', '');
                CalendarioAccesoFicha::atender($this->user, $this->request, $accion, CalendarioAccesoFicha::ALCANCE_ROL, $codrole);
            }
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view): void {
            if ($viewName !== CalendarioAccesoFicha::VISTA_ROL) {
                return;
            }

            $codrole = (string)$this->mainTabModelValue('codrole');
            $ids = array_keys(CalendarioAccesoFicha::calendariosAsignables($this->user));
            $where = CalendarioAccesoFicha::whereAccesos(CalendarioAccesoFicha::ALCANCE_ROL, $codrole, $ids);
            $view->loadData('', $where, ['id' => 'ASC']);
        };
    }
}

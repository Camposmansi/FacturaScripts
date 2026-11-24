<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Pestaña «Calendarios» en la ficha de usuario: sus accesos directos (editables) y, en el pie, con quién comparte sus personales y lo que le llega por grupo o empresa (solo lectura). Solo para gestores de comunes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\Widget\WidgetSelect;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAccesoFicha;

class EditUser
{
    public function createViews(): Closure
    {
        return function (): void {
            if (false === CalendarioAccesoFicha::pestanaVisible($this->user)) {
                return;
            }

            $nick = (string)$this->request->query('code', '');
            $destino = new User();
            $asignables = $destino->load($nick) ? CalendarioAccesoFicha::calendariosAsignables($this->user, $destino) : [];

            $vista = CalendarioAccesoFicha::VISTA_USUARIO;
            $this->addEditListView($vista, 'AuralisCalendarioAcceso', 'auralis-calendarios', 'fa-solid fa-calendar-check')
                ->setInLine(true);

            $columna = $this->tab($vista)->columnForName('auralis-calendario');
            if ($columna !== null && $columna->widget instanceof WidgetSelect) {
                $columna->widget->setValuesFromArray(CalendarioAccesoFicha::opcionesCalendario($asignables), false, false, 'value', 'title', 'group');
            }

            // Altas, cambios y bajas de la pestaña editable las atiende la Lib: el Core de EditUser solo
            // deja guardar el propio usuario. Se vacía la acción para que el Core no la repita.
            $accion = (string)$this->request->inputOrQuery('action', '');
            if ($this->active !== $vista || false === in_array($accion, CalendarioAccesoFicha::ACCIONES, true)) {
                return;
            }

            $this->request->request->set('action', '');
            if ($this->validateFormToken()) {
                CalendarioAccesoFicha::atender($this->user, $this->request, $accion, CalendarioAccesoFicha::ALCANCE_USUARIO, $nick);
            }
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view): void {
            if ($viewName !== CalendarioAccesoFicha::VISTA_USUARIO) {
                return;
            }

            $usuario = $this->mainTab()->model;
            $ids = array_keys(CalendarioAccesoFicha::calendariosAsignables($this->user, $usuario));
            $where = CalendarioAccesoFicha::whereAccesos(CalendarioAccesoFicha::ALCANCE_USUARIO, (string)$usuario->nick, $ids);
            $view->loadData('', $where, ['id' => 'ASC']);

            // Solo lectura en el pie de la pestaña (Block/CalendarioAccesoResumen).
            $view->setSettings(CalendarioAccesoFicha::AJUSTE_RESUMEN, CalendarioAccesoFicha::resumenUsuario($this->user, $usuario));
        };
    }
}

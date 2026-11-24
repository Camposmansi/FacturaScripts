<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Visibilidad calendario/salas: menú ListAuralisSala y pestaña ConfigCalendarioAuralis según consumidores activos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Dinamic\Model\Page;

class CalendarSalasMenu
{
    /**
     * Plugins que consumen calendario/salas (menú Salas + pestaña Configuración calendario).
     *
     * @var string[]
     */
    public const CONSUMIDORES = [
        'zSalud',
        'zTaller',
        'zVeterinaria',
    ];

    /**
     * Fija menú admin/auralis y showonmenu según haya algún consumidor activo.
     * Idempotente: si la página ya está correcta, no escribe en BD ni limpia caché.
     * Solo debe invocarse desde Auralis Init (init/update).
     */
    public static function sincronizar(): void
    {
        // Ocultar ListAuralisSala residual si existiera en la tabla pages
        $oldPage = new Page();
        if ($oldPage->load('ListAuralisSala') && $oldPage->showonmenu) {
            $oldPage->showonmenu = false;
            $oldPage->save();
        }

        $page = new Page();
        if (false === $page->load('ConfigAuralisServiciosCalendario')) {
            $page->name = 'ConfigAuralisServiciosCalendario';
            $page->title = 'auralis-calendario';
            $page->icon = 'fa-solid fa-calendar-days';
            $page->menu = 'admin';
            $page->submenu = 'auralis';
            $page->showonmenu = true;
            $page->ordernum = 30;
            $page->save();
            Cache::delete('model-Page-Show-Menu');
            Cache::clear();
            return;
        }

        $mostrar = true;
        $menuOk = $page->menu === 'admin'
            && $page->submenu === 'auralis'
            && (bool) $page->showonmenu === true
            && $page->title === 'auralis-calendario'
            && $page->icon === 'fa-solid fa-calendar-days'
            && (int) $page->ordernum === 30;

        if ($menuOk) {
            return;
        }

        $page->menu = 'admin';
        $page->submenu = 'auralis';
        $page->title = 'auralis-calendario';
        $page->icon = 'fa-solid fa-calendar-days';
        $page->showonmenu = true;
        $page->ordernum = 30;
        $page->save();
        Cache::delete('model-Page-Show-Menu');
        Cache::clear();
    }

    public static function hayConsumidorActivo(): bool
    {
        foreach (self::CONSUMIDORES as $nombre) {
            if (Plugins::isEnabled($nombre)) {
                return true;
            }
        }

        return false;
    }
}

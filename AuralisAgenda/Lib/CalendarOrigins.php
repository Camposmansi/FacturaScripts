<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Helper para resolver los ámbitos y verticales de calendario según los plugins activos en el sistema.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

final class CalendarOrigins
{
    public const ORIGIN_GENERAL = 'general';
    public const ORIGIN_SALUD = 'salud';
    public const ORIGIN_TALLER = 'ztaller';
    public const ORIGIN_VETERINARIA = 'zveterinaria';

    /**
     * Calendarios de módulo: origen_tipo real => plugin, página de su calendario, etiqueta y color inicial.
     * zSalud guarda sus eventos con 'zsalud' (no con ORIGIN_SALUD, deuda conocida del README).
     *
     * @var array<string, array{plugin: string, controlador: string, etiqueta: string, color: string}>
     */
    public const MODULOS = [
        self::ORIGIN_TALLER => [
            'plugin' => 'zTaller',
            'controlador' => 'CalendarioZTaller',
            'etiqueta' => 'calendar-origin-taller',
            'color' => '#f4511e',
        ],
        self::ORIGIN_VETERINARIA => [
            'plugin' => 'zVeterinaria',
            'controlador' => 'CalendarioZVeterinaria',
            'etiqueta' => 'calendar-origin-veterinaria',
            'color' => '#0b8043',
        ],
        'zsalud' => [
            'plugin' => 'zSalud',
            'controlador' => 'CalendarioZSalud',
            'etiqueta' => 'calendar-origin-salud',
            'color' => '#8e24aa',
        ],
    ];

    /**
     * Módulos con plugin activo (origen_tipo => datos de MODULOS).
     *
     * @return array<string, array{plugin: string, controlador: string, etiqueta: string, color: string}>
     */
    public static function modulosActivos(): array
    {
        $activos = [];
        foreach (self::MODULOS as $codigo => $modulo) {
            if (Plugins::isEnabled($modulo['plugin'])) {
                $activos[$codigo] = $modulo;
            }
        }

        return $activos;
    }

    /**
     * Devuelve las opciones para selectores XML (WidgetSelect) basadas en los plugins activos.
     *
     * @return array<int, array{value: string, title: string}>
     */
    public static function getSelectOptions(): array
    {
        $options = [
            [
                'value' => self::ORIGIN_GENERAL,
                'title' => Tools::trans('calendar-origin-general'),
            ],
        ];

        if (Plugins::isEnabled('zSalud')) {
            $options[] = [
                'value' => self::ORIGIN_SALUD,
                'title' => Tools::trans('calendar-origin-salud'),
            ];
        }

        if (Plugins::isEnabled('zTaller')) {
            $options[] = [
                'value' => self::ORIGIN_TALLER,
                'title' => Tools::trans('calendar-origin-taller'),
            ];
        }

        if (Plugins::isEnabled('zVeterinaria')) {
            $options[] = [
                'value' => self::ORIGIN_VETERINARIA,
                'title' => Tools::trans('calendar-origin-veterinaria'),
            ];
        }

        return $options;
    }

    /**
     * Mapa clave => etiqueta para filtros y columnas de listados.
     *
     * @return array<string, string>
     */
    public static function getFilterOptions(): array
    {
        $map = [
            self::ORIGIN_GENERAL => Tools::trans('calendar-origin-general'),
        ];

        if (Plugins::isEnabled('zSalud')) {
            $map[self::ORIGIN_SALUD] = Tools::trans('calendar-origin-salud');
        }

        if (Plugins::isEnabled('zTaller')) {
            $map[self::ORIGIN_TALLER] = Tools::trans('calendar-origin-taller');
        }

        if (Plugins::isEnabled('zVeterinaria')) {
            $map[self::ORIGIN_VETERINARIA] = Tools::trans('calendar-origin-veterinaria');
        }

        return $map;
    }
}

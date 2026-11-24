<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Lee los filtros activos de un listado del Core con los nombres que espera el tablero Kanban.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

/**
 * Soporte de la extensión de listado: el Core invoca sin argumentos TODOS los métodos
 * de una clase de extensión (ExtensionsTrait::addExtension), así que los helpers viven aquí.
 */
final class KanbanFiltrosListado
{
    /**
     * Filtros del listado (clave del filtro) que se trasladan al tablero con el mismo nombre.
     * Lo que el tablero no admite lo descarta KanbanAcceso::filtros() (p. ej. codagente en compras).
     */
    private const FILTROS_DIRECTOS = ['idempresa', 'codserie', 'codcliente', 'codproveedor', 'codagente'];

    /**
     * Lee los valores actuales de los filtros del listado (ya cargados por el Core)
     * con los nombres que espera el tablero. Los filtros que no existen se omiten.
     *
     * @param object $view vista del listado
     * @return array<string, string>
     */
    public static function deLaVista($view): array
    {
        $filtros = is_array($view->filters ?? null) ? $view->filters : [];
        $entrada = [];

        foreach (self::FILTROS_DIRECTOS as $clave) {
            if (isset($filtros[$clave]) && is_scalar($filtros[$clave]->getValue())) {
                $entrada[$clave] = (string) $filtros[$clave]->getValue();
            }
        }

        // Filtro de periodo: sus fechas desde/hasta. Un periodo predefinido llega en d-m-Y
        // (PeriodTools::DATE_FORMAT) y el tablero solo acepta Y-m-d.
        if (isset($filtros['date']) && method_exists($filtros['date'], 'getValue')) {
            $desde = self::fechaIso($filtros['date']->getValue('start'));
            $hasta = self::fechaIso($filtros['date']->getValue('end'));
            if ($desde !== '') {
                $entrada['desde'] = $desde;
            }
            if ($hasta !== '') {
                $entrada['hasta'] = $hasta;
            }
        }

        return $entrada;
    }

    /** Fecha en Y-m-d desde d-m-Y o Y-m-d; cadena vacía si no es una fecha válida. */
    public static function fechaIso($valor): string
    {
        if (false === is_string($valor)) {
            return '';
        }

        $valor = trim($valor);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $valor, $partes) === 1) {
            return checkdate((int) $partes[2], (int) $partes[1], (int) $partes[3])
                ? $partes[3] . '-' . $partes[2] . '-' . $partes[1]
                : '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 ? $valor : '';
    }
}

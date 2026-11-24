<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Rejilla mensual del calendario: celdas de relleno Lun→Dom (SSOT).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

/**
 * Alineación de la vista mensual cuando la cabecera Twig es fija lunes→domingo.
 */
final class CalendarMonthGrid
{
    /**
     * Antepone celdas is_filler si el rango es un mes completo y el día 1 no es lunes.
     *
     * @param array<int, array<string, mixed>> $days
     * @return array<int, array<string, mixed>>
     */
    public static function prependFillers(array $days, string $desde, string $hasta): array
    {
        if ($days === []) {
            return $days;
        }

        try {
            $start = new \DateTime($desde);
            $end = new \DateTime($hasta);
        } catch (\Throwable) {
            return $days;
        }

        if ($start->format('d') !== '01' || $end->format('Y-m-d') !== $start->format('Y-m-t')) {
            return $days;
        }

        $relleno = (int)$start->format('N') - 1;
        if ($relleno <= 0) {
            return $days;
        }

        $vacio = [
            'day_name' => '',
            'day_num' => '',
            'label' => '',
            'date' => '',
            'is_today' => false,
            'is_filler' => true,
            'net_amount' => '',
            'net_class' => '',
            'cards' => [],
            'allday_cards' => [],
            'timed_cards' => [],
        ];

        for ($i = 0; $i < $relleno; $i++) {
            array_unshift($days, $vacio);
        }

        return $days;
    }
}

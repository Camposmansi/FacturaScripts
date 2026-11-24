<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Filtra pares factura ↔ rectificativa sin recibos que se compensan a cero en la alerta de descuadre.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

final class ReceiptsDiffFilter
{
    /** Tolerancia monetaria alineada con el Core (abs(diff) <= 0.01 = cuadrado). */
    public const TOLERANCE = 0.01;

    /**
     * Elimina del listado los pares vinculados por idfacturarect cuando ambos
     * no tienen recibos y sus totales se anulan entre sí.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function filterCompensatedRectificativePairs(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $byId = [];
        foreach ($items as $item) {
            $id = (int) ($item['idfactura'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $item;
            }
        }

        $exclude = [];
        foreach ($items as $item) {
            $idRect = (int) ($item['idfactura'] ?? 0);
            $idOriginal = (int) ($item['idfacturarect'] ?? 0);
            if ($idRect <= 0 || $idOriginal <= 0 || !isset($byId[$idOriginal])) {
                continue;
            }
            if (isset($exclude[$idRect]) || isset($exclude[$idOriginal])) {
                continue;
            }

            $original = $byId[$idOriginal];
            $sumaRect = (float) ($item['suma_recibos'] ?? 0);
            $sumaOrig = (float) ($original['suma_recibos'] ?? 0);
            if (abs($sumaRect) > self::TOLERANCE || abs($sumaOrig) > self::TOLERANCE) {
                continue;
            }

            $totalRect = (float) ($item['total'] ?? 0);
            $totalOrig = (float) ($original['total'] ?? 0);
            if (abs($totalOrig + $totalRect) > self::TOLERANCE) {
                continue;
            }

            $exclude[$idRect] = true;
            $exclude[$idOriginal] = true;
        }

        if ($exclude === []) {
            return array_values($items);
        }

        $filtered = [];
        foreach ($items as $item) {
            $id = (int) ($item['idfactura'] ?? 0);
            if (!isset($exclude[$id])) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }
}

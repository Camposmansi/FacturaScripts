<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Hidrata % cobrado/por cobrar (ventas) o % pagado/por pagar (compras) según el importe de los recibos pagados (batch; incluye rectificativas).
 */

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\ReciboCliente;
use FacturaScripts\Dinamic\Model\ReciboProveedor;

final class RecibosFacturaPctStatus
{
    /**
     * @param object $view
     */
    public static function hydrateView($view): void
    {
        self::hydrate(
            $view,
            FacturaCliente::class,
            ReciboCliente::class,
            'zk_pct_cobrado',
            'zk_pct_cobrado_tone'
        );
    }

    /**
     * @param object $view
     */
    public static function hydrateProveedorView($view): void
    {
        self::hydrate(
            $view,
            FacturaProveedor::class,
            ReciboProveedor::class,
            'zk_pct_pagado',
            'zk_pct_pagado_tone'
        );
    }

    /**
     * @param object $view
     * @param class-string $facturaClass
     * @param class-string $reciboClass
     */
    private static function hydrate(
        $view,
        string $facturaClass,
        string $reciboClass,
        string $pctField,
        string $toneField
    ): void {
        if (empty($view) || empty($view->cursor) || !is_array($view->cursor)) {
            return;
        }

        $ids = [];
        /** @var array<int, object> $porId */
        $porId = [];

        foreach ($view->cursor as $model) {
            self::limpiarProps($model, $pctField, $toneField);
            if (false === ($model instanceof $facturaClass)) {
                continue;
            }

            $id = (int) $model->primaryColumnValue();
            if ($id <= 0) {
                continue;
            }

            $ids[$id] = $id;
            $porId[$id] = $model;
        }

        if ($ids === []) {
            return;
        }

        // Como el núcleo (ReceiptGenerator): lo cobrado/pagado es la suma del importe de los recibos pagados.
        $where = [Where::in('idfactura', array_values($ids)), Where::eq('pagado', true)];
        $pagadoPorFactura = [];
        foreach ($reciboClass::all($where, [], 0, 0) as $recibo) {
            $idFac = (int) $recibo->idfactura;
            if ($idFac <= 0) {
                continue;
            }
            $pagadoPorFactura[$idFac] = ($pagadoPorFactura[$idFac] ?? 0.0) + (float) $recibo->importe;
        }

        foreach ($porId as $id => $model) {
            $total = (float) $model->total;
            $absTotal = abs($total);
            $pagado = round($pagadoPorFactura[$id] ?? 0.0, 4);
            $absPagado = abs($pagado);

            if (!empty($model->pagada)) {
                $absPagado = $absTotal;
            }

            if ($absTotal < 0.01 && $absPagado < 0.01) {
                continue;
            }

            $pct = $absTotal > 0.01
                ? min(100.0, round(($absPagado / $absTotal) * 100, 1))
                : ($absPagado >= 0.01 ? 100.0 : 0.0);

            // «100,0» y «0,0» solo cuando son exactos: el redondeo no puede dar por cobrada/sin cobrar una factura que no lo está.
            if (empty($model->pagada) && $pct >= 100.0) {
                $pct = 99.9;
            }
            if ($absPagado > 0.0 && $pct < 0.1) {
                $pct = 0.1;
            }

            $pctPendiente = max(0.0, round(100.0 - $pct, 1));
            $tone = self::toneDesdePct($pct);

            $model->{$pctField} = $pct;
            $model->{$toneField} = $tone;
            $model->zk_pct_pendiente = $pctPendiente;
            $model->zk_pct_pendiente_tone = $tone;
        }
    }

    /**
     * @param object $model
     */
    private static function limpiarProps($model, string $pctField, string $toneField): void
    {
        $model->{$pctField} = null;
        $model->{$toneField} = null;
        $model->zk_pct_pendiente = null;
        $model->zk_pct_pendiente_tone = null;
    }

    private static function toneDesdePct(float $pct): string
    {
        if ($pct >= 100.0) {
            return 'ok';
        }
        if ($pct <= 0.0) {
            return 'danger';
        }

        return 'warning';
    }
}

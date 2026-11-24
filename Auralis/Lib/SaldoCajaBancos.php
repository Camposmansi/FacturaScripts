<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Saldo real de caja y bancos (subcuentas 57) sumando partidas sin asientos de apertura ni de cierre.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use Throwable;

/**
 * Calcula el saldo de tesorería (grupo 57) a partir de las partidas contables.
 *
 * No usa subcuentas.saldo porque esa columna es por ejercicio: al sumar varios ejercicios,
 * el saldo arrastrado por el asiento de apertura se contaría dos veces. Al excluir los
 * asientos de apertura ('A') y de cierre ('C'), cada movimiento real cuenta una sola vez,
 * se cierren o no los ejercicios. La regularización ('R') no se excluye: no toca el grupo 57.
 * Sin filtro de fecha, igual que el saldo de subcuenta.
 */
final class SaldoCajaBancos
{
    /** Prefijo de las subcuentas de tesorería (caja y bancos) del PGC. */
    public const PREFIJO_SUBCUENTA = '57';

    /**
     * Devuelve el saldo (debe - haber) de las subcuentas 57.
     *
     * @param int|null $idempresa empresa del asiento; null o 0 suma todas las empresas.
     */
    public static function calcular(?int $idempresa = null): float
    {
        try {
            $db = new DataBase();
            if (false === $db->tableExists('partidas') || false === $db->tableExists('asientos')) {
                return 0.0;
            }

            $fila = self::consulta($idempresa)
                ->selectRaw('SUM(partidas.debe) AS debe, SUM(partidas.haber) AS haber')
                ->first();

            return round((float)($fila['debe'] ?? 0) - (float)($fila['haber'] ?? 0), 2);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('saldo-caja-bancos: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Consulta base (partidas 57 de asientos que no son de apertura ni de cierre), sin SELECT.
     * Pública para diagnóstico y tests; el llamador decide qué columnas seleccionar.
     *
     * @param int|null $idempresa empresa del asiento; null o 0 no filtra.
     */
    public static function consulta(?int $idempresa = null): DbQuery
    {
        $query = DbQuery::table('partidas')
            ->join('asientos', 'partidas.idasiento', 'asientos.idasiento')
            ->whereLike('partidas.codsubcuenta', self::PREFIJO_SUBCUENTA . '%')
            ->where([
                // operacion es NULL en los asientos generales: NOT IN a secas los descartaría
                Where::sub([
                    Where::isNull('asientos.operacion'),
                    Where::orNotIn('asientos.operacion', [Asiento::OPERATION_OPENING, Asiento::OPERATION_CLOSING]),
                ]),
            ]);

        if (!empty($idempresa)) {
            $query->whereEq('asientos.idempresa', $idempresa);
        }

        return $query;
    }
}

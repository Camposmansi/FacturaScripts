<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Resumen CSV del paquete para la gestoría (separador «;», BOM UTF-8 y celdas protegidas contra fórmulas).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

use FacturaScripts\Core\Tools;

final class GestoriaResumenCsv
{
    public const BOM = "\xEF\xBB\xBF";
    public const SEPARADOR = ';';

    /** Prefijos que una hoja de cálculo interpretaría como fórmula. */
    private const PREFIJOS_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Filas acumuladas del resumen.
     *
     * @var list<array{fecha: string, tipo: string, codigo: string, numproveedor: string, cifnif: string, nombre: string, neto: float, iva: float, total: float, fichero: string, aviso: string}>
     */
    private array $filas = [];

    /**
     * @param array{fecha: string, tipo: string, codigo: string, numproveedor: string, cifnif: string, nombre: string, neto: float, iva: float, total: float, fichero: string, aviso: string} $fila
     */
    public function anadir(array $fila): void
    {
        $this->filas[] = $fila;
    }

    public function contarFilas(): int
    {
        return count($this->filas);
    }

    /**
     * Contenido completo del CSV con BOM, cabecera y filas.
     */
    public function generar(): string
    {
        $flujo = fopen('php://temp', 'r+');
        if (false === $flujo) {
            return self::BOM;
        }

        fputcsv($flujo, $this->cabecera(), self::SEPARADOR, '"', '');
        foreach ($this->filas as $fila) {
            fputcsv($flujo, [
                self::celda($fila['fecha']),
                self::celda($fila['tipo']),
                self::celda($fila['codigo']),
                self::celda($fila['numproveedor']),
                self::celda($fila['cifnif']),
                self::celda($fila['nombre']),
                self::importe($fila['neto']),
                self::importe($fila['iva']),
                self::importe($fila['total']),
                self::celda($fila['fichero']),
                self::celda($fila['aviso']),
            ], self::SEPARADOR, '"', '');
        }

        rewind($flujo);
        $contenido = (string)stream_get_contents($flujo);
        fclose($flujo);

        return self::BOM . $contenido;
    }

    /**
     * Texto de celda: si empieza por un carácter de fórmula se prefija con comilla simple.
     */
    public static function celda(string $valor): string
    {
        if ($valor !== '' && in_array(mb_substr($valor, 0, 1), self::PREFIJOS_FORMULA, true)) {
            return "'" . $valor;
        }

        return $valor;
    }

    /**
     * Importe con dos decimales, coma decimal y sin separador de miles (lo que espera una hoja en español).
     */
    public static function importe(float $valor): string
    {
        $redondeado = round($valor, 2);
        if (abs($redondeado) < 0.005) {
            $redondeado = 0.0;
        }

        return number_format($redondeado, 2, ',', '');
    }

    /**
     * @return list<string>
     */
    private function cabecera(): array
    {
        return [
            Tools::trans('date'),
            Tools::trans('auralis-gestoria-csv-tipo'),
            Tools::trans('code'),
            Tools::trans('auralis-gestoria-csv-numproveedor'),
            Tools::trans('cifnif'),
            Tools::trans('name'),
            Tools::trans('auralis-gestoria-csv-base'),
            Tools::trans('auralis-gestoria-csv-iva'),
            Tools::trans('total'),
            Tools::trans('auralis-gestoria-csv-fichero'),
            Tools::trans('auralis-gestoria-csv-aviso'),
        ];
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Criterios saneados del informe «Documentos para la gestoría» (periodo, tipos, filtros y modo de compras).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

use DateTimeImmutable;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\AlbaranProveedor;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoProveedor;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;

final class GestoriaCriterios
{
    public const AMBITO_VENTAS = 'ventas';
    public const AMBITO_COMPRAS = 'compras';

    public const MODO_PDF = 'pdf';
    public const MODO_ADJUNTOS = 'adjuntos';
    public const MODO_ADJUNTOS_PDF = 'adjuntos_pdf';

    public const MODOS_COMPRAS = [self::MODO_PDF, self::MODO_ADJUNTOS, self::MODO_ADJUNTOS_PDF];

    public const PAGO_TODAS = 'todas';
    public const PAGO_PAGADAS = 'pagadas';
    public const PAGO_PENDIENTES = 'pendientes';

    public const ESTADOS_PAGO = [self::PAGO_TODAS, self::PAGO_PAGADAS, self::PAGO_PENDIENTES];

    /** Rango máximo del periodo (un año natural, bisiesto incluido). */
    public const MAX_DIAS_PERIODO = 366;

    public const ATAJOS = ['mes_actual', 'mes_anterior', 't1', 't2', 't3', 't4', 'anio'];

    /**
     * Tipos de documento permitidos por ámbito: clave corta → clase, página Edit y clave i18n.
     *
     * @var array<string, array<string, array{clase: class-string, edit: string, etiqueta: string}>>
     */
    public const TIPOS = [
        self::AMBITO_VENTAS => [
            'facturas' => ['clase' => FacturaCliente::class, 'edit' => 'EditFacturaCliente', 'etiqueta' => 'customer-invoices'],
            'albaranes' => ['clase' => AlbaranCliente::class, 'edit' => 'EditAlbaranCliente', 'etiqueta' => 'customer-delivery-notes'],
            'pedidos' => ['clase' => PedidoCliente::class, 'edit' => 'EditPedidoCliente', 'etiqueta' => 'customer-orders'],
            'presupuestos' => ['clase' => PresupuestoCliente::class, 'edit' => 'EditPresupuestoCliente', 'etiqueta' => 'customer-estimations'],
        ],
        self::AMBITO_COMPRAS => [
            'facturas' => ['clase' => FacturaProveedor::class, 'edit' => 'EditFacturaProveedor', 'etiqueta' => 'supplier-invoices'],
            'albaranes' => ['clase' => AlbaranProveedor::class, 'edit' => 'EditAlbaranProveedor', 'etiqueta' => 'supplier-delivery-notes'],
            'pedidos' => ['clase' => PedidoProveedor::class, 'edit' => 'EditPedidoProveedor', 'etiqueta' => 'supplier-orders'],
            'presupuestos' => ['clase' => PresupuestoProveedor::class, 'edit' => 'EditPresupuestoProveedor', 'etiqueta' => 'supplier-estimations'],
        ],
    ];

    public string $desde = '';
    public string $hasta = '';

    /** Cadena vacía = todas las empresas visibles para el usuario. */
    public string $codEmpresa = '';

    public bool $incluirVentas = false;

    /** @var list<string> */
    public array $tiposVentas = [];

    public string $codcliente = '';
    public string $codserie = '';

    public bool $incluirCompras = false;

    /** @var list<string> */
    public array $tiposCompras = [];

    public string $codproveedor = '';
    public string $modoCompras = self::MODO_PDF;
    public string $estadoPago = self::PAGO_TODAS;
    public bool $incluirCsv = true;

    /** Clave i18n del primer error de validación; vacía si los criterios son válidos. */
    public string $error = '';

    /**
     * Construye los criterios desde la entrada del formulario, con allowlists y casteos.
     *
     * @param array<string, mixed> $datos
     */
    public static function desdeEntrada(array $datos, ?object $usuario): self
    {
        $criterios = new self();

        $desdeBruto = (string)($datos['desde'] ?? '');
        $hastaBruto = (string)($datos['hasta'] ?? '');
        if (null === AuralisSecurity::sanitizeIsoDate($desdeBruto) || null === AuralisSecurity::sanitizeIsoDate($hastaBruto)) {
            $criterios->error = 'auralis-gestoria-error-fechas';
        }
        [$criterios->desde, $criterios->hasta] = AuralisSecurity::sanitizeDateRange(
            $desdeBruto,
            $hastaBruto,
            self::MAX_DIAS_PERIODO
        );

        $criterios->codEmpresa = AuralisSecurity::resolveCodEmpresa($usuario, (string)($datos['idempresa'] ?? ''));

        $criterios->incluirVentas = self::casilla($datos['incluir_ventas'] ?? '');
        $criterios->tiposVentas = self::filtrarTipos(self::AMBITO_VENTAS, $datos['tipos_ventas'] ?? []);
        $criterios->codcliente = self::codigo($datos['codcliente'] ?? '');
        $criterios->codserie = self::codigo($datos['codserie'] ?? '');

        $criterios->incluirCompras = self::casilla($datos['incluir_compras'] ?? '');
        $criterios->tiposCompras = self::filtrarTipos(self::AMBITO_COMPRAS, $datos['tipos_compras'] ?? []);
        $criterios->codproveedor = self::codigo($datos['codproveedor'] ?? '');

        $modo = (string)($datos['modo_compras'] ?? '');
        $criterios->modoCompras = in_array($modo, self::MODOS_COMPRAS, true) ? $modo : self::MODO_PDF;

        $estado = (string)($datos['estado_pago'] ?? '');
        $criterios->estadoPago = in_array($estado, self::ESTADOS_PAGO, true) ? $estado : self::PAGO_TODAS;

        $criterios->incluirCsv = self::casilla($datos['incluir_csv'] ?? '');

        if ($criterios->error === '' && false === $criterios->hayAlgoQueExportar()) {
            $criterios->error = 'auralis-gestoria-error-sin-tipos';
        }

        return $criterios;
    }

    /**
     * Tipos activos de un ámbito (vacío si el ámbito no está incluido).
     *
     * @return list<string>
     */
    public function tiposDe(string $ambito): array
    {
        if ($ambito === self::AMBITO_VENTAS) {
            return $this->incluirVentas ? $this->tiposVentas : [];
        }
        if ($ambito === self::AMBITO_COMPRAS) {
            return $this->incluirCompras ? $this->tiposCompras : [];
        }

        return [];
    }

    public function hayAlgoQueExportar(): bool
    {
        return $this->tiposDe(self::AMBITO_VENTAS) !== [] || $this->tiposDe(self::AMBITO_COMPRAS) !== [];
    }

    /**
     * Rango [desde, hasta] de un atajo de periodo respecto a la fecha indicada.
     *
     * @return array{0: string, 1: string}
     */
    public static function rangoAtajo(string $atajo, DateTimeImmutable $hoy): array
    {
        $anio = (int)$hoy->format('Y');
        switch ($atajo) {
            case 'mes_anterior':
                $inicio = $hoy->modify('first day of previous month');
                return [$inicio->format('Y-m-01'), $inicio->format('Y-m-t')];

            case 't1':
            case 't2':
            case 't3':
            case 't4':
                $mesInicio = ((int)mb_substr($atajo, 1) - 1) * 3 + 1;
                $inicio = $hoy->setDate($anio, $mesInicio, 1);
                $fin = $hoy->setDate($anio, $mesInicio + 2, 1);
                return [$inicio->format('Y-m-d'), $fin->format('Y-m-t')];

            case 'anio':
                return [sprintf('%04d-01-01', $anio), sprintf('%04d-12-31', $anio)];

            default:
                return [$hoy->format('Y-m-01'), $hoy->format('Y-m-t')];
        }
    }

    /**
     * Todos los atajos con su rango, para pintarlos en la pantalla.
     *
     * @return array<string, array{desde: string, hasta: string}>
     */
    public static function atajos(DateTimeImmutable $hoy): array
    {
        $lista = [];
        foreach (self::ATAJOS as $atajo) {
            [$desde, $hasta] = self::rangoAtajo($atajo, $hoy);
            $lista[$atajo] = ['desde' => $desde, 'hasta' => $hasta];
        }

        return $lista;
    }

    /**
     * @param mixed $valor
     */
    private static function casilla($valor): bool
    {
        return in_array((string)$valor, ['1', 'true', 'on'], true);
    }

    /**
     * Código de cliente/proveedor/serie: sin HTML, sin espacios laterales y acotado.
     *
     * @param mixed $valor
     */
    private static function codigo($valor): string
    {
        if (false === is_scalar($valor)) {
            return '';
        }

        $limpio = trim(strip_tags((string)$valor));

        return mb_substr($limpio, 0, 20);
    }

    /**
     * @param mixed $valores
     *
     * @return list<string>
     */
    private static function filtrarTipos(string $ambito, $valores): array
    {
        if (is_string($valores)) {
            $valores = $valores === '' ? [] : explode(',', $valores);
        }
        if (false === is_array($valores)) {
            return [];
        }

        $permitidos = array_keys(self::TIPOS[$ambito]);
        $resultado = [];
        foreach ($permitidos as $tipo) {
            foreach ($valores as $valor) {
                if (is_scalar($valor) && (string)$valor === $tipo) {
                    $resultado[] = $tipo;
                    break;
                }
            }
        }

        return $resultado;
    }
}

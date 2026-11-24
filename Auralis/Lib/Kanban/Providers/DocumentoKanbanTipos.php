<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Tabla única de los 4 tableros Kanban de documentos del Core (presupuestos y pedidos de cliente y de proveedor): clave, modelo, ajuste, títulos y lo que cambia entre tipos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban\Providers;

use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PedidoProveedor;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoProveedor;

/**
 * Sin BD: solo constantes. Lo derivable no se repite aquí: tabla y clave primaria salen del
 * modelo, y las páginas del Core son «List» y «Edit» + nombre del modelo.
 *
 * Solo presupuestos y pedidos, de cliente y de proveedor: albaranes y facturas no tienen tablero
 * (el proveedor DocumentoKanbanProvider no tiene reglas de facturas). Un tablero de otro tipo lo
 * registra su plugin con su propio proveedor (KanbanRegistry::register).
 *
 * Campos de cada tablero:
 * - modelo: clase Dinamic del documento.
 * - ajuste: clave del ajuste on/off (grupo auralis_avisos). Presupuestos de cliente conserva kanban_presupuestos.
 * - titulo / etiquetaAjuste: claves de traducción del tablero y de su casilla en Ajustes → Listados.
 * - ventas: true = cliente, agente y numero2; false = proveedor, nombre y numproveedor (sin agente).
 * - fechaLimite: campo de fecha límite que pinta la tarjeta y se edita en el detalle, o null.
 * - documento: clave del Core para nombrar el documento en los avisos.
 */
final class DocumentoKanbanTipos
{
    public const DEFINICIONES = [
        'presupuestos-cliente' => [
            'modelo' => PresupuestoCliente::class,
            'ajuste' => 'kanban_presupuestos',
            'titulo' => 'auralis-kanban-titulo-presupuestos',
            'etiquetaAjuste' => 'auralis-kanban-ajuste-presupuestos-cliente',
            'ventas' => true,
            'fechaLimite' => 'finoferta',
            'documento' => 'customer-estimation',
        ],
        'pedidos-cliente' => [
            'modelo' => PedidoCliente::class,
            'ajuste' => 'kanban_pedidos_cliente',
            'titulo' => 'auralis-kanban-titulo-pedidos-cliente',
            'etiquetaAjuste' => 'auralis-kanban-ajuste-pedidos-cliente',
            'ventas' => true,
            'fechaLimite' => 'finoferta',
            'documento' => 'customer-order',
        ],
        // los documentos de compra del Core no tienen fecha límite (finoferta solo existe en ventas)
        'presupuestos-proveedor' => [
            'modelo' => PresupuestoProveedor::class,
            'ajuste' => 'kanban_presupuestos_proveedor',
            'titulo' => 'auralis-kanban-titulo-presupuestos-proveedor',
            'etiquetaAjuste' => 'auralis-kanban-ajuste-presupuestos-proveedor',
            'ventas' => false,
            'fechaLimite' => null,
            'documento' => 'supplier-estimation',
        ],
        'pedidos-proveedor' => [
            'modelo' => PedidoProveedor::class,
            'ajuste' => 'kanban_pedidos_proveedor',
            'titulo' => 'auralis-kanban-titulo-pedidos-proveedor',
            'etiquetaAjuste' => 'auralis-kanban-ajuste-pedidos-proveedor',
            'ventas' => false,
            'fechaLimite' => null,
            'documento' => 'supplier-order',
        ],
    ];

    /** @return string[] claves de tablero, en el orden de la tabla */
    public static function claves(): array
    {
        return array_keys(self::DEFINICIONES);
    }

    /** @return array<string, mixed>|null */
    public static function definicion(string $tablero): ?array
    {
        return self::DEFINICIONES[$tablero] ?? null;
    }

    /** Nombre corto del modelo (tipodoc de estados_documentos y model1 de doctransformations). */
    public static function tipoDoc(string $tablero): string
    {
        $clase = (string) (self::DEFINICIONES[$tablero]['modelo'] ?? '');
        $posicion = strrpos($clase, '\\');

        return $posicion === false ? $clase : substr($clase, $posicion + 1);
    }

    /** Tablero cuyo listado del Core es $listado (p. ej. ListPedidoCliente), o null. */
    public static function tableroDeListado(string $listado): ?string
    {
        foreach (self::claves() as $tablero) {
            if ('List' . self::tipoDoc($tablero) === $listado) {
                return $tablero;
            }
        }

        return null;
    }

    /** Clave de traducción del documento de un tipodoc (para «se generará un …»), o null. */
    public static function claveDocumento(string $tipoDoc): ?string
    {
        foreach (self::claves() as $tablero) {
            if (self::tipoDoc($tablero) === $tipoDoc) {
                return self::DEFINICIONES[$tablero]['documento'];
            }
        }

        return null;
    }
}

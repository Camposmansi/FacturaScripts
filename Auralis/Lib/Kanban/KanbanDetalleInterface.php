<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Capacidad opcional de un tablero Kanban: detalle de la tarjeta en un modal (lectura, guardado y repintado) con la carcasa de Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Dinamic\Model\User;

/**
 * Los proveedores que no la implementan abren siempre la ficha (modo 'page').
 * El formato del detalle y su saneado están en KanbanDetalle.
 */
interface KanbanDetalleInterface extends KanbanProviderInterface
{
    public const DETALLE_PAGINA = 'page';
    public const DETALLE_MODAL = 'modal';

    /** Modo si nadie lo pide: 'page' (navegar a la ficha) o 'modal'. */
    public function modoDetallePorDefecto(): string;

    /**
     * Detalle de una tarjeta (formato de KanbanDetalle::normalizar()) o null si no existe
     * o el usuario no puede verla. El proveedor aplica readPermission() + «solo sus datos»
     * + empresa, igual que en cards().
     *
     * @return array<string, mixed>|null
     */
    public function detalle(string $id, User $user): ?array;

    /**
     * Guarda los campos editables. $valores llega YA filtrado por Auralis con la lista
     * de campos editables que devolvió detalle(). El proveedor aplica editPermission()
     * (allowUpdate) + «solo sus datos» + empresa y guarda con save() del modelo.
     * ok ⇒ tarjeta recargada (con 'column'); error ⇒ mensaje para el usuario.
     *
     * @param array<string, mixed> $valores
     */
    public function guardarDetalle(string $id, array $valores, User $user): KanbanResultadoMover;

    /**
     * Una tarjeta (formato de cardData() + 'column') para repintar, o null si no se puede ver.
     *
     * @return array<string, mixed>|null
     */
    public function tarjeta(string $id, User $user): ?array;

    /**
     * Assets propios del detalle que la página del tablero carga con ?v=auralisAssetVersion().
     *
     * @return array{js?: string[], css?: string[]} rutas 'Dinamic/Assets/JS/x.js' / 'Dinamic/Assets/CSS/x.css'
     */
    public function detalleAssets(): array;
}

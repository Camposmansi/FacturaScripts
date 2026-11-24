<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Contrato de un proveedor de tablero Kanban: columnas, tarjetas paginadas, recuentos y movimiento entre columnas.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Dinamic\Model\User;

interface KanbanProviderInterface
{
    /** Slug único del tablero ([a-z0-9-]), p. ej. 'presupuestos-cliente'. */
    public function key(): string;

    /** Título visible, ya traducido. */
    public function title(): string;

    /** Página del Core cuyo permiso se exige para VER (allowAccess + onlyOwnerData). P. ej. 'ListPresupuestoCliente'. */
    public function readPermission(): string;

    /** Página del Core cuyo permiso se exige para MOVER (allowUpdate + onlyOwnerData). P. ej. 'EditPresupuestoCliente'. */
    public function editPermission(): string;

    /** Controlador del listado (para el conmutador «Lista»), p. ej. 'ListPresupuestoCliente'. */
    public function listUrl(): string;

    /**
     * Filtros admitidos por querystring/POST: nombre => tipo ('int' | 'string' | 'date').
     * Lo que no esté aquí se descarta. Presupuestos: idempresa:int, codserie:string,
     * codcliente:string, codagente:string, desde:date, hasta:date.
     * @return array<string, string>
     */
    public function allowedFilters(): array;

    /**
     * Columnas en orden de pantalla.
     * @return array<int, array{id: string, title: string, color: string, icon: string,
     *   confirm: bool, allowDrop: bool, active: bool, generatesDoc?: bool}>
     */
    public function columns(User $user): array;

    /**
     * Una página de tarjetas de una columna, ya filtrada por empresa y «solo sus datos».
     * Orden: por defecto fecha DESC, id DESC. Si el proveedor implementa KanbanOrdenableInterface,
     * $filters trae además 'orden' y 'dir' ya saneados por KanbanAcceso::orden() (lista cerrada de
     * opcionesOrden()); sin ellos, el primer criterio con su dirección por defecto. El desempate es
     * siempre id DESC, para que la paginación por offset sea estable. Sin permiso de lectura ⇒ [].
     * @param array<string, string> $filters ya saneados con allowedFilters() (+ 'orden' y 'dir')
     * @return array<int, array> tarjetas con el formato de cardData()
     */
    public function cards(string $columnId, array $filters, int $offset, int $limit, User $user): array;

    /**
     * Recuento y suma del dato clave por columna, en UNA consulta agrupada.
     * Sin permiso de lectura ⇒ [].
     * @return array<string, array{count: int, total: float}>
     */
    public function countByColumn(array $filters, User $user): array;

    /**
     * Tarjeta lista para JSON (formato §4). $locked lo calcula cards()/move() en lote.
     */
    public function cardData(ModelClass $model, bool $locked = false): array;

    /**
     * Mueve la tarjeta. $position se acepta pero se ignora (sin orden manual en la prueba).
     * Sin $confirmed y con destino confirm=true ⇒ resultado needsConfirm (no guarda nada).
     */
    public function move(string $id, string $toColumn, int $position, User $user, bool $confirmed = false): KanbanResultadoMover;
}

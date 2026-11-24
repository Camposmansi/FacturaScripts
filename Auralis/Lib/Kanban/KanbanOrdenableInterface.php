<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Capacidad opcional de un tablero Kanban: varios criterios de orden de las tarjetas, elegidos de una lista cerrada.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

/**
 * Los tableros que no la implementan siguen con el orden fijo fecha DESC, id DESC y sin selector.
 *
 * El criterio elegido llega a cards() dentro de $filters, en las claves PARAM_ORDEN y PARAM_DIR,
 * ya saneado por KanbanAcceso::orden() (solo claves de opcionesOrden() y 'asc' | 'desc').
 * Por eso 'orden' y 'dir' son nombres reservados: no pueden usarse en allowedFilters().
 * Sea cual sea el criterio, el desempate es SIEMPRE la clave primaria DESC: la paginación
 * por offset no repite ni pierde tarjetas.
 *
 * Cada tarjeta lleva en 'sortValues' el valor de cada criterio (número, fecha ISO en texto o null),
 * para que el navegador coloque una tarjeta movida con el mismo orden que el servidor.
 */
interface KanbanOrdenableInterface extends KanbanProviderInterface
{
    /** Nombre del parámetro (petición y $filters) con la clave del criterio. */
    public const PARAM_ORDEN = 'orden';

    /** Nombre del parámetro (petición y $filters) con la dirección. */
    public const PARAM_DIR = 'dir';

    public const ASC = 'asc';
    public const DESC = 'desc';

    /**
     * Criterios disponibles, en orden de pantalla. El primero es el de por defecto.
     * Cada uno: ['clave' => '[a-z0-9-]{1,40}', 'etiqueta' => clave de traducción, 'dir' => 'asc' | 'desc'].
     * La dirección es la de por defecto de ese criterio; el usuario puede invertirla.
     *
     * @return array<int, array{clave: string, etiqueta: string, dir: string}>
     */
    public function opcionesOrden(): array;
}

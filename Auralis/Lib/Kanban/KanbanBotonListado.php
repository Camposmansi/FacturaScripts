<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Botón «Tablero» de los listados de documentos (solo icono), con los filtros del listado en la URL.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\Providers\DocumentoKanbanTipos;

/**
 * Soporte de la extensión global de listados (Extension/Controller/ListController): el Core invoca
 * sin argumentos TODOS los métodos de una clase de extensión, así que la lógica vive aquí.
 */
final class KanbanBotonListado
{
    /** Prefijo del id del botón: el JS lo busca para llevarlo al final del grupo de la derecha (tras Filtros / Orden / Colores). */
    public const PREFIJO_ID = 'auralis-kanban-boton-';

    /** JS que mueve el botón al grupo de la derecha del listado (sin él, el botón se queda en las acciones). */
    public const JS = '/Dinamic/Assets/JS/auralis-kanban-boton-listado.js?v=1.77';

    /**
     * Datos del botón para $this->tab($vista)->addButton(), o null si no toca.
     * Solo en la vista principal de los 4 listados con tablero (ListPresupuestoCliente, ListPedidoCliente,
     * ListPresupuestoProveedor y ListPedidoProveedor)
     * y si KanbanAcceso::tablero() lo permite (ajuste del tablero, permiso de lectura del Core).
     *
     * @param string $controlador nombre corto del controlador del listado
     * @param string $vista nombre de la vista que se acaba de cargar
     * @param object $view vista del listado (para leer sus filtros)
     * @param User|false|null $user usuario del controlador (false si no hay sesión)
     * @return array<string, string>|null
     */
    public static function boton(string $controlador, string $vista, $view, $user): ?array
    {
        // comprobación barata primero: solo la vista principal de los 4 listados con tablero
        // (presupuestos y pedidos de cliente y de proveedor); el resto de listados sale aquí sin botón
        if ($vista !== $controlador || false === $user instanceof User) {
            return null;
        }

        $clave = DocumentoKanbanTipos::tableroDeListado($vista);
        if ($clave === null) {
            return null;
        }

        // Sin ajuste activo, sin tablero o sin permiso de lectura: no hay botón.
        $tablero = KanbanAcceso::tablero($clave, $user);
        if ($tablero === null) {
            return null;
        }

        $entrada = KanbanFiltrosListado::deLaVista($view);
        $parametros = ['board' => $tablero->key()] + KanbanAcceso::filtros($tablero, $entrada);

        AssetManager::addJs(Tools::config('route') . self::JS);

        // solo icono: el título es el texto del botón para el ratón y el lector de pantalla
        return [
            'type' => 'link',
            'action' => 'KanbanAuralis?' . http_build_query($parametros, '', '&amp;'),
            'icon' => 'fa-solid fa-table-columns',
            'title' => 'auralis-kanban-tablero',
            'id' => self::PREFIJO_ID . $vista,
        ];
    }
}

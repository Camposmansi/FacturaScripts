<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Capacidad opcional de un tablero Kanban: barra de filtros visible y vuelta al listado del Core con los mismos filtros.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Dinamic\Model\User;

/**
 * Los tableros que no la implementan siguen filtrando por la URL (allowedFilters()), sin barra.
 */
interface KanbanFiltrosInterface extends KanbanProviderInterface
{
    public const FILTRO_SELECT = 'select';
    public const FILTRO_BUSCAR = 'buscar';
    public const FILTRO_FECHA = 'fecha';

    /**
     * Controles de la barra, en orden. Cada uno:
     * ['nombre' => 'codagente', 'etiqueta' => 'Agente', 'tipo' => 'select' | 'buscar' | 'fecha',
     *  'opciones' => [valor => texto] (solo select), 'texto' => texto del valor actual (solo buscar)].
     * Cada nombre debe estar en allowedFilters(). $filtros llega ya saneado.
     *
     * @param array<string, string> $filtros
     * @return array<int, array<string, mixed>>
     */
    public function barraFiltros(array $filtros, User $user): array;

    /**
     * Opciones de un control «buscar» (máx. 15), solo entre los datos que el usuario puede ver.
     *
     * @return array<int, array{value: string, text: string}>
     */
    public function buscarFiltro(string $nombre, string $texto, User $user): array;

    /**
     * Campos POST del listado del Core equivalentes a los filtros, para volver a «Lista» con ellos
     * (el listado solo lee sus filtros por POST y los recuerda en caché).
     *
     * @param array<string, string> $filtros
     * @return array<string, string> p. ej. ['filtercodserie' => 'A', 'filterstartdate' => '01-10-2026']
     */
    public function filtrosListado(array $filtros): array;
}

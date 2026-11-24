<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Criterio de orden que otro plugin añade a los tableros Kanban de documentos (KanbanOrdenRegistry), declarado sin SQL.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

/**
 * El plugin no escribe SQL: declara de dónde sale el dato (origen()) y Auralis monta la consulta
 * con identificadores validados contra el esquema real, valores escapados y dirección de una lista cerrada.
 * Lo que llega de la petición solo se compara con las claves registradas.
 *
 * Si cualquier método lanza una excepción, el criterio se descarta (queda en el log del canal Auralis)
 * y el tablero sigue con su orden por defecto.
 */
interface KanbanCriterioOrdenInterface
{
    /** Clave única [a-z0-9-]{1,40}; 'fecha', 'importe' y 'validez' son de Auralis y no se pueden usar. */
    public function clave(): string;

    /** Clave de traducción del texto del selector «Ordenar por». */
    public function etiqueta(): string;

    /**
     * Tableros a los que aplica (claves de KanbanRegistry, p. ej. 'presupuestos-cliente').
     *
     * @return string[]
     */
    public function tableros(): array;

    /** Dirección por defecto: 'asc' o 'desc'. */
    public function direccionPorDefecto(): string;

    /**
     * De dónde sale el valor para el tablero $tablero. Solo columnas numéricas o de fecha.
     *
     * - Columna del propio documento: ['columna' => 'mi_columna'].
     * - Columna de otra tabla enlazada por el id del documento:
     *   ['tabla' => 'mi_tabla', 'columna' => 'porcentaje', 'enlace' => 'iddocumento',
     *    'condiciones' => ['tipodoc' => 'PresupuestoCliente'], 'agregado' => 'max' | 'min'].
     *   Si hay varias filas por documento se toma el máximo (o el mínimo): nunca se duplican tarjetas.
     *
     * @return array<string, mixed>
     */
    public function origen(string $tablero): array;

    /**
     * Texto corto opcional (texto plano, máx. 60 caracteres) que la tarjeta muestra mientras se ordena
     * por este criterio, p. ej. «Avance 40 %». Null = nada. $valor es número, fecha ISO o null.
     *
     * @param float|string|null $valor
     */
    public function textoTarjeta(string $tablero, $valor): ?string;
}

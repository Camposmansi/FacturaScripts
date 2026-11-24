<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Proveedor de etiquetas que otro plugin añade a las tarjetas de los tableros Kanban de documentos (KanbanEtiquetaRegistry), pedidas por lote.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

/**
 * Auralis llama a etiquetas() UNA vez por página de tarjetas (y una vez por tarjeta repintada tras
 * mover o guardar), solo con los ids de documentos que el usuario ya puede ver (permisos, empresa y
 * «solo sus datos»). El proveedor no recibe nada de la petición.
 *
 * Lo que devuelve se sanea en KanbanEtiquetaRegistry::etiquetas(): texto plano corto, color de una
 * lista cerrada y como mucho KanbanEtiquetaRegistry::MAX_POR_PROVEEDOR etiquetas por documento.
 * Si cualquier método lanza una excepción o el formato no es válido, se ignoran SUS etiquetas
 * (aviso en el log del canal Auralis) y el tablero sigue.
 */
interface KanbanEtiquetaProviderInterface
{
    /** Clave única [a-z0-9-]{1,40}, p. ej. 'miplugin-avance'. */
    public function clave(): string;

    /**
     * Tableros a los que aplica (claves de KanbanRegistry, p. ej. 'presupuestos-cliente').
     *
     * @return string[]
     */
    public function tableros(): array;

    /**
     * Etiquetas de un lote de documentos del tablero $tablero, en una o pocas consultas (sin N+1).
     * Solo hace falta devolver los ids que tengan etiquetas; los ids que no se pidieron se ignoran.
     *
     * - label: texto plano (se recorta a 40 caracteres; sin HTML).
     * - color: '#rgb', '#rrggbb' (también con alfa) o un color de Bootstrap ('primary', 'success',
     *   'warning'…); cualquier otro valor se queda sin color.
     * - title: texto plano opcional para el tooltip (máx. 120 caracteres).
     *
     * @param int[] $ids ids de documento (clave primaria) de tarjetas visibles para el usuario
     * @return array<int, array<int, array{label: string, color?: string, title?: string}>>
     */
    public function etiquetas(string $tablero, array $ids): array;
}

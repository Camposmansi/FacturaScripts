<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Buscador genérico del widget zLineasProducto: precio y descripción de una referencia con la
 *              lógica del Core (PresupuestoCliente en memoria + getNewProductLine, así aplican la tarifa del
 *              cliente y plugins como zTarifas; si no hay nada, la Variante) y respuesta JSON del autocomplete
 *              con los mismos filtros que SalesController (producto no bloqueado y que se vende).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Dinamic\Model\Variante;

final class ZLineasProductoBuscador
{
    /**
     * Documento en memoria por codcliente (null = cliente inexistente), para que una búsqueda
     * no cargue el mismo cliente una vez por resultado.
     *
     * @var array<string, PresupuestoCliente|null>
     */
    private static array $documentos = [];

    /**
     * Precio y descripción de $referencia para $codcliente. Nunca guarda nada.
     *
     * Contrato: con cliente, el precio es EXACTAMENTE el pvpunitario (unitario sin IVA) que pone el Core
     * al añadir esa referencia a un documento de venta de ese cliente (getNewProductLine), incluido un 0
     * de la tarifa. Sin cliente, o si el Core no encuentra el producto, el precio neto de la Variante.
     * Nunca se usa ni se compara con el precio con IVA.
     *
     * @return array{precio: float, descripcion: string}
     */
    public static function precio(string $codcliente, string $referencia): array
    {
        $referencia = Tools::noHtml(trim($referencia));
        $codcliente = Tools::noHtml(trim($codcliente));
        $out = ['precio' => 0.0, 'descripcion' => ''];

        if ($referencia === '') {
            return $out;
        }

        $doc = self::documento($codcliente);
        if (null !== $doc) {
            $line = $doc->getNewProductLine($referencia);
            // el Core solo rellena idproducto si encontró la variante: entonces manda su precio,
            // aunque sea 0 (tarifa al 100 %) o el producto no tenga descripción
            if (false === empty($line->idproducto)) {
                $out['precio'] = (float) ($line->pvpunitario ?? 0);
                $out['descripcion'] = Tools::noHtml(trim((string) ($line->descripcion ?? '')));
                return $out;
            }
        }

        $variante = new Variante();
        if ($variante->loadWhere([Where::eq('referencia', $referencia)])) {
            $out['precio'] = (float) $variante->precio;
            $out['descripcion'] = Tools::noHtml(trim((string) $variante->description()));
        }

        return $out;
    }

    /**
     * Respuesta JSON del autocomplete del widget: `key`, `value`, `precio`, `descripcion`
     * y la fila `no-data` (key null) si no hay resultados.
     * Término de `query[term]` (lo que envía el JS) o `term`; `codcliente` y `filtro` del input.
     *
     * @param callable|null $resolverPrecio fn(string $codcliente, string $ref): array{precio:float,descripcion:string};
     *                                      por defecto self::precio()
     * @param callable|null $filtroReferencia fn(string $ref, string $filtro): bool; solo se llama si llega `filtro`
     */
    public static function autocompleteJson(
        Request $request,
        Response $response,
        ?callable $resolverPrecio = null,
        ?callable $filtroReferencia = null
    ): void {
        $resolverPrecio ??= [self::class, 'precio'];

        $query = $request->request->getArray('query');
        $term = (string) ($query['term'] ?? '');
        if ($term === '') {
            $term = (string) $request->inputOrQuery('term', '');
        }

        $codcliente = Tools::noHtml(trim((string) $request->input('codcliente', '')));

        // se sanea de nuevo en el servidor: solo [A-Za-z0-9_-]
        $filtro = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('filtro', ''));

        // mismos filtros que el Core en SalesController::autocompleteProductAction()
        $where = [
            Where::eq('p.bloqueado', 0),
            Where::eq('p.sevende', 1),
        ];

        $list = [];
        $variante = new Variante();
        foreach ($variante->codeModelSearch($term, 'referencia', $where) as $value) {
            $ref = (string) $value->code;
            if ($filtro !== '' && null !== $filtroReferencia && false === (bool) $filtroReferencia($ref, $filtro)) {
                continue;
            }

            $resolved = $resolverPrecio($codcliente, $ref);
            $resolved = is_array($resolved) ? $resolved : [];
            $desc = (string) ($resolved['descripcion'] ?? '') !== ''
                ? (string) $resolved['descripcion']
                : (string) $value->description;
            $list[] = [
                'key' => Tools::fixHtml($ref),
                'value' => Tools::fixHtml($desc),
                'precio' => (float) ($resolved['precio'] ?? 0),
                'descripcion' => Tools::fixHtml($desc),
            ];
        }

        if (empty($list)) {
            $list[] = ['key' => null, 'value' => Tools::trans('no-data'), 'precio' => 0, 'descripcion' => ''];
        }

        $response->json($list);
    }

    /**
     * Olvida los documentos memorizados (tests o procesos largos que cambian tarifas).
     */
    public static function limpiarCache(): void
    {
        self::$documentos = [];
    }

    private static function documento(string $codcliente): ?PresupuestoCliente
    {
        if ($codcliente === '') {
            return null;
        }

        if (array_key_exists($codcliente, self::$documentos)) {
            return self::$documentos[$codcliente];
        }

        $doc = null;
        $cliente = new Cliente();
        if ($cliente->load($codcliente)) {
            $doc = new PresupuestoCliente();
            $doc->setSubject($cliente);
        }

        self::$documentos[$codcliente] = $doc;
        return $doc;
    }
}

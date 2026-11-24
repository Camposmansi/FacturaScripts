<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Recoge y renderiza avisos internos de terceros, productos y familias en documentos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Model\Base\PurchaseDocument;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Familia;
use FacturaScripts\Dinamic\Model\GrupoClientes;
use FacturaScripts\Dinamic\Model\Producto;
use FacturaScripts\Dinamic\Model\Proveedor;

class AvisosDocumento
{
    private const SETTINGS_GROUP = 'auralis_avisos';
    private const FIELD = 'aviso_auralis';

    /**
     * Claves de configuración por tipo de documento.
     *
     * @return string[]
     */
    public static function clavesConfiguracion(): array
    {
        return [
            'presupuesto_cliente',
            'pedido_cliente',
            'albaran_cliente',
            'factura_cliente',
            'presupuesto_proveedor',
            'pedido_proveedor',
            'albaran_proveedor',
            'factura_proveedor',
        ];
    }

    /**
     * Indica si los avisos están activos para la clase de documento indicada.
     */
    public static function estaActivo(string $modelClassName): bool
    {
        $clave = self::mapaClaseAClave($modelClassName);
        if ($clave === null) {
            return false;
        }

        $valor = Tools::settings(self::SETTINGS_GROUP, $clave, true);
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Recoge avisos aplicables a un documento de venta.
     *
     * @return array{tercero:array,familias:array,productos:array,total:int}
     */
    public static function recogerVentas(SalesDocument $model): array
    {
        $payload = self::payloadVacio();

        if (false === self::estaActivo($model->modelClassName())) {
            return $payload;
        }

        $codcliente = (string) ($model->codcliente ?? '');
        if ($codcliente !== '') {
            $avisoCliente = self::avisoCliente($codcliente);
            if ($avisoCliente !== null) {
                $payload['tercero'][] = $avisoCliente;
            }

            $cliente = new Cliente();
            if ($cliente->load($codcliente)) {
                $codgrupo = trim((string) ($cliente->codgrupo ?? ''));
                if ($codgrupo !== '') {
                    $avisoGrupo = self::avisoGrupoCliente($codgrupo);
                    if ($avisoGrupo !== null) {
                        $payload['tercero'][] = $avisoGrupo;
                    }
                }
            }
        }

        self::anadirAvisosLineas($payload, $model->getLines());

        $payload['total'] = self::contar($payload);
        return $payload;
    }

    /**
     * Recoge avisos aplicables a un documento de compra.
     *
     * @return array{tercero:array,familias:array,productos:array,total:int}
     */
    public static function recogerCompras(PurchaseDocument $model): array
    {
        $payload = self::payloadVacio();

        if (false === self::estaActivo($model->modelClassName())) {
            return $payload;
        }

        $codproveedor = (string) ($model->codproveedor ?? '');
        if ($codproveedor !== '') {
            $avisoProveedor = self::avisoProveedor($codproveedor);
            if ($avisoProveedor !== null) {
                $payload['tercero'][] = $avisoProveedor;
            }
        }

        self::anadirAvisosLineas($payload, $model->getLines());

        $payload['total'] = self::contar($payload);
        return $payload;
    }

    /**
     * Genera el HTML del banner colapsable de avisos en columnas (tercero / familia / producto).
     * Solo se renderizan las columnas con contenido; el ancho se reparte entre las visibles.
     *
     * @param array{tercero:array,familias:array,productos:array,total:int} $payload
     * @param string $tipoTercero customer|supplier
     */
    public static function renderHtml(array $payload, string $tipoTercero = 'customer'): ?string
    {
        if (($payload['total'] ?? 0) < 1) {
            return null;
        }

        $uid = 'auralis-avisos-' . bin2hex(random_bytes(4));
        $total = (int) $payload['total'];
        $titulo = Tools::trans('auralis-avisos-encontrados', ['%count%' => (string) $total]);

        $columnas = [];

        $colTercero = self::renderSeccionTercero($payload['tercero'] ?? [], $tipoTercero, $uid);
        if ($colTercero !== '') {
            $columnas[] = $colTercero;
        }

        $colFamilias = self::renderSeccionLista(
            $payload['familias'] ?? [],
            'familias',
            Tools::trans('auralis-avisos-familias'),
            $uid
        );
        if ($colFamilias !== '') {
            $columnas[] = $colFamilias;
        }

        $colProductos = self::renderSeccionLista(
            $payload['productos'] ?? [],
            'productos',
            Tools::trans('auralis-avisos-productos'),
            $uid
        );
        if ($colProductos !== '') {
            $columnas[] = $colProductos;
        }

        if (empty($columnas)) {
            return null;
        }

        $colClass = self::claseColumna(count($columnas));

        $html = '<div class="col-12 order-last auralis-avisos-wrap">'
            . '<div class="auralis-avisos-banner alert alert-warning mb-2 py-2 px-3" role="region" aria-label="'
            . self::e($titulo) . '">'
            . '<button type="button" class="auralis-avisos-toggle text-decoration-none text-start w-100 p-0 border-0 bg-transparent'
            . ' d-flex align-items-center gap-2" data-bs-toggle="collapse" data-bs-target="#' . $uid . '"'
            . ' aria-expanded="false" aria-controls="' . $uid . '">'
            . '<i class="fa-solid fa-triangle-exclamation flex-shrink-0"></i>'
            . '<span class="fw-semibold flex-grow-1">' . self::e($titulo) . '</span>'
            . '<i class="fa-solid fa-chevron-down auralis-avisos-chevron"></i>'
            . '</button>'
            . '<div id="' . $uid . '" class="collapse auralis-avisos-body mt-2">'
            . '<div class="row g-3 align-items-start auralis-avisos-columns">';

        foreach ($columnas as $colHtml) {
            $html .= '<div class="' . $colClass . '">' . $colHtml . '</div>';
        }

        $html .= '</div></div></div></div>';
        return $html;
    }

    /**
     * Clase Bootstrap según el número de columnas visibles.
     */
    private static function claseColumna(int $numColumnas): string
    {
        return match ($numColumnas) {
            1 => 'col-12',
            2 => 'col-12 col-md-6',
            default => 'col-12 col-md-4',
        };
    }

    /**
     * @return array{tercero:array,familias:array,productos:array,total:int}
     */
    private static function payloadVacio(): array
    {
        return [
            'tercero' => [],
            'familias' => [],
            'productos' => [],
            'total' => 0,
        ];
    }

    private static function mapaClaseAClave(string $modelClassName): ?string
    {
        $mapa = [
            'PresupuestoCliente' => 'presupuesto_cliente',
            'PedidoCliente' => 'pedido_cliente',
            'AlbaranCliente' => 'albaran_cliente',
            'FacturaCliente' => 'factura_cliente',
            'PresupuestoProveedor' => 'presupuesto_proveedor',
            'PedidoProveedor' => 'pedido_proveedor',
            'AlbaranProveedor' => 'albaran_proveedor',
            'FacturaProveedor' => 'factura_proveedor',
        ];

        return $mapa[$modelClassName] ?? null;
    }

    private static function avisoCliente(string $codcliente): ?array
    {
        $cliente = new Cliente();
        if (false === $cliente->hasColumn(self::FIELD) || false === $cliente->load($codcliente)) {
            return null;
        }

        $texto = trim((string) ($cliente->{self::FIELD} ?? ''));
        if ($texto === '') {
            return null;
        }

        return [
            'codigo' => $codcliente,
            'nombre' => (string) ($cliente->nombre ?? $codcliente),
            'texto' => $texto,
        ];
    }

    private static function avisoGrupoCliente(string $codgrupo): ?array
    {
        $grupo = new GrupoClientes();
        if (false === $grupo->hasColumn(self::FIELD) || false === $grupo->load($codgrupo)) {
            return null;
        }

        $texto = trim((string) ($grupo->{self::FIELD} ?? ''));
        if ($texto === '') {
            return null;
        }

        return [
            'codigo' => $codgrupo,
            'nombre' => (string) ($grupo->nombre ?? $codgrupo),
            'texto' => $texto,
        ];
    }

    private static function avisoProveedor(string $codproveedor): ?array
    {
        $proveedor = new Proveedor();
        if (false === $proveedor->hasColumn(self::FIELD) || false === $proveedor->load($codproveedor)) {
            return null;
        }

        $texto = trim((string) ($proveedor->{self::FIELD} ?? ''));
        if ($texto === '') {
            return null;
        }

        return [
            'codigo' => $codproveedor,
            'nombre' => (string) ($proveedor->nombre ?? $codproveedor),
            'texto' => $texto,
        ];
    }

    /**
     * @param array{tercero:array,familias:array,productos:array,total:int} $payload
     * @param array<int, object> $lineas
     */
    private static function anadirAvisosLineas(array &$payload, array $lineas): void
    {
        $productoProbe = new Producto();
        if (false === $productoProbe->hasColumn(self::FIELD)) {
            return;
        }

        $referencias = [];
        $idsProducto = [];
        foreach ($lineas as $linea) {
            $idproducto = (int) ($linea->idproducto ?? 0);
            if ($idproducto > 0) {
                $idsProducto[$idproducto] = true;
            }

            $referencia = trim((string) ($linea->referencia ?? ''));
            if ($referencia !== '') {
                $referencias[$referencia] = true;
            }
        }

        if (empty($referencias) && empty($idsProducto)) {
            return;
        }

        // Resolver idproducto desde variantes cuando la línea solo trae referencia
        if (!empty($referencias)) {
            $variantes = DbQuery::table('variantes')
                ->select('referencia, idproducto')
                ->whereIn('referencia', array_keys($referencias))
                ->get();

            foreach ($variantes as $row) {
                $id = (int) ($row['idproducto'] ?? 0);
                if ($id > 0) {
                    $idsProducto[$id] = true;
                }
            }
        }

        if (empty($idsProducto)) {
            return;
        }

        $productos = DbQuery::table('productos')
            ->select('idproducto, referencia, descripcion, codfamilia, aviso_auralis')
            ->whereIn('idproducto', array_keys($idsProducto))
            ->get();

        $codFamilias = [];
        $vistosProducto = [];
        foreach ($productos as $row) {
            $id = (int) ($row['idproducto'] ?? 0);
            if ($id < 1 || isset($vistosProducto[$id])) {
                continue;
            }
            $vistosProducto[$id] = true;

            $texto = trim((string) ($row['aviso_auralis'] ?? ''));
            if ($texto !== '') {
                $ref = (string) ($row['referencia'] ?? '');
                $desc = (string) ($row['descripcion'] ?? $ref);
                $payload['productos'][] = [
                    'codigo' => $ref,
                    'nombre' => $desc,
                    'texto' => $texto,
                ];
            }

            $codfamilia = trim((string) ($row['codfamilia'] ?? ''));
            if ($codfamilia !== '') {
                $codFamilias[$codfamilia] = true;
            }
        }

        if (empty($codFamilias)) {
            return;
        }

        $familiaProbe = new Familia();
        if (false === $familiaProbe->hasColumn(self::FIELD)) {
            return;
        }

        $familias = DbQuery::table('familias')
            ->select('codfamilia, descripcion, aviso_auralis')
            ->whereIn('codfamilia', array_keys($codFamilias))
            ->get();

        foreach ($familias as $row) {
            $texto = trim((string) ($row['aviso_auralis'] ?? ''));
            if ($texto === '') {
                continue;
            }

            $payload['familias'][] = [
                'codigo' => (string) ($row['codfamilia'] ?? ''),
                'nombre' => (string) ($row['descripcion'] ?? $row['codfamilia'] ?? ''),
                'texto' => $texto,
            ];
        }
    }

    /**
     * @param array{tercero:array,familias:array,productos:array,total:int} $payload
     */
    private static function contar(array $payload): int
    {
        return count($payload['tercero']) + count($payload['familias']) + count($payload['productos']);
    }

    /**
     * @param array<int, array{codigo:string,nombre:string,texto:string}> $items
     */
    private static function renderSeccionTercero(array $items, string $tipoTercero, string $uid): string
    {
        if (empty($items)) {
            return '';
        }

        $titulo = $tipoTercero === 'supplier'
            ? Tools::trans('auralis-avisos-proveedor')
            : Tools::trans('auralis-avisos-cliente');
        $icon = $tipoTercero === 'supplier' ? 'fa-truck' : 'fa-user';
        $secId = $uid . '-tercero';

        $html = '<div class="auralis-avisos-section h-100">'
            . '<button type="button" class="auralis-avisos-section-toggle border-0 bg-transparent w-100'
            . ' text-start d-flex align-items-center gap-2 p-0" data-bs-toggle="collapse"'
            . ' data-bs-target="#' . $secId . '" aria-expanded="false">'
            . '<i class="fa-solid ' . $icon . ' flex-shrink-0"></i>'
            . '<span class="auralis-avisos-section-title flex-grow-1">' . self::e($titulo) . '</span>'
            . '<i class="fa-solid fa-chevron-down auralis-avisos-chevron-sm"></i>'
            . '</button>'
            . '<div id="' . $secId . '" class="collapse">'
            . '<div class="auralis-avisos-list mt-1">';

        foreach ($items as $item) {
            $html .= '<div class="auralis-avisos-item">'
                . '<div class="auralis-avisos-item-title">' . self::e((string) $item['nombre']) . '</div>'
                . '<div class="auralis-avisos-text">' . self::eNl((string) $item['texto']) . '</div>'
                . '</div>';
        }

        $html .= '</div></div></div>';
        return $html;
    }

    /**
     * @param array<int, array{codigo:string,nombre:string,texto:string}> $items
     */
    private static function renderSeccionLista(array $items, string $tipo, string $titulo, string $uid): string
    {
        if (empty($items)) {
            return '';
        }

        $count = count($items);
        $secId = $uid . '-' . $tipo;
        $icon = $tipo === 'familias' ? 'fa-folder-tree' : 'fa-box';

        $html = '<div class="auralis-avisos-section h-100">'
            . '<button type="button" class="auralis-avisos-section-toggle border-0 bg-transparent w-100'
            . ' text-start d-flex align-items-center gap-2 p-0" data-bs-toggle="collapse"'
            . ' data-bs-target="#' . $secId . '" aria-expanded="false">'
            . '<i class="fa-solid ' . $icon . ' flex-shrink-0"></i>'
            . '<span class="auralis-avisos-section-title flex-grow-1">' . self::e($titulo) . ' (' . $count . ')</span>'
            . '<i class="fa-solid fa-chevron-down auralis-avisos-chevron-sm"></i>'
            . '</button>'
            . '<div id="' . $secId . '" class="collapse">'
            . '<div class="auralis-avisos-list mt-1">';

        foreach ($items as $item) {
            $etiqueta = trim((string) ($item['codigo'] ?? ''));
            if ($etiqueta !== '') {
                $etiqueta .= ' — ';
            }
            $etiqueta .= (string) ($item['nombre'] ?? '');

            $html .= '<div class="auralis-avisos-item mb-1">'
                . '<div class="auralis-avisos-item-title">' . self::e($etiqueta) . '</div>'
                . '<div class="auralis-avisos-text">' . self::eNl((string) ($item['texto'] ?? '')) . '</div>'
                . '</div>';
        }

        $html .= '</div></div></div>';
        return $html;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function eNl(string $value): string
    {
        return nl2br(self::e($value), false);
    }
}

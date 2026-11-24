<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Editor embebido de líneas de producto/servicio en Edit y EditListView.
 *              Tipo XML: zLineasProducto. Atributos (todos opcionales):
 *              - autoadd (true|false, default false): clona fila vacía al elegir producto.
 *              - autocompleteaction (default autocomplete-producto-lineas): action POST del autocomplete.
 *              - autocompletefilter (texto [A-Za-z0-9_-], default vacío): se envía como POST `filtro`.
 *              - prices (true|false, default true): false oculta la columna de precio (precio[] va oculto).
 *              - billable (true|false, default true): false oculta el interruptor «Facturable» (facturable[] va oculto).
 *              - maxlines (entero >= 0, default 0 = sin límite): máximo de líneas, bloqueadas incluidas.
 *              - lines (texto [A-Za-z0-9_-], default vacío): tipo de línea; si viene, se llama a
 *                getLineasProducto($lines), getLineasProductoBloqueadas($lines) y getLineasProductoCodcliente($lines).
 *              - detail (ruta Twig [A-Za-z0-9_/-] sin extensión, default vacío): plantilla del consumidor que se
 *                pinta como segunda línea de cada fila (guardadas, vacía inicial y molde). Recibe model, linea,
 *                clave, nombre (detalle[<clave>]), bloqueada, codigoDoc, puedeEditar, editable, listaId y lines.
 *              - quantityfield (texto [a-z0-9_], default cantidad): propiedad de la línea de la que se lee la cantidad.
 *              - quantitytitle (clave de traducción, default quantity): rótulo de la cantidad.
 *              - pricetitle (clave de traducción, default auralis-precio-unitario): rótulo del precio.
 *              - emptyquantity (true|false, default false): las filas nuevas (vacía inicial, molde y las que se
 *                vacían) salen con la cantidad vacía en vez de 1, para que cualquier cantidad escrita cuente como dato.
 *              El título de la cabecera sale del title/name de la columna XML (traducido); si viene vacío,
 *              «auralis-lineas-producto». El sync y los cargos los implementa el consumidor (ZLineasProductoSync).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Widget;

use Exception;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\Widget\BaseWidget;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use Throwable;

class WidgetZLineasProducto extends BaseWidget
{
    /** Si true, al elegir producto se clona otra fila vacía. Si false, solo el botón «Añadir línea». */
    protected bool $autoadd = false;

    /** Action POST del autocomplete en el controlador consumidor. */
    protected string $autocompleteAction = 'autocomplete-producto-lineas';

    /** Filtro opcional que viaja como POST `filtro` en el autocomplete (saneado a [A-Za-z0-9_-]). */
    protected string $autocompleteFilter = '';

    /** Si false, la columna de precio no se ve (precio[] viaja como hidden). */
    protected bool $prices = true;

    /** Si false, el interruptor «Facturable» no se ve (facturable[] viaja como hidden). */
    protected bool $billable = true;

    /** Máximo de líneas (bloqueadas incluidas). 0 = sin límite. */
    protected int $maxLineas = 0;

    /** Tipo de línea que se pasa a los getters del padre ('' = sin argumento). */
    protected string $lines = '';

    /** Plantilla Twig del detalle de cada fila, sin extensión ('' = sin detalle). */
    protected string $detail = '';

    /** Propiedad de la línea de la que se lee la cantidad. */
    protected string $quantityField = 'cantidad';

    /** Clave de traducción del rótulo de la cantidad. */
    protected string $quantityTitle = 'quantity';

    /** Clave de traducción del rótulo del precio. */
    protected string $priceTitle = 'auralis-precio-unitario';

    /** Si true, las filas nuevas salen con la cantidad vacía en vez de 1. */
    protected bool $emptyQuantity = false;

    /** Marcador de clave del molde; el JS lo cambia por una clave única al clonar. */
    public const MARCADOR_CLAVE = '__ZLP__';

    /** Clave de la fila vacía inicial (las que crea el JS empiezan por «n» y llevan sello de tiempo). */
    public const CLAVE_FILA_NUEVA = 'n0';

    public function __construct(array $data)
    {
        $data['fieldname'] = $data['fieldname'] ?? '';
        parent::__construct($data);

        $this->autoadd = self::atributoBool($data, 'autoadd', false);
        $this->prices = self::atributoBool($data, 'prices', true);
        $this->billable = self::atributoBool($data, 'billable', true);
        $this->emptyQuantity = self::atributoBool($data, 'emptyquantity', false);

        $action = trim((string) ($data['autocompleteaction'] ?? ''));
        if ($action !== '') {
            $this->autocompleteAction = $action;
        }

        $this->autocompleteFilter = (string) preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) ($data['autocompletefilter'] ?? '')
        );

        $max = trim((string) ($data['maxlines'] ?? '0'));
        $this->maxLineas = ctype_digit($max) ? (int) $max : 0;

        $this->lines = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($data['lines'] ?? ''));

        // ruta relativa a View sin extensión: sin puntos no cabe «..» ni otra extensión
        $detail = trim(trim((string) ($data['detail'] ?? '')), '/');
        $this->detail = preg_match('/^[A-Za-z0-9_\/-]+$/', $detail) === 1 ? $detail : '';

        $campo = (string) preg_replace('/[^a-z0-9_]/', '', (string) ($data['quantityfield'] ?? ''));
        $this->quantityField = $campo === '' ? 'cantidad' : $campo;

        $titulo = trim((string) ($data['quantitytitle'] ?? ''));
        $this->quantityTitle = $titulo === '' ? 'quantity' : $titulo;

        $tituloPrecio = trim((string) ($data['pricetitle'] ?? ''));
        $this->priceTitle = $tituloPrecio === '' ? 'auralis-precio-unitario' : $tituloPrecio;
    }

    protected function assets(): void
    {
        $route = Tools::config('route');
        AssetManager::addJs($route . '/node_modules/jquery-ui-dist/jquery-ui.min.js?v=1.73', 2);
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/ZLineasProducto.css?v=1.73');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/ZLineasProducto.js?v=1.73');
    }

    /**
     * @param object $model
     * @param string $title
     * @param string $description
     * @param string $titleurl
     */
    public function edit($model, $title = '', $description = '', $titleurl = ''): string
    {
        try {
            $puedeEditar = false === $this->readonly();
            $listaId = $this->buildListaId($model);
            $bloqueadas = $this->resolveBloqueadas($model);
            $lineas = $this->resolveLineas($model);

            return Html::render('Block/ZLineasProductoEmbed.html.twig', [
                'model' => $model,
                'puedeEditar' => $puedeEditar,
                'listaId' => $listaId,
                'codclienteLineas' => $this->resolveCodcliente($model),
                'lineasBloqueadas' => $bloqueadas,
                'lineasProducto' => $lineas,
                'filasProducto' => $this->buildFilas($model, $lineas, $bloqueadas, $puedeEditar, $listaId),
                'filaNueva' => $this->buildFila($model, null, self::CLAVE_FILA_NUEVA, false, '', $puedeEditar, $listaId),
                'filaMolde' => $this->buildFila($model, null, self::MARCADOR_CLAVE, false, '', $puedeEditar, $listaId),
                'tituloCantidad' => Tools::trans($this->quantityTitle),
                'tituloPrecio' => Tools::trans($this->priceTitle),
                'cantidadVacia' => $this->emptyQuantity,
                'autoadd' => $this->autoadd,
                'autocompleteAction' => $this->autocompleteAction,
                'autocompleteFilter' => $this->autocompleteFilter,
                'mostrarPrecios' => $this->prices,
                'mostrarFacturable' => $this->billable,
                'maxLineas' => $this->maxLineas,
                'tituloLineas' => $this->resolveTitulo((string) $title),
            ]);
        } catch (Exception $e) {
            Tools::log()->error('widget-zlineasproducto-error', ['%error%' => $e->getMessage()]);

            return '<div class="alert alert-danger mb-3">'
                . '<i class="fa-solid fa-circle-xmark fa-fw me-2"></i>'
                . Tools::trans('auralis-lineas-producto')
                . '</div>';
        }
    }

    /**
     * @param object $model
     */
    public function inputHidden($model): string
    {
        return '';
    }

    /**
     * @param object $model
     * @param \FacturaScripts\Core\Request $request
     */
    public function processFormData(&$model, $request): void
    {
        // El POST lo procesa el controlador con ZLineasProductoSync.
    }

    /**
     * @param object $model
     * @param string $display
     */
    public function tableCell($model, $display = 'left'): string
    {
        return '<td></td>';
    }

    /**
     * Lee un atributo booleano del XML ('1', 'true', 'yes', 'on' = true; '0', 'false', 'no', 'off' = false).
     *
     * @param array<string, mixed> $data
     */
    private static function atributoBool(array $data, string $clave, bool $porDefecto): bool
    {
        if (false === isset($data[$clave])) {
            return $porDefecto;
        }

        $raw = strtolower(trim((string) $data[$clave]));
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $porDefecto;
    }

    /**
     * Datos ya calculados de cada línea guardada: id (clave primaria), clave, bloqueo, cantidad y detalle.
     *
     * @param object $model
     * @param list<object> $lineas
     * @param array<int|string, string> $bloqueadas
     *
     * @return list<array<string, mixed>>
     */
    private function buildFilas($model, array $lineas, array $bloqueadas, bool $puedeEditar, string $listaId): array
    {
        $filas = [];
        foreach ($lineas as $index => $linea) {
            $id = self::idDeLinea($linea);
            $bloqueada = $id !== '' && isset($bloqueadas[$id]);
            // en las filas guardadas la clave es su id (saneado); sin id, una clave de posición
            $clave = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $id);
            if ($clave === '') {
                $clave = 'g' . $index;
            }

            $filas[] = $this->buildFila(
                $model,
                $linea,
                $clave,
                $bloqueada,
                $bloqueada ? (string) $bloqueadas[$id] : '',
                $puedeEditar,
                $listaId
            );
        }

        return $filas;
    }

    /**
     * @param object $model
     * @param object|null $linea null en la fila vacía inicial y en el molde
     *
     * @return array<string, mixed>
     */
    private function buildFila(
        $model,
        $linea,
        string $clave,
        bool $bloqueada,
        string $codigoDoc,
        bool $puedeEditar,
        string $listaId
    ): array {
        // fila nueva (sin línea): 1 o vacía según emptyquantity
        $cantidad = is_object($linea) || false === $this->emptyQuantity ? 1 : '';
        if (is_object($linea) && isset($linea->{$this->quantityField})) {
            $cantidad = $linea->{$this->quantityField};
        }

        return [
            'linea' => $linea,
            'id' => is_object($linea) ? self::idDeLinea($linea) : '',
            'clave' => $clave,
            'bloqueada' => $bloqueada,
            'codigoDoc' => $codigoDoc,
            'cantidad' => $cantidad,
            'detalleHtml' => $this->renderDetalle($model, $linea, $clave, $bloqueada, $codigoDoc, $puedeEditar, $listaId),
        ];
    }

    /**
     * Segunda línea de la fila con la plantilla del consumidor. Si falla, se registra y la fila sale sin detalle
     * (el resto del widget se pinta igual).
     *
     * @param object $model
     * @param object|null $linea
     */
    private function renderDetalle(
        $model,
        $linea,
        string $clave,
        bool $bloqueada,
        string $codigoDoc,
        bool $puedeEditar,
        string $listaId
    ): string {
        if ($this->detail === '') {
            return '';
        }

        try {
            return Html::render($this->detail . '.html.twig', [
                'model' => $model,
                'linea' => $linea,
                'clave' => $clave,
                'nombre' => 'detalle[' . $clave . ']',
                'bloqueada' => $bloqueada,
                'codigoDoc' => $codigoDoc,
                'puedeEditar' => $puedeEditar,
                'editable' => $puedeEditar && false === $bloqueada,
                'listaId' => $listaId,
                'lines' => $this->lines,
            ]);
        } catch (Throwable $e) {
            Tools::log()->warning('auralis-lineas-detalle-error', [
                '%plantilla%' => $this->detail,
                '%error%' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Id de la línea: id() (ModelClass) o primaryColumnValue() si el objeto lo tiene; si no, idlinea
     * (stdClass de los tests).
     */
    private static function idDeLinea(object $linea): string
    {
        if (method_exists($linea, 'id')) {
            return trim((string) $linea->id());
        }
        if (method_exists($linea, 'primaryColumnValue')) {
            return trim((string) $linea->primaryColumnValue());
        }

        return trim((string) ($linea->idlinea ?? ''));
    }

    /**
     * Título de la cabecera: el de la columna XML (llega sin traducir desde ColumnItem) o el genérico.
     */
    private function resolveTitulo(string $title): string
    {
        $title = trim($title);
        return $title === '' ? Tools::trans('auralis-lineas-producto') : Tools::trans($title);
    }

    /**
     * @param object $model
     *
     * @return list<object>
     */
    private function resolveLineas($model): array
    {
        if (false === is_object($model) || false === method_exists($model, 'getLineasProducto')) {
            return [];
        }

        $lineas = $this->lines === '' ? $model->getLineasProducto() : $model->getLineasProducto($this->lines);
        return is_array($lineas) ? array_values($lineas) : [];
    }

    /**
     * @param object $model
     *
     * @return array<int|string, string>
     */
    private function resolveBloqueadas($model): array
    {
        if (false === is_object($model) || false === method_exists($model, 'getLineasProductoBloqueadas')) {
            return [];
        }

        $map = $this->lines === ''
            ? $model->getLineasProductoBloqueadas()
            : $model->getLineasProductoBloqueadas($this->lines);
        return is_array($map) ? $map : [];
    }

    /**
     * @param object $model
     */
    private function resolveCodcliente($model): string
    {
        if (false === is_object($model)) {
            return '';
        }

        if (method_exists($model, 'getLineasProductoCodcliente')) {
            return $this->lines === ''
                ? (string) $model->getLineasProductoCodcliente()
                : (string) $model->getLineasProductoCodcliente($this->lines);
        }

        if (isset($model->codcliente) && (string) $model->codcliente !== '') {
            return (string) $model->codcliente;
        }

        return '';
    }

    /**
     * @param object $model
     */
    private function buildListaId($model): string
    {
        $short = 'padre';
        if (is_object($model)) {
            $parts = explode('\\', get_class($model));
            $short = (string) end($parts);
        }

        $pk = 'nuevo';
        if (is_object($model) && method_exists($model, 'primaryColumnValue')) {
            $value = $model->primaryColumnValue();
            if (null !== $value && '' !== (string) $value && '0' !== (string) $value) {
                $pk = (string) $value;
            } else {
                $pk = 'nuevo-' . spl_object_id($model);
            }
        }

        return 'zlp-' . preg_replace('/[^A-Za-z0-9_-]/', '', $short . '-' . $pk);
    }
}

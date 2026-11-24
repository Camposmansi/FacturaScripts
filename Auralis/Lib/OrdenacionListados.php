<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Utilidades para enriquecer opciones de orden en ListView y aplicar orden numérico a códigos varchar.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Lib\ExtendedController\ListView;
use FacturaScripts\Core\Model\Base\JoinModel as LegacyJoinModel;
use FacturaScripts\Core\Template\JoinModel as TemplateJoinModel;
use FacturaScripts\Core\Tools;

class OrdenacionListados
{
    private const SETTINGS_GROUP = 'auralis_avisos';

    public const SETTING_TODAS_COLUMNAS = 'orden_todas_columnas';
    public const SETTING_NATURAL_CODIGOS = 'orden_natural_codigos';

    /**
     * Campos de código varchar donde el orden lexicográfico suele fallar (10 antes que 2).
     *
     * @var string[]
     */
    private const CAMPOS_CODIGO = [
        'codagente',
        'codalmacen',
        'codcliente',
        'codcuenta',
        'codejercicio',
        'codfabricante',
        'codfamilia',
        'codgrupo',
        'codigo',
        'codpago',
        'codproveedor',
        'codtarifa',
        'referencia',
    ];

    /**
     * Campos character que no deben castarse a entero.
     *
     * @var string[]
     */
    private const CAMPOS_EXCLUIDOS = [
        'coddivisa',
        'codimpuesto',
        'codpais',
        'codserie',
        'name',
        'nick',
        'tipoidfiscal',
    ];

    /**
     * Claves de settings de ordenación (para defaults en Init/Config).
     *
     * @return string[]
     */
    public static function clavesConfiguracion(): array
    {
        return [
            self::SETTING_TODAS_COLUMNAS,
            self::SETTING_NATURAL_CODIGOS,
        ];
    }

    public static function activarTodasColumnas(): bool
    {
        return (bool) Tools::settings(self::SETTINGS_GROUP, self::SETTING_TODAS_COLUMNAS, true);
    }

    public static function activarOrdenNaturalCodigos(): bool
    {
        return (bool) Tools::settings(self::SETTINGS_GROUP, self::SETTING_NATURAL_CODIGOS, true);
    }

    /**
     * Indica si el modelo construye el ORDER BY por concatenación (sin soporte de integer:).
     *
     * @param mixed $model
     */
    public static function esJoinModel($model): bool
    {
        return $model instanceof TemplateJoinModel || $model instanceof LegacyJoinModel;
    }

    /**
     * Aplica las mejoras de ordenación configuradas sobre una ListView.
     */
    public static function aplicarEnVista(ListView $view): void
    {
        if (self::activarTodasColumnas()) {
            self::anadirColumnasComoOrden($view);
        }

        if (self::activarOrdenNaturalCodigos()) {
            self::aplicarOrdenNaturalCodigos($view);
        }
    }

    /**
     * Registra como opción de orden cada columna visible que exista en la tabla del modelo.
     */
    public static function anadirColumnasComoOrden(ListView $view): void
    {
        $existentes = self::mapaCamposOrdenExistentes($view);
        $camposModelo = self::camposModelo($view);
        $esJoin = self::esJoinModel($view->model ?? null);

        foreach ($view->getColumns() as $col) {
            if ($col->hidden()) {
                continue;
            }

            $campo = isset($col->widget) ? (string) ($col->widget->fieldname ?? '') : '';
            if ($campo === '' || isset($existentes[strtolower($campo)])) {
                continue;
            }

            if ($camposModelo !== [] && false === array_key_exists($campo, $camposModelo)) {
                continue;
            }

            // En JoinModel el ORDER BY necesita el SQL real (tabla.columna), no el alias corto.
            $campoOrden = self::resolverCampoSql($campo, $camposModelo, $esJoin);
            $etiqueta = empty($col->title) ? $campo : $col->title;
            $view->addOrderBy([$campoOrden], $etiqueta);
            $existentes[strtolower($campo)] = true;
            $existentes[strtolower(self::normalizarNombreCampo($campoOrden))] = true;
        }
    }

    /**
     * Reescribe criterios de orden elegibles para orden numérico.
     *
     * ModelClass/DbQuery: prefijo nativo integer:.
     * JoinModel: se omite (su getOrderBy() concatena claves literales y no entiende integer:).
     */
    public static function aplicarOrdenNaturalCodigos(ListView $view): void
    {
        $camposModelo = self::camposModelo($view);
        if ($camposModelo === []) {
            return;
        }

        // JoinModel::getOrderBy() hace "ORDER BY {clave} {ASC|DESC}" sin procesar prefijos.
        // Inyectar integer: provoca SQL inválido (p. ej. ListProducto → pestaña Variantes/Stock).
        if (self::esJoinModel($view->model ?? null)) {
            return;
        }

        foreach ($view->orderOptions as $key => $option) {
            if (empty($option['fields']) || false === is_array($option['fields'])) {
                continue;
            }

            $nuevos = [];
            $cambiados = false;

            foreach ($option['fields'] as $field) {
                $reescritos = self::camposOrdenNaturalParaCampo((string) $field, $camposModelo);
                if ($reescritos === []) {
                    $nuevos[] = $field;
                    continue;
                }

                foreach ($reescritos as $item) {
                    $nuevos[] = $item;
                }
                $cambiados = true;
            }

            if ($cambiados) {
                $view->orderOptions[$key]['fields'] = array_values(array_unique($nuevos));
            }
        }

        self::sincronizarOrdenActivo($view, $camposModelo);
    }

    /**
     * Devuelve [integer:campo, campo] si el campo es elegible; vacío si no.
     * Método público para tests de regresión sin montar ListView.
     *
     * @param array<string, array> $camposModelo
     * @return string[]
     */
    public static function camposOrdenNaturalParaCampo(string $field, array $camposModelo): array
    {
        $campo = self::normalizarNombreCampo($field);
        if ($campo === '' || false === self::esCampoCodigoNatural($campo, $camposModelo)) {
            return [];
        }

        // Si ya venía con integer:, no duplicar el prefijo.
        if (str_starts_with(trim($field), 'integer:')) {
            return [trim($field), $campo];
        }

        return ['integer:' . $campo, $campo];
    }

    /**
     * Si el orden ya estaba fijado con el nombre plano del campo, lo alinea al criterio integer:.
     *
     * @param array<string, array> $camposModelo
     */
    private static function sincronizarOrdenActivo(ListView $view, array $camposModelo): void
    {
        if (empty($view->order) || false === is_array($view->order)) {
            return;
        }

        $nuevoOrden = [];
        $huboCambio = false;

        foreach ($view->order as $campo => $direccion) {
            $reescritos = self::camposOrdenNaturalParaCampo((string) $campo, $camposModelo);
            if ($reescritos !== [] && false === str_starts_with((string) $campo, 'integer:')) {
                foreach ($reescritos as $expr) {
                    $nuevoOrden[$expr] = $direccion;
                }
                $huboCambio = true;
                continue;
            }

            $nuevoOrden[$campo] = $direccion;
        }

        if ($huboCambio) {
            $view->order = $nuevoOrden;
        }
    }

    /**
     * @param array<string, array> $camposModelo
     */
    public static function esCampoCodigoNatural(string $campo, array $camposModelo): bool
    {
        $campo = strtolower(trim($campo));
        if ($campo === '' || in_array($campo, self::CAMPOS_EXCLUIDOS, true)) {
            return false;
        }

        if (false === in_array($campo, self::CAMPOS_CODIGO, true)) {
            return false;
        }

        if (false === array_key_exists($campo, $camposModelo)) {
            return false;
        }

        return self::esTipoCaracter($camposModelo[$campo]);
    }

    /**
     * En JoinModel, el meta['name'] suele ser tabla.columna.
     *
     * @param array<string, array> $camposModelo
     */
    private static function resolverCampoSql(string $campo, array $camposModelo, bool $esJoin): string
    {
        if (false === $esJoin || false === isset($camposModelo[$campo])) {
            return $campo;
        }

        $sql = (string) ($camposModelo[$campo]['name'] ?? '');
        if ($sql !== '' && str_contains($sql, '.') && false === str_contains($sql, '(')) {
            return $sql;
        }

        return $campo;
    }

    /**
     * @param array|mixed $metaCampo
     */
    private static function esTipoCaracter($metaCampo): bool
    {
        if (false === is_array($metaCampo)) {
            return false;
        }

        $tipo = strtolower((string) ($metaCampo['type'] ?? ''));
        if ($tipo === '') {
            return false;
        }

        return str_contains($tipo, 'char')
            || str_contains($tipo, 'text')
            || str_contains($tipo, 'character');
    }

    /**
     * Quita prefijos funcionales conocidos y deja el nombre de columna.
     */
    public static function normalizarNombreCampo(string $field): string
    {
        $field = trim($field);
        if ($field === '') {
            return '';
        }

        if (str_starts_with($field, 'integer:')) {
            $field = substr($field, 8);
        } elseif (str_starts_with($field, 'lower:') || str_starts_with($field, 'upper:')) {
            $field = substr($field, 6);
        } elseif (preg_match('/^(LOWER|UPPER)\(([a-zA-Z0-9_.]+)\)$/i', $field, $m)) {
            $field = $m[2];
        }

        // Solo nombres de columna simples (sin expresiones SQL libres).
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $field)) {
            $partes = explode('.', $field);
            return strtolower((string) end($partes));
        }

        return '';
    }

    /**
     * @return array<string, true>
     */
    private static function mapaCamposOrdenExistentes(ListView $view): array
    {
        $existentes = [];
        foreach ($view->orderOptions as $opt) {
            if (empty($opt['fields']) || false === is_array($opt['fields'])) {
                continue;
            }
            foreach ($opt['fields'] as $campo) {
                $nombre = self::normalizarNombreCampo((string) $campo);
                if ($nombre !== '') {
                    $existentes[$nombre] = true;
                }
                $existentes[strtolower(trim((string) $campo))] = true;
            }
        }

        return $existentes;
    }

    /**
     * @return array<string, array>
     */
    private static function camposModelo(ListView $view): array
    {
        if (false === isset($view->model) || false === method_exists($view->model, 'getModelFields')) {
            return [];
        }

        $campos = $view->model->getModelFields();
        return is_array($campos) ? $campos : [];
    }
}

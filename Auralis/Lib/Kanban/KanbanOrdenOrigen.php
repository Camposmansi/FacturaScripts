<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Valida el origen declarado por un criterio de orden de otro plugin y monta su SQL y sus valores por tarjeta sin aceptar SQL de fuera.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Template\ModelClass;
use InvalidArgumentException;

/**
 * Por qué es seguro:
 * - El plugin solo da NOMBRES (tabla, columna, enlace, columnas de condición), que deben cumplir
 *   [a-z_][a-z0-9_]{0,62} y existir en el esquema real (DataBase::tableExists / getColumns).
 *   Se escapan además con escapeColumn().
 * - Los valores de las condiciones son escalares que se escapan con var2str(); nunca vienen de la petición.
 * - La columna debe ser numérica o de fecha (el navegador ordena igual que el SQL) y el enlace, numérico
 *   (como la clave primaria del documento).
 * - Lo de otra tabla va en una subconsulta escalar MAX()/MIN(): no hay JOIN, así que no puede
 *   duplicar ni quitar tarjetas, ni tocar el WHERE de permisos, empresa o «solo sus datos».
 * - La dirección y el criterio salen de listas cerradas (KanbanAcceso::orden()).
 */
final class KanbanOrdenOrigen
{
    public const TIPO_NUMERO = 'numero';
    public const TIPO_FECHA = 'fecha';
    public const TIPO_FECHA_HORA = 'fecha-hora';

    private const PATRON_IDENTIFICADOR = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** Alias de la tabla del plugin dentro de la subconsulta (permite que sea la del propio documento). */
    private const ALIAS_TABLA = 'zk_orden_ext';

    private const MAX_CONDICIONES = 5;
    private const MAX_LONGITUD_VALOR = 100;
    private const AGREGADOS = ['max', 'min'];

    private const CLAVES_DOCUMENTO = ['columna'];
    private const CLAVES_TABLA = ['tabla', 'columna', 'enlace', 'condiciones', 'agregado'];

    /**
     * Origen validado contra el esquema real. Lanza InvalidArgumentException con el motivo si no vale.
     *
     * @param array<string, mixed> $origen
     * @return array{tabla: ?string, columna: string, enlace: ?string, condiciones: array<string, bool|int|float|string>, agregado: string, tipo: string}
     */
    public static function validar(array $origen, string $tablaDocumento): array
    {
        $db = new DataBase();
        $columna = self::identificador($origen['columna'] ?? null, 'columna');

        if (false === array_key_exists('tabla', $origen)) {
            self::soloClaves($origen, self::CLAVES_DOCUMENTO);
            return [
                'tabla' => null,
                'columna' => $columna,
                'enlace' => null,
                'condiciones' => [],
                'agregado' => 'max',
                'tipo' => self::tipo($db->getColumns($tablaDocumento), $columna),
            ];
        }

        self::soloClaves($origen, self::CLAVES_TABLA);
        $tabla = self::identificador($origen['tabla'], 'tabla');
        if (false === $db->tableExists($tabla)) {
            throw new InvalidArgumentException('la tabla ' . $tabla . ' no existe');
        }

        $columnas = $db->getColumns($tabla);
        $tipo = self::tipo($columnas, $columna);

        $enlace = self::identificador($origen['enlace'] ?? null, 'enlace');
        if (self::tipo($columnas, $enlace) !== self::TIPO_NUMERO) {
            throw new InvalidArgumentException('el enlace ' . $enlace . ' debe ser numérico, como el id del documento');
        }

        $condiciones = [];
        $entrada = $origen['condiciones'] ?? [];
        if (false === is_array($entrada) || count($entrada) > self::MAX_CONDICIONES) {
            throw new InvalidArgumentException('condiciones no válidas');
        }
        foreach ($entrada as $campo => $valor) {
            $campo = self::identificador($campo, 'condición');
            if (false === isset($columnas[$campo])) {
                throw new InvalidArgumentException('la columna ' . $campo . ' no existe en ' . $tabla);
            }
            if (false === is_scalar($valor) || (is_string($valor) && mb_strlen($valor) > self::MAX_LONGITUD_VALOR)) {
                throw new InvalidArgumentException('valor no válido en la condición ' . $campo);
            }
            $condiciones[$campo] = $valor;
        }

        $agregado = $origen['agregado'] ?? 'max';
        if (false === in_array($agregado, self::AGREGADOS, true)) {
            throw new InvalidArgumentException('agregado no válido');
        }

        return [
            'tabla' => $tabla,
            'columna' => $columna,
            'enlace' => $enlace,
            'condiciones' => $condiciones,
            'agregado' => $agregado,
            'tipo' => $tipo,
        ];
    }

    /**
     * Expresión SQL del valor para cada documento de $tablaDocumento (solo identificadores validados
     * y escapados, y valores con var2str()).
     *
     * @param array<string, mixed> $origen resultado de validar()
     */
    public static function expresion(array $origen, string $tablaDocumento, string $clavePrimaria): string
    {
        $db = new DataBase();
        if ($origen['tabla'] === null) {
            return $db->escapeColumn($tablaDocumento . '.' . $origen['columna']);
        }

        $alias = self::ALIAS_TABLA;
        $sql = '(SELECT ' . strtoupper($origen['agregado']) . '(' . $db->escapeColumn($alias . '.' . $origen['columna']) . ')'
            . ' FROM ' . $db->escapeColumn($origen['tabla']) . ' ' . $db->escapeColumn($alias)
            . ' WHERE ' . $db->escapeColumn($alias . '.' . $origen['enlace'])
            . ' = ' . $db->escapeColumn($tablaDocumento . '.' . $clavePrimaria);
        foreach ($origen['condiciones'] as $campo => $valor) {
            $sql .= ' AND ' . $db->escapeColumn($alias . '.' . $campo) . ' = ' . $db->var2str($valor);
        }

        return $sql . ')';
    }

    /**
     * Valor de cada documento, en lote (como mucho UNA consulta): id => número, fecha ISO o null.
     *
     * @param array<string, mixed> $origen resultado de validar()
     * @param ModelClass[] $docs
     * @return array<int, float|string|null>
     */
    public static function valores(array $origen, array $docs): array
    {
        $resultado = [];
        if ($origen['tabla'] === null) {
            foreach ($docs as $doc) {
                $resultado[(int) $doc->id()] = self::normalizar($doc->{$origen['columna']} ?? null, $origen['tipo']);
            }
            return $resultado;
        }

        $ids = [];
        foreach ($docs as $doc) {
            $ids[] = (int) $doc->id();
            $resultado[(int) $doc->id()] = null;
        }
        if (empty($ids)) {
            return [];
        }

        $db = new DataBase();
        $consulta = DbQuery::table($origen['tabla'])
            ->selectRaw($db->escapeColumn($origen['enlace']) . ' AS zk_id, '
                . strtoupper($origen['agregado']) . '(' . $db->escapeColumn($origen['columna']) . ') AS zk_valor')
            ->whereIn($origen['enlace'], $ids);
        foreach ($origen['condiciones'] as $campo => $valor) {
            $consulta->whereEq($campo, $valor);
        }

        foreach ($consulta->groupBy($origen['enlace'])->get() as $fila) {
            $resultado[(int) $fila['zk_id']] = self::normalizar($fila['zk_valor'] ?? null, $origen['tipo']);
        }

        return $resultado;
    }

    /** Número como float; fecha en Y-m-d (o Y-m-d H:i:s); vacío o no válido ⇒ null. */
    public static function normalizar($valor, string $tipo)
    {
        if ($valor === null || $valor === '' || false === is_scalar($valor)) {
            return null;
        }

        if ($tipo === self::TIPO_NUMERO) {
            return is_numeric($valor) ? (float) $valor : null;
        }

        $marca = strtotime((string) $valor);
        if ($marca === false) {
            return null;
        }

        return date($tipo === self::TIPO_FECHA ? 'Y-m-d' : 'Y-m-d H:i:s', $marca);
    }

    /** Tipo de orden de una columna: número, fecha o fecha y hora; si no es ninguno, no vale. */
    private static function tipo(array $columnas, string $columna): string
    {
        if (false === isset($columnas[$columna])) {
            throw new InvalidArgumentException('la columna ' . $columna . ' no existe');
        }

        $tipo = strtolower(trim((string) ($columnas[$columna]['type'] ?? '')));
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|double|float|real)\b/', $tipo) === 1) {
            return self::TIPO_NUMERO;
        }
        if (preg_match('/^date\b/', $tipo) === 1) {
            return self::TIPO_FECHA;
        }
        if (preg_match('/^(datetime|timestamp)\b/', $tipo) === 1) {
            return self::TIPO_FECHA_HORA;
        }

        throw new InvalidArgumentException('la columna ' . $columna . ' no es numérica ni de fecha (' . $tipo . ')');
    }

    private static function identificador($valor, string $que): string
    {
        if (false === is_string($valor) || preg_match(self::PATRON_IDENTIFICADOR, $valor) !== 1) {
            throw new InvalidArgumentException($que . ' no válida');
        }

        return $valor;
    }

    /** El origen no puede traer claves desconocidas (p. ej. un «sql» que alguien espere que se use). */
    private static function soloClaves(array $origen, array $permitidas): void
    {
        $sobran = array_diff(array_keys($origen), $permitidas);
        if (false === empty($sobran)) {
            throw new InvalidArgumentException('claves no admitidas: ' . implode(', ', array_map('strval', $sobran)));
        }
    }
}

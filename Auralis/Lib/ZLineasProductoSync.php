<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description UPSERT batch de líneas embebidas (widget zLineasProducto). Sin lógica de cargos:
 *              el consumidor pasa ids bloqueados, resolución de precio y callback post-sync.
 *              Límite opcional de líneas ($maxLineas, bloqueadas incluidas; 0 = sin límite).
 *              Clave primaria genérica (primaryColumn() del modelo de línea; el POST sigue siendo idlinea[]).
 *              Detalle por fila (detail del widget): clave[] paralelo y detalle[<clave>][campo]; el consumidor
 *              copia los campos que permite con $aplicarDetalle. Cantidad en $campoCantidad (default cantidad).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use ReflectionNamedType;
use ReflectionProperty;

class ZLineasProductoSync
{
    /**
     * @param class-string<ModelClass> $lineaClass
     * @param list<string|null> $referencias
     * @param list<string|null> $descripciones
     * @param list<string|float|null> $cantidades
     * @param list<string|float|null> $precios
     * @param list<string|int|null> $facturables
     * @param list<string|int|null> $idlineas id de cada fila (clave primaria del modelo; '' = fila nueva)
     * @param array<int|string, string> $bloqueadas id => código documento (solo lectura)
     * @param callable|null $resolverPrecio fn(string $codcliente, string $ref): array{descripcion:string,precio:float}
     * @param callable|null $afterSync fn(string $lineaClass, int $parentId): void
     * @param int $maxLineas tope de líneas (no vacías + bloqueadas); 0 = sin límite
     * @param list<string|null> $claves clave de cada fila (clave[] del widget), paralelo a $referencias
     * @param array<string, mixed> $detalles clave => [campo => valor] (detalle[<clave>][campo] del widget)
     * @param string $campoCantidad propiedad de la línea donde se guarda la cantidad
     * @param callable|null $aplicarDetalle fn(ModelClass $linea, array $detalle): void; copia SOLO los campos permitidos
     * @param callable|null $esFilaVacia fn(?string $ref, string $desc, float $precio, array $detalle): bool
     */
    public static function sync(
        string $lineaClass,
        string $parentField,
        int $parentId,
        array $referencias,
        array $descripciones,
        array $cantidades,
        array $precios,
        string $codcliente = '',
        array $facturables = [],
        array $idlineas = [],
        array $bloqueadas = [],
        ?callable $resolverPrecio = null,
        ?callable $afterSync = null,
        int $maxLineas = 0,
        array $claves = [],
        array $detalles = [],
        string $campoCantidad = 'cantidad',
        ?callable $aplicarDetalle = null,
        ?callable $esFilaVacia = null
    ): bool {
        if ($parentId <= 0) {
            Tools::log()->warning('record-not-found');
            return false;
        }

        // El límite se comprueba antes de tocar nada: si se supera, no se guarda ni se borra ninguna línea.
        if (self::excedeLimite(
            $maxLineas,
            $referencias,
            $descripciones,
            $precios,
            $idlineas,
            $bloqueadas,
            $claves,
            $detalles,
            $esFilaVacia
        )) {
            Tools::log()->warning('auralis-lineas-limite-superado', ['%max%' => $maxLineas]);
            return false;
        }

        $campoCantidad = self::sanearCampo($campoCantidad);
        $pk = $lineaClass::primaryColumn();

        /** @var array<int|string, ModelClass> $existentes */
        $existentes = [];
        foreach ($lineaClass::all([Where::eq($parentField, $parentId)], [$pk => 'ASC'], 0, 0) as $linea) {
            $existentes[self::idDe($linea)] = $linea;
        }

        $conservadas = array_fill_keys(array_keys($bloqueadas), true);

        foreach ($referencias as $index => $value) {
            $idPosteado = self::idPosteado($idlineas, $index);
            if ($idPosteado !== '' && isset($bloqueadas[$idPosteado])) {
                continue;
            }

            $ref = empty($value) ? null : (string) $value;
            $desc = trim((string) ($descripciones[$index] ?? ''));
            $precio = (float) ($precios[$index] ?? 0);
            $detalle = self::detalleDeFila($claves, $detalles, $index);
            if (self::esVacia($ref, $desc, $precio, $detalle, $esFilaVacia)) {
                continue;
            }

            $existe = $idPosteado !== '' && isset($existentes[$idPosteado]) && false === isset($conservadas[$idPosteado]);

            /** @var ModelClass $linea */
            $linea = $existe ? $existentes[$idPosteado] : new $lineaClass();

            // Línea nueva sin referencia: se respeta la que ponga el modelo en su clear() (p. ej. mano de obra).
            // En una línea existente, la referencia vacía la borra.
            if (null === $ref && false === $existe && false === empty($linea->referencia)) {
                $ref = (string) $linea->referencia;
            }

            if (null !== $ref && $ref !== '' && null !== $resolverPrecio) {
                $resolved = $resolverPrecio($codcliente, $ref);
                if (is_array($resolved)) {
                    if ($desc === '' && ($resolved['descripcion'] ?? '') !== '') {
                        $desc = (string) $resolved['descripcion'];
                    }
                    if ($precio <= 0.0 && (float) ($resolved['precio'] ?? 0) > 0.0) {
                        $precio = (float) $resolved['precio'];
                    }
                }
            }

            $linea->{$parentField} = $parentId;
            $linea->referencia = $ref;
            $linea->descripcion = $desc === '' ? null : $desc;
            $linea->{$campoCantidad} = (float) ($cantidades[$index] ?? 1);
            $linea->precio = $precio;
            $linea->facturable = self::valorFacturable($linea, (int) ($facturables[$index] ?? 1) !== 0);

            if (null !== $aplicarDetalle) {
                $aplicarDetalle($linea, $detalle);
            }

            if (false === $linea->save()) {
                Tools::log()->error('record-save-error');
                return false;
            }

            $conservadas[self::idDe($linea)] = true;
        }

        foreach ($existentes as $id => $linea) {
            if (isset($conservadas[$id])) {
                continue;
            }

            if (false === $linea->delete()) {
                Tools::log()->error('record-deleted-error');
                return false;
            }
        }

        if (null !== $afterSync) {
            $afterSync($lineaClass, $parentId);
        }

        return true;
    }

    /**
     * True si las filas no vacías que llegan, sumadas a las bloqueadas, pasan de $maxLineas.
     * Pura (sin BD). Las filas bloqueadas reenviadas en el POST no cuentan doble.
     * Una fila con solo detalle cuenta (regla por defecto); con $esFilaVacia manda el consumidor.
     *
     * @param list<string|null> $referencias
     * @param list<string|null> $descripciones
     * @param list<string|float|null> $precios
     * @param list<string|int|null> $idlineas
     * @param array<int|string, string> $bloqueadas id => código documento
     * @param list<string|null> $claves clave de cada fila, paralelo a $referencias
     * @param array<string, mixed> $detalles clave => [campo => valor]
     * @param callable|null $esFilaVacia fn(?string $ref, string $desc, float $precio, array $detalle): bool
     */
    public static function excedeLimite(
        int $maxLineas,
        array $referencias,
        array $descripciones,
        array $precios,
        array $idlineas = [],
        array $bloqueadas = [],
        array $claves = [],
        array $detalles = [],
        ?callable $esFilaVacia = null
    ): bool {
        if ($maxLineas <= 0) {
            return false;
        }

        $total = count($bloqueadas);
        foreach ($referencias as $index => $value) {
            $idPosteado = self::idPosteado($idlineas, $index);
            if ($idPosteado !== '' && isset($bloqueadas[$idPosteado])) {
                continue;
            }

            $ref = empty($value) ? null : (string) $value;
            $desc = trim((string) ($descripciones[$index] ?? ''));
            $precio = (float) ($precios[$index] ?? 0);
            $detalle = self::detalleDeFila($claves, $detalles, $index);
            if (false === self::esVacia($ref, $desc, $precio, $detalle, $esFilaVacia)) {
                $total++;
            }
        }

        return $total > $maxLineas;
    }

    /**
     * Fila vacía por defecto: sin referencia, sin descripción, precio <= 0 y sin ningún valor en su detalle
     * ('' y '0' cuentan como vacío). No se persiste ni cuenta para el límite.
     *
     * @param array<string, string> $detalle
     */
    public static function filaVaciaPorDefecto(?string $ref, string $desc, float $precio, array $detalle = []): bool
    {
        if (null !== $ref || $desc !== '' || $precio > 0.0) {
            return false;
        }

        foreach ($detalle as $valor) {
            $valor = trim((string) $valor);
            if ($valor !== '' && $valor !== '0') {
                return false;
            }
        }

        return true;
    }

    /**
     * Sync desde POST si viene referencia[]. Transacción incluida.
     *
     * Lee `clave[]` y `detalle[<clave>][campo]` con `$request->request->getArray()`: la bolsa
     * (Core\Internal\SubRequest) devuelve el array tal cual llega de $_POST, anidado y SIN sanear
     * (ojo: `get()` serializa los arrays, no sirve aquí). Las claves de fila y los nombres de campo
     * se sanean a [A-Za-z0-9_-] y solo se conservan valores escalares; los valores NO se limpian:
     * $aplicarDetalle debe tiparlos y pasar Tools::noHtml() a los textos.
     *
     * @param class-string<ModelClass> $lineaClass
     * @param array<int|string, string> $bloqueadas
     * @param callable|null $resolverPrecio
     * @param callable|null $afterSync
     * @param int $maxLineas tope de líneas (no vacías + bloqueadas); 0 = sin límite. Si se supera, rollback.
     * @param string $campoCantidad propiedad de la línea donde se guarda la cantidad
     * @param callable|null $aplicarDetalle fn(ModelClass $linea, array $detalle): void
     * @param callable|null $esFilaVacia fn(?string $ref, string $desc, float $precio, array $detalle): bool
     */
    public static function syncFromHttp(
        bool $allowUpdate,
        Request $request,
        string $lineaClass,
        string $parentField,
        int $parentId,
        array $bloqueadas = [],
        ?callable $resolverPrecio = null,
        ?callable $afterSync = null,
        string $obsoletoWarningKey = 'auralis-lineas-formulario-obsoleto',
        int $maxLineas = 0,
        string $campoCantidad = 'cantidad',
        ?callable $aplicarDetalle = null,
        ?callable $esFilaVacia = null
    ): void {
        if (false === $allowUpdate || $parentId <= 0 || '' === $lineaClass || '' === $parentField) {
            return;
        }

        if (false === $request->request->has('referencia')) {
            return;
        }

        if (false === $request->request->has('idlinea') && [] !== $bloqueadas) {
            Tools::log()->warning($obsoletoWarningKey);
            return;
        }

        $codcliente = Tools::noHtml(trim((string) $request->request->get('codcliente_lineas', '')));
        if ($codcliente === '') {
            $codcliente = Tools::noHtml(trim((string) $request->query('codcliente', '')));
        }

        $db = new DataBase();
        $db->beginTransaction();

        $ok = self::sync(
            $lineaClass,
            $parentField,
            $parentId,
            $request->request->getArray('referencia'),
            $request->request->getArray('descripcion'),
            $request->request->getArray('cantidad'),
            $request->request->getArray('precio'),
            $codcliente,
            $request->request->getArray('facturable'),
            $request->request->getArray('idlinea'),
            $bloqueadas,
            $resolverPrecio,
            $afterSync,
            $maxLineas,
            $request->request->getArray('clave'),
            $request->request->getArray('detalle'),
            $campoCantidad,
            $aplicarDetalle,
            $esFilaVacia
        );

        if ($ok) {
            $db->commit();
            return;
        }

        $db->rollback();
    }

    /**
     * Detalle saneado de la fila $index: clave de $claves[$index] y campos escalares de $detalles[clave].
     * Sin clave (consumidores sin detail) devuelve [].
     *
     * @param list<string|null> $claves
     * @param array<string, mixed> $detalles
     *
     * @return array<string, string>
     */
    private static function detalleDeFila(array $claves, array $detalles, int|string $index): array
    {
        $clave = self::sanearClave((string) ($claves[$index] ?? ''));
        if ($clave === '' || false === isset($detalles[$clave]) || false === is_array($detalles[$clave])) {
            return [];
        }

        $detalle = [];
        foreach ($detalles[$clave] as $campo => $valor) {
            $campo = self::sanearClave((string) $campo);
            if ($campo === '' || (null !== $valor && false === is_scalar($valor))) {
                continue;
            }
            $detalle[$campo] = (string) $valor;
        }

        return $detalle;
    }

    /**
     * @param array<string, string> $detalle
     */
    private static function esVacia(?string $ref, string $desc, float $precio, array $detalle, ?callable $esFilaVacia): bool
    {
        if (null !== $esFilaVacia) {
            return (bool) $esFilaVacia($ref, $desc, $precio, $detalle);
        }

        return self::filaVaciaPorDefecto($ref, $desc, $precio, $detalle);
    }

    /**
     * Id posteado de la fila normalizado a texto ('' = fila nueva). Las claves numéricas de PHP
     * hacen que '7' y 7 apunten al mismo hueco de $existentes / $bloqueadas.
     *
     * @param list<string|int|null> $idlineas
     */
    private static function idPosteado(array $idlineas, int|string $index): string
    {
        $id = trim((string) ($idlineas[$index] ?? ''));
        return $id === '0' ? '' : $id;
    }

    private static function idDe(object $linea): string
    {
        return trim((string) $linea->id());
    }

    private static function sanearClave(string $valor): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $valor);
    }

    private static function sanearCampo(string $campo): string
    {
        $campo = (string) preg_replace('/[^a-z0-9_]/', '', $campo);
        return $campo === '' ? 'cantidad' : $campo;
    }

    /**
     * Facturable como bool si la propiedad del modelo está tipada bool (strict_types daría TypeError
     * con un int); 1/0 en los modelos sin tipar (zVeterinaria).
     */
    private static function valorFacturable(object $linea, bool $facturable): bool|int
    {
        if (property_exists($linea, 'facturable')) {
            $tipo = (new ReflectionProperty($linea, 'facturable'))->getType();
            if ($tipo instanceof ReflectionNamedType && $tipo->getName() === 'bool') {
                return $facturable;
            }
        }

        return $facturable ? 1 : 0;
    }
}

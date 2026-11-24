<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro en memoria (sin BD) de los proveedores de etiquetas que otros plugins añaden a las tarjetas de los tableros Kanban de documentos, con la petición por lote y el saneado.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Tools;
use InvalidArgumentException;
use Throwable;

/**
 * Cada plugin registra su proveedor en el init() de su Init.php. Un plugin desactivado no ejecuta
 * su Init, así que sus etiquetas desaparecen solas. Si la clave ya existe, el último gana.
 *
 * etiquetas() pide el lote a cada proveedor del tablero (una llamada por proveedor), sanea lo que
 * devuelve y lo junta en orden de registro. Un proveedor que falla o devuelve un formato no válido
 * se queda sin etiquetas en ese lote (aviso en el log) y los demás siguen.
 */
final class KanbanEtiquetaRegistry
{
    /** Longitud máxima del texto de una etiqueta. */
    public const MAX_TEXTO = 40;

    /** Longitud máxima del tooltip de una etiqueta. */
    public const MAX_TITULO = 120;

    /** Etiquetas como mucho por proveedor y documento (las demás se descartan). */
    public const MAX_POR_PROVEEDOR = 3;

    /** Colores de Bootstrap admitidos como nombre (los mismos que acepta el JS del tablero). */
    public const COLORES_BOOTSTRAP = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

    /** Hex con 3, 4, 6 u 8 dígitos. */
    private const PATRON_HEX = '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

    /** @var array<string, KanbanEtiquetaProviderInterface> */
    private static array $proveedores = [];

    /**
     * Registra un proveedor por su clave. False (y aviso en el log) si la clave no es válida
     * ([a-z0-9-]{1,40}) o si el proveedor falla al darla.
     */
    public static function register(KanbanEtiquetaProviderInterface $proveedor): bool
    {
        try {
            $clave = $proveedor->clave();
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban: proveedor de etiquetas ' . get_class($proveedor) . ' descartado: ' . $e->getMessage());
            return false;
        }

        if (preg_match(KanbanAcceso::PATRON_CLAVE_ORDEN, $clave) !== 1) {
            Tools::log('Auralis')->warning('Kanban: proveedor de etiquetas con clave no válida: ' . mb_substr($clave, 0, 60));
            return false;
        }

        self::$proveedores[$clave] = $proveedor;

        return true;
    }

    /**
     * Proveedores que aplican al tablero, en orden de registro. Uno que falla al decir sus
     * tableros se omite (queda en el log).
     *
     * @return array<string, KanbanEtiquetaProviderInterface>
     */
    public static function paraTablero(string $tablero): array
    {
        $resultado = [];
        foreach (self::$proveedores as $clave => $proveedor) {
            try {
                $tableros = $proveedor->tableros();
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('Kanban: proveedor de etiquetas ' . $clave . ': ' . $e->getMessage());
                continue;
            }

            if (is_array($tableros) && in_array($tablero, $tableros, true)) {
                $resultado[$clave] = $proveedor;
            }
        }

        return $resultado;
    }

    /**
     * Etiquetas de otros plugins para un lote de documentos ya visibles para el usuario: una llamada
     * por proveedor del tablero, saneadas y en orden de registro. Sin proveedores o sin ids no
     * llama a nadie.
     *
     * @param int[] $ids
     * @return array<int, array<int, array{label: string, color: string, title?: string}>> id => etiquetas
     */
    public static function etiquetas(string $tablero, array $ids): array
    {
        $pedidos = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) {
                $pedidos[$id] = true;
            }
        }

        $proveedores = empty($pedidos) ? [] : self::paraTablero($tablero);
        $resultado = [];
        foreach ($proveedores as $clave => $proveedor) {
            try {
                $lote = self::sanearLote($proveedor->etiquetas($tablero, array_keys($pedidos)), $pedidos);
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('Kanban ' . $tablero . ': etiquetas «' . $clave . '» descartadas: ' . $e->getMessage());
                continue;
            }

            foreach ($lote as $id => $etiquetas) {
                $resultado[$id] = array_merge($resultado[$id] ?? [], $etiquetas);
            }
        }

        return $resultado;
    }

    /** @return array<string, KanbanEtiquetaProviderInterface> */
    public static function all(): array
    {
        return self::$proveedores;
    }

    /** Vacía el registro (solo tests). */
    public static function reset(): void
    {
        self::$proveedores = [];
    }

    /**
     * Texto plano de una línea: sin etiquetas HTML, sin caracteres de control y recortado.
     * '' si no es texto UTF-8 válido o queda vacío.
     */
    public static function textoPlano(string $texto, int $maximo): string
    {
        $limpio = preg_replace('/[\p{Cc}\s]+/u', ' ', strip_tags($texto));

        return $limpio === null ? '' : mb_substr(trim($limpio), 0, $maximo);
    }

    /** Hex o nombre de color de Bootstrap; '' si es cualquier otra cosa. */
    public static function colorSeguro(string $color): string
    {
        $color = strtolower(trim($color));
        if (in_array($color, self::COLORES_BOOTSTRAP, true) || preg_match(self::PATRON_HEX, $color) === 1) {
            return $color;
        }

        return '';
    }

    /**
     * Valida el formato de lo que devolvió un proveedor y sanea cada etiqueta. Cualquier fallo de
     * formato invalida el lote entero de ESE proveedor (InvalidArgumentException).
     *
     * @param mixed $lote
     * @param array<int, true> $pedidos ids pedidos
     * @return array<int, array<int, array{label: string, color: string, title?: string}>>
     */
    private static function sanearLote($lote, array $pedidos): array
    {
        if (false === is_array($lote)) {
            throw new InvalidArgumentException('formato no válido: se esperaba un array');
        }

        $resultado = [];
        foreach ($lote as $id => $lista) {
            if (false === is_array($lista)) {
                throw new InvalidArgumentException('formato no válido en el documento ' . mb_substr((string) $id, 0, 20));
            }

            $id = (int) $id;
            $etiquetas = [];
            foreach ($lista as $etiqueta) {
                $limpia = self::sanearEtiqueta($etiqueta);
                if ($limpia !== null && count($etiquetas) < self::MAX_POR_PROVEEDOR) {
                    $etiquetas[] = $limpia;
                }
            }

            // un id que no se pidió nunca llega a la tarjeta
            if (isset($pedidos[$id]) && false === empty($etiquetas)) {
                $resultado[$id] = $etiquetas;
            }
        }

        return $resultado;
    }

    /**
     * Etiqueta saneada, o null si queda sin texto.
     *
     * @param mixed $etiqueta
     * @return array{label: string, color: string, title?: string}|null
     */
    private static function sanearEtiqueta($etiqueta): ?array
    {
        if (false === is_array($etiqueta) || false === is_string($etiqueta['label'] ?? null)) {
            throw new InvalidArgumentException('formato no válido: cada etiqueta necesita un label de texto');
        }

        foreach (['color', 'title'] as $campo) {
            if (isset($etiqueta[$campo]) && false === is_string($etiqueta[$campo])) {
                throw new InvalidArgumentException('formato no válido: ' . $campo . ' debe ser texto');
            }
        }

        $texto = self::textoPlano($etiqueta['label'], self::MAX_TEXTO);
        if ($texto === '') {
            return null;
        }

        $limpia = ['label' => $texto, 'color' => self::colorSeguro($etiqueta['color'] ?? '')];
        $titulo = self::textoPlano($etiqueta['title'] ?? '', self::MAX_TITULO);
        if ($titulo !== '') {
            $limpia['title'] = $titulo;
        }

        return $limpia;
    }
}

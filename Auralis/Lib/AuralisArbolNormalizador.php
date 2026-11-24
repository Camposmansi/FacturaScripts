<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Normaliza los nodos de un proveedor AuralisArbol al contrato JSON (árbol único, tipos y longitudes acotadas).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use stdClass;

final class AuralisArbolNormalizador
{
    public const MAX_NODOS = 2000;
    public const TIPOS_BADGE = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];

    private const MAX_BADGES = 6;
    private const MAX_VALORES = 12;

    /**
     * Devuelve solo los nodos alcanzables desde la raíz (el primero con padre vacío), en anchura:
     * descarta huérfanos, ciclos, ids repetidos y raíces extra. Si se supera $maxNodos, corta y
     * marca tieneHijos en los padres que han perdido hijos.
     *
     * @param array<int, mixed> $nodos
     *
     * @return array{nodos: array<int, array<string, mixed>>, truncado: bool}
     */
    public static function normalizar(array $nodos, int $maxNodos = self::MAX_NODOS): array
    {
        $raiz = null;
        $porId = [];
        $hijos = [];
        foreach ($nodos as $crudo) {
            if (!is_array($crudo)) {
                continue;
            }

            $nodo = self::nodo($crudo);
            if ($nodo === null || isset($porId[$nodo['id']])) {
                continue;
            }

            if ($nodo['padre'] === null) {
                if ($raiz !== null) {
                    continue;
                }
                $raiz = $nodo['id'];
            } else {
                $hijos[$nodo['padre']][] = $nodo['id'];
            }
            $porId[$nodo['id']] = $nodo;
        }

        if ($raiz === null) {
            return ['nodos' => [], 'truncado' => false];
        }

        $salida = [];
        $posicion = [];
        $truncado = false;
        $cola = [$raiz];
        while (!empty($cola)) {
            $id = array_shift($cola);
            if (isset($posicion[$id])) {
                continue;
            }

            if (count($salida) >= max(1, $maxNodos)) {
                $truncado = true;
                $padre = $porId[$id]['padre'];
                if ($padre !== null && isset($posicion[$padre])) {
                    $salida[$posicion[$padre]]['tieneHijos'] = true;
                }
                continue;
            }

            $posicion[$id] = count($salida);
            $salida[] = $porId[$id];
            foreach ($hijos[$id] ?? [] as $hijo) {
                $cola[] = $hijo;
            }
        }

        return ['nodos' => $salida, 'truncado' => $truncado];
    }

    /**
     * @param array<string, mixed> $crudo
     *
     * @return array<string, mixed>|null
     */
    private static function nodo(array $crudo): ?array
    {
        $id = self::texto($crudo['id'] ?? null, 200);
        if ($id === '') {
            return null;
        }

        $padre = self::texto($crudo['padre'] ?? null, 200);
        $cantidad = $crudo['cantidad'] ?? null;

        return [
            'id' => $id,
            'padre' => $padre === '' || $padre === $id ? null : $padre,
            'etiqueta' => self::texto($crudo['etiqueta'] ?? null, 120),
            'subtitulo' => self::texto($crudo['subtitulo'] ?? null, 160),
            'cantidad' => is_numeric($cantidad) ? (float)$cantidad : null,
            'valores' => self::valores($crudo['valores'] ?? null),
            'badges' => self::badges($crudo['badges'] ?? null),
            'alerta' => self::alerta($crudo['alerta'] ?? null),
            'url' => self::url($crudo['url'] ?? null),
            'tieneHijos' => (bool)($crudo['tieneHijos'] ?? false),
        ];
    }

    private static function texto(mixed $valor, int $max): string
    {
        if (!is_scalar($valor) || is_bool($valor)) {
            return '';
        }

        $texto = trim(mb_scrub((string)$valor, 'UTF-8'));
        // Saltos y controles fuera: las tarjetas SVG son de una línea.
        $texto = (string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);

        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
    }

    /**
     * @return array<string, string|int|float>|stdClass objeto vacío para que el JSON sea {} y no []
     */
    private static function valores(mixed $valores): array|stdClass
    {
        $salida = [];
        if (is_array($valores)) {
            foreach ($valores as $clave => $valor) {
                if (count($salida) >= self::MAX_VALORES) {
                    break;
                }

                $clave = self::texto($clave, 40);
                if ($clave === '' || !is_scalar($valor) || is_bool($valor)) {
                    continue;
                }
                $salida[$clave] = is_string($valor) ? self::texto($valor, 80) : $valor;
            }
        }

        return empty($salida) ? new stdClass() : $salida;
    }

    /**
     * @return array<int, array{tipo: string, texto: string, icono: string}>
     */
    private static function badges(mixed $badges): array
    {
        $salida = [];
        if (!is_array($badges)) {
            return $salida;
        }

        foreach ($badges as $badge) {
            if (count($salida) >= self::MAX_BADGES) {
                break;
            }
            if (!is_array($badge)) {
                continue;
            }

            $texto = self::texto($badge['texto'] ?? null, 40);
            $icono = self::texto($badge['icono'] ?? null, 80);
            if ($texto === '' && $icono === '') {
                continue;
            }

            $tipo = self::texto($badge['tipo'] ?? null, 20);
            $salida[] = [
                'tipo' => in_array($tipo, self::TIPOS_BADGE, true) ? $tipo : 'secondary',
                'texto' => $texto,
                // Solo clases Font Awesome: el valor acaba en un atributo class.
                'icono' => 1 === preg_match('/^fa-[a-z0-9-]+( fa-[a-z0-9-]+)*$/', $icono) ? $icono : '',
            ];
        }

        return $salida;
    }

    /**
     * null = sin aviso; '' = aviso sin texto (el proveedor envió true); texto = aviso con mensaje.
     */
    private static function alerta(mixed $alerta): ?string
    {
        if ($alerta === true) {
            return '';
        }

        $texto = self::texto($alerta, 200);
        return $texto === '' ? null : $texto;
    }

    /**
     * Solo rutas del propio ERP (Controlador?x=y o /ruta) y http(s). Nada de javascript:, data: ni //host.
     */
    private static function url(mixed $url): string
    {
        $url = self::texto($url, 500);
        if ($url === '' || 1 === preg_match('/\s/', $url)) {
            return '';
        }

        if (1 === preg_match('#^[A-Za-z][A-Za-z0-9_]*(\?.*)?$#', $url)
            || 1 === preg_match('#^/(?!/)#', $url)
            || 1 === preg_match('#^https?://[^/]#i', $url)) {
            return $url;
        }

        return '';
    }
}

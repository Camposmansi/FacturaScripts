<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Puerta única de acceso a los tableros Kanban (ajuste, tablero, permisos) y saneo de filtros y del orden.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use Throwable;

final class KanbanAcceso
{
    /** Longitud máxima de un filtro de texto. */
    private const MAX_LONGITUD_TEXTO = 50;

    /** Clave de un criterio de orden. */
    public const PATRON_CLAVE_ORDEN = '/^[a-z0-9-]{1,40}$/';

    /**
     * Devuelve el tablero si SU ajuste está activo (también para admin), existe,
     * hay usuario y este tiene acceso a la página de lectura del Core. Si no, null.
     * «Solo sus datos» lo aplica después el proveedor al leer y al mover.
     */
    public static function tablero(string $key, ?User $user): ?KanbanProviderInterface
    {
        if ($user === null || false === KanbanAjustes::activo($key)) {
            return null;
        }

        $provider = KanbanRegistry::get($key);
        if ($provider === null) {
            return null;
        }

        $permisos = new ControllerPermissions($user, $provider->readPermission());

        return $permisos->allowAccess ? $provider : null;
    }

    /** El usuario puede mover tarjetas (allowUpdate de la página de edición). */
    public static function puedeMover(KanbanProviderInterface $provider, User $user): bool
    {
        $permisos = new ControllerPermissions($user, $provider->editPermission());

        return $permisos->allowUpdate;
    }

    /**
     * Sanea los filtros de entrada según allowedFilters(): descarta lo no admitido,
     * int con cast, date solo ISO válida (Y-m-d) y string recortado.
     *
     * @param array<string, mixed> $entrada
     * @return array<string, string>
     */
    public static function filtros(KanbanProviderInterface $provider, array $entrada): array
    {
        $filtros = [];
        foreach ($provider->allowedFilters() as $nombre => $tipo) {
            if (false === isset($entrada[$nombre]) || false === is_scalar($entrada[$nombre])) {
                continue;
            }

            $valor = trim((string) $entrada[$nombre]);
            if ($valor === '') {
                continue;
            }

            switch ($tipo) {
                case 'int':
                    if (preg_match('/^-?\d{1,10}$/', $valor) === 1) {
                        $filtros[$nombre] = (string) (int) $valor;
                    }
                    break;

                case 'date':
                    $fecha = AuralisSecurity::sanitizeIsoDate($valor);
                    if ($fecha !== null) {
                        $filtros[$nombre] = $fecha;
                    }
                    break;

                case 'string':
                    $filtros[$nombre] = mb_substr($valor, 0, self::MAX_LONGITUD_TEXTO);
                    break;
            }
        }

        return $filtros;
    }

    /**
     * Criterios de orden válidos del tablero (KanbanOrdenableInterface), sin repetidos y en su orden.
     * [] si el tablero no ordena o si falla al dar sus opciones (queda en el log).
     *
     * @return array<int, array{clave: string, etiqueta: string, dir: string}>
     */
    public static function opcionesOrden(KanbanProviderInterface $provider): array
    {
        if (false === $provider instanceof KanbanOrdenableInterface) {
            return [];
        }

        try {
            $opciones = $provider->opcionesOrden();
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban ' . $provider->key() . ': criterios de orden: ' . $e->getMessage());
            return [];
        }

        $validas = [];
        foreach ($opciones as $opcion) {
            $clave = is_array($opcion) && is_string($opcion['clave'] ?? null) ? $opcion['clave'] : '';
            $dir = is_array($opcion) && is_string($opcion['dir'] ?? null) ? $opcion['dir'] : '';
            if (preg_match(self::PATRON_CLAVE_ORDEN, $clave) !== 1 || isset($validas[$clave])
                || false === in_array($dir, [KanbanOrdenableInterface::ASC, KanbanOrdenableInterface::DESC], true)) {
                continue;
            }

            $validas[$clave] = ['clave' => $clave, 'etiqueta' => (string) ($opcion['etiqueta'] ?? $clave), 'dir' => $dir];
        }

        return array_values($validas);
    }

    /**
     * Criterio y dirección pedidos, contra la lista cerrada de opcionesOrden(): una clave desconocida
     * (o que no es texto) cae al primer criterio con SU dirección por defecto; una dirección
     * distinta de 'asc' | 'desc' cae a la del criterio. Nunca devuelve nada que venga de la petición
     * sin pasar por esa lista. [] si el tablero no ordena.
     *
     * @param array<string, mixed> $entrada
     * @return array{orden?: string, dir?: string}
     */
    public static function orden(KanbanProviderInterface $provider, array $entrada): array
    {
        $opciones = self::opcionesOrden($provider);
        if (empty($opciones)) {
            return [];
        }

        $pedida = $entrada[KanbanOrdenableInterface::PARAM_ORDEN] ?? null;
        $elegida = $opciones[0];
        $valida = false;
        foreach ($opciones as $opcion) {
            if (is_string($pedida) && $opcion['clave'] === trim($pedida)) {
                $elegida = $opcion;
                $valida = true;
                break;
            }
        }

        $dir = $entrada[KanbanOrdenableInterface::PARAM_DIR] ?? null;
        $dir = is_string($dir) ? strtolower(trim($dir)) : '';
        if (false === $valida || false === in_array($dir, [KanbanOrdenableInterface::ASC, KanbanOrdenableInterface::DESC], true)) {
            $dir = $elegida['dir'];
        }

        return [KanbanOrdenableInterface::PARAM_ORDEN => $elegida['clave'], KanbanOrdenableInterface::PARAM_DIR => $dir];
    }
}

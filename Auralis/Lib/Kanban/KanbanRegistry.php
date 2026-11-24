<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro en memoria (sin BD) de los proveedores de tableros Kanban, indexados por su clave.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

final class KanbanRegistry
{
    /** @var array<string, KanbanProviderInterface> */
    private static array $providers = [];

    /** Registra un proveedor por su key(); si la clave ya existe, el último gana. */
    public static function register(KanbanProviderInterface $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?KanbanProviderInterface
    {
        return self::$providers[$key] ?? null;
    }

    /**
     * @return array<string, KanbanProviderInterface> ordenado por clave
     */
    public static function all(): array
    {
        $providers = self::$providers;
        ksort($providers);

        return $providers;
    }

    /** Vacía el registro (solo tests). */
    public static function reset(): void
    {
        self::$providers = [];
    }
}

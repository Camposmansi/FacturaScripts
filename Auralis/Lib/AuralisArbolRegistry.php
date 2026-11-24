<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro central de proveedores del visor de árbol AuralisArbol por clave.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use InvalidArgumentException;

final class AuralisArbolRegistry
{
    public const PATRON_CLAVE = '/^[a-z0-9][a-z0-9_-]{0,49}$/';

    /** @var array<string, AuralisArbolProviderInterface> */
    private static array $providers = [];

    public static function register(AuralisArbolProviderInterface $provider): void
    {
        $clave = $provider->clave();
        if (1 !== preg_match(self::PATRON_CLAVE, $clave)) {
            throw new InvalidArgumentException('Clave de proveedor de árbol no válida: ' . $clave);
        }

        self::$providers[$clave] = $provider;
    }

    public static function get(string $clave): ?AuralisArbolProviderInterface
    {
        return self::$providers[$clave] ?? null;
    }

    /**
     * @return string[]
     */
    public static function claves(): array
    {
        $claves = array_keys(self::$providers);
        sort($claves);

        return $claves;
    }

    public static function reset(): void
    {
        self::$providers = [];
    }
}

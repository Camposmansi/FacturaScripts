<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Registro en memoria (sin BD) de los proveedores de grupos de asistentes del calendario, indexados por su clave.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use InvalidArgumentException;

final class CalendarParticipantGroupRegistry
{
    public const PATRON_CLAVE = '/^[a-z][a-z0-9_-]{1,39}$/';

    /** @var array<string, CalendarParticipantGroupProviderInterface> */
    private static array $providers = [];

    /** Registra un proveedor por su clave(); si la clave ya existe, el último gana. */
    public static function register(CalendarParticipantGroupProviderInterface $provider): void
    {
        $clave = $provider->clave();
        if (1 !== preg_match(self::PATRON_CLAVE, $clave)) {
            throw new InvalidArgumentException('Clave de proveedor de grupos de asistentes no válida: ' . $clave);
        }

        self::$providers[$clave] = $provider;
    }

    public static function get(string $clave): ?CalendarParticipantGroupProviderInterface
    {
        return self::$providers[$clave] ?? null;
    }

    /**
     * @return array<string, CalendarParticipantGroupProviderInterface> ordenado por clave
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

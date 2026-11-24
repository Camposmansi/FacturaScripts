<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro central de proveedores de notificaciones por origen_tipo del calendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

final class CalendarNotificationRegistry
{
    /** @var array<string, CalendarNotificationProviderInterface> */
    private static array $providers = [];

    private static bool $generalRegistered = false;

    public static function register(CalendarNotificationProviderInterface $provider): void
    {
        self::$providers[$provider->origenTipo()] = $provider;
    }

    public static function ensureGeneralProvider(): void
    {
        if (self::$generalRegistered) {
            return;
        }

        self::register(new GeneralCalendarNotificationProvider());
        self::$generalRegistered = true;
    }

    public static function get(string $origenTipo): CalendarNotificationProviderInterface
    {
        self::ensureGeneralProvider();

        if (isset(self::$providers[$origenTipo])) {
            return self::$providers[$origenTipo];
        }

        return self::$providers['general'] ?? new GeneralCalendarNotificationProvider();
    }

    /**
     * @return string[]
     */
    public static function origenTipos(): array
    {
        self::ensureGeneralProvider();

        $tipos = array_keys(self::$providers);
        sort($tipos);

        return $tipos;
    }

    /**
     * @return array<string, string>
     */
    public static function availableNotificationsFor(string $origenTipo): array
    {
        return self::get($origenTipo)->availableNotifications();
    }

    public static function isNotificationAllowed(string $origenTipo, string $notificationName): bool
    {
        $available = self::availableNotificationsFor($origenTipo);

        return isset($available[$notificationName]);
    }

    public static function reset(): void
    {
        self::$providers = [];
        self::$generalRegistered = false;
    }
}

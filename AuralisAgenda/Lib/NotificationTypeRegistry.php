<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro central de tipos de aviso in-app. calendar y changelog van incorporados.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use InvalidArgumentException;

final class NotificationTypeRegistry
{
    /** La columna tipo es varchar(20). */
    public const PATRON_CLAVE = '/^[a-z][a-z0-9_]{0,19}$/';

    /** @var array<string, NotificationTypeInterface> */
    private static array $tipos = [];

    private static bool $incorporados = false;

    public static function register(NotificationTypeInterface $tipo): void
    {
        $clave = $tipo->clave();
        if (1 !== preg_match(self::PATRON_CLAVE, $clave)) {
            throw new InvalidArgumentException('Clave de tipo de aviso no válida: ' . $clave);
        }

        self::$tipos[$clave] = $tipo;
    }

    public static function ensureIncorporados(): void
    {
        if (self::$incorporados) {
            return;
        }

        self::$incorporados = true;
        self::register(NotificationType::calendario());
        self::register(NotificationType::novedades());
    }

    public static function get(string $clave): ?NotificationTypeInterface
    {
        self::ensureIncorporados();

        return self::$tipos[$clave] ?? null;
    }

    /**
     * @return string[]
     */
    public static function claves(): array
    {
        self::ensureIncorporados();

        $claves = array_keys(self::$tipos);
        sort($claves);

        return $claves;
    }

    public static function reset(): void
    {
        self::$tipos = [];
        self::$incorporados = false;
    }
}

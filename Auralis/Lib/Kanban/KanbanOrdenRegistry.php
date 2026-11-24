<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro en memoria (sin BD) de los criterios de orden que otros plugins añaden a los tableros Kanban de documentos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Tools;
use Throwable;

/**
 * Cada plugin registra sus criterios en el init() de su Init.php. Un plugin desactivado no ejecuta
 * su Init, así que sus criterios desaparecen solos. Si la clave ya existe, el último gana.
 */
final class KanbanOrdenRegistry
{
    /** @var array<string, KanbanCriterioOrdenInterface> */
    private static array $criterios = [];

    /**
     * Registra un criterio por su clave. False (y aviso en el log) si la clave no es válida
     * ([a-z0-9-]{1,40}) o si el criterio falla al darla.
     */
    public static function register(KanbanCriterioOrdenInterface $criterio): bool
    {
        try {
            $clave = $criterio->clave();
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban: criterio de orden ' . get_class($criterio) . ' descartado: ' . $e->getMessage());
            return false;
        }

        if (preg_match(KanbanAcceso::PATRON_CLAVE_ORDEN, $clave) !== 1) {
            Tools::log('Auralis')->warning('Kanban: criterio de orden con clave no válida: ' . mb_substr($clave, 0, 60));
            return false;
        }

        self::$criterios[$clave] = $criterio;

        return true;
    }

    /**
     * Criterios que aplican al tablero, en orden de registro. Un criterio que falla al
     * decir sus tableros se omite (queda en el log).
     *
     * @return array<string, KanbanCriterioOrdenInterface>
     */
    public static function paraTablero(string $tablero): array
    {
        $resultado = [];
        foreach (self::$criterios as $clave => $criterio) {
            try {
                $tableros = $criterio->tableros();
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('Kanban: criterio de orden ' . $clave . ': ' . $e->getMessage());
                continue;
            }

            if (is_array($tableros) && in_array($tablero, $tableros, true)) {
                $resultado[$clave] = $criterio;
            }
        }

        return $resultado;
    }

    /** @return array<string, KanbanCriterioOrdenInterface> */
    public static function all(): array
    {
        return self::$criterios;
    }

    /** Vacía el registro (solo tests). */
    public static function reset(): void
    {
        self::$criterios = [];
    }
}

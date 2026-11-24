<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Limpia descripciones de eventos contaminadas con franja horaria de subtitle.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;

class LimpiarDescripcionesFranjaHorariaCalendario extends MigrationClass
{
    public const MIGRATION_NAME = 'limpiar_descripciones_franja_calendario_v2026_09';

    public function run(): void
    {
        $table = 'auralis_calendar_events';
        if (false === $this->db()->tableExists($table)) {
            return;
        }

        $columns = $this->db()->getColumns($table);
        if (false === isset($columns['id']) || false === isset($columns['descripcion'])) {
            return;
        }

        // SQL directo: instanciar el modelo aquí dispara DbUpdater y la FK a tipos
        // antes de que exista auralis_calendar_event_types (errno 150 al actualizar).
        $rows = $this->db()->select(
            'SELECT id, descripcion FROM ' . $table
            . " WHERE descripcion IS NOT NULL AND descripcion <> ''"
        );
        foreach ($rows as $row) {
            $raw = trim((string)($row['descripcion'] ?? ''));
            if ($raw === '') {
                continue;
            }

            $cleaned = self::limpiarFranja($raw);
            if ($cleaned === $raw) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $valor = $cleaned === '' ? 'NULL' : $this->db()->var2str($cleaned);
            $this->db()->exec('UPDATE ' . $table . ' SET descripcion = ' . $valor . ' WHERE id = ' . $id);
        }
    }

    /**
     * Quita prefijos/solo-franja "HH:MM - HH:MM" o "HH:MM - HH:MM | " repetidos.
     */
    public static function limpiarFranja(string $texto): string
    {
        $out = trim($texto);
        $pattern = '/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}(?:\s*\|\s*)?/u';

        // Hasta 20 pasadas por si se acumularon varias franjas al inicio.
        for ($i = 0; $i < 20; ++$i) {
            $next = preg_replace($pattern, '', $out, 1);
            if (!is_string($next) || $next === $out) {
                break;
            }
            $out = trim($next);
        }

        // Descripción que era solo la franja (sin notas reales).
        if (preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/u', $out) === 1) {
            return '';
        }

        return $out;
    }
}

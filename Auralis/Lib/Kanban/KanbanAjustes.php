<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Ajuste on/off de cada tablero Kanban de documentos (encendidos si el ajuste no existía; uno guardado no se toca).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\Providers\DocumentoKanbanTipos;

/**
 * Una clave por tablero en el grupo auralis_avisos (tabla DocumentoKanbanTipos: los 4 tableros,
 * presupuestos y pedidos de cliente y de proveedor). Las claves de tableros retirados (albaranes y
 * facturas) que sigan guardadas en la BD ya no se leen.
 * Un tablero registrado por otro plugin no tiene ajuste aquí: su plugin decide si lo registra.
 */
final class KanbanAjustes
{
    public const GRUPO = 'auralis_avisos';

    /** Clave histórica del tablero de presupuestos de cliente (no cambia). */
    public const CLAVE_PRESUPUESTOS = 'kanban_presupuestos';

    /** Clave del ajuste de un tablero, o null si Auralis no le pone ajuste. */
    public static function clave(string $tablero): ?string
    {
        $definicion = DocumentoKanbanTipos::definicion($tablero);

        return $definicion === null ? null : (string) $definicion['ajuste'];
    }

    /**
     * Claves de todos los ajustes de tablero, en el orden de la tabla de tipos.
     *
     * @return string[]
     */
    public static function claves(): array
    {
        $claves = [];
        foreach (DocumentoKanbanTipos::claves() as $tablero) {
            $claves[] = (string) self::clave($tablero);
        }

        return $claves;
    }

    /** El tablero está encendido. Sin ajuste propio (tablero de otro plugin) cuenta como encendido. */
    public static function activo(string $tablero): bool
    {
        $clave = self::clave($tablero);
        if ($clave === null) {
            return true;
        }

        return filter_var(Tools::settings(self::GRUPO, $clave, true), FILTER_VALIDATE_BOOLEAN);
    }

    /** Fija el valor por defecto (true) solo en memoria de cada ajuste que no exista. */
    public static function asegurarValorPorDefecto(): void
    {
        foreach (self::claves() as $clave) {
            if (Tools::settings(self::GRUPO, $clave) === null) {
                Tools::settingsSet(self::GRUPO, $clave, true);
            }
        }
    }

    /**
     * Guarda en la BD encendido ('1') cada ajuste de tablero que aún no exista (instalación nueva o
     * actualización desde una versión sin el ajuste). Un ajuste ya guardado, true o false, no se toca.
     * Solo escribe el grupo auralis_avisos (no Tools::settingsSave(), que guardaría todos los grupos).
     * Para Init::update(): hace consultas.
     */
    public static function persistirValorPorDefecto(): void
    {
        $ajustes = new Settings();
        if (false === $ajustes->load(self::GRUPO)) {
            $ajustes->name = self::GRUPO;
        }

        $propiedades = $ajustes->getProperties();
        $faltan = [];
        foreach (self::claves() as $clave) {
            if (!isset($propiedades[$clave])) {
                $faltan[] = $clave;
            }
        }
        if (empty($faltan)) {
            return;
        }

        foreach ($faltan as $clave) {
            $ajustes->setProperty($clave, '1');
        }
        if (false === $ajustes->save()) {
            Tools::log('Auralis')->warning('Auralis: no se pudieron guardar los ajustes de los tableros.');
            return;
        }

        foreach ($faltan as $clave) {
            Tools::settingsSet(self::GRUPO, $clave, true);
        }
    }
}

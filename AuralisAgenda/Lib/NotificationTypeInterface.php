<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Contrato de un tipo de aviso in-app de Auralis (clave, permiso, canales y audiencia).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

interface NotificationTypeInterface
{
    public function clave(): string;

    /** Clave i18n del nombre visible del tipo. */
    public function texto(): string;

    public function icono(): string;

    /** Página del Core que hay que poder ver. Vacío: cualquier usuario con sesión. */
    public function permiso(): string;

    /** personal: destinatarios fijos. suscribible: se reparte en el bloque de suscripciones. */
    public function audiencia(): string;

    public function ventanaEmergente(): bool;

    public function push(): bool;

    public function insistente(): bool;

    /** Minutos entre repeticiones del sonido. 0 si el tipo no es insistente. */
    public function intervaloMinutos(): int;

    /** Segundos de cada ráfaga. 0 si el tipo no es insistente. */
    public function duracionSegundos(): int;

    public function cierreAutomatico(): bool;

    /** Clave i18n del texto genérico de push, sin importes, nombres ni DNI. */
    public function textoPush(): string;

    /** false: el tipo existe en el registro pero emit() no crea filas (novedades). */
    public function emisible(): bool;
}

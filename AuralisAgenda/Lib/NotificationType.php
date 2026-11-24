<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Definición inmutable de un tipo de aviso. Los plugins no necesitan una clase por tipo.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisNotification;

final class NotificationType implements NotificationTypeInterface
{
    public const AUDIENCIA_PERSONAL = 'personal';

    public const AUDIENCIA_SUSCRIBIBLE = 'suscribible';

    public function __construct(
        private string $clave,
        private string $texto,
        private string $icono,
        private string $permiso,
        private string $audiencia,
        private bool $ventanaEmergente,
        private bool $push,
        private bool $insistente,
        private bool $cierreAutomatico,
        private string $textoPush,
        private bool $emisible,
        private int $intervaloMinutos,
        private int $duracionSegundos
    ) {
        if (false === $this->insistente) {
            $this->intervaloMinutos = 0;
            $this->duracionSegundos = 0;

            return;
        }

        $this->intervaloMinutos = max(1, min(60, $this->intervaloMinutos));
        $this->duracionSegundos = max(1, min(30, $this->duracionSegundos));
    }

    public static function calendario(): self
    {
        return new self(
            AuralisNotification::TYPE_CALENDAR,
            'auralis-tipo-aviso-calendar',
            'fa-solid fa-calendar-days',
            'CalendarioUsuario',
            self::AUDIENCIA_PERSONAL,
            true,
            true,
            false,
            false,
            'auralis-push-generico-calendar',
            true,
            0,
            0
        );
    }

    /**
     * Las novedades viven en novedades.json. El tipo está registrado para que
     * test() lo acepte, pero emit() no crea filas en la campana.
     */
    public static function novedades(): self
    {
        return new self(
            AuralisNotification::TYPE_CHANGELOG,
            'auralis-inapp-tab-changelog',
            'fa-solid fa-bullhorn',
            '',
            self::AUDIENCIA_PERSONAL,
            false,
            false,
            false,
            false,
            '',
            false,
            0,
            0
        );
    }

    public function clave(): string
    {
        return $this->clave;
    }

    public function texto(): string
    {
        return $this->texto;
    }

    public function icono(): string
    {
        return $this->icono;
    }

    public function permiso(): string
    {
        return $this->permiso;
    }

    public function audiencia(): string
    {
        return $this->audiencia;
    }

    public function ventanaEmergente(): bool
    {
        return $this->ventanaEmergente;
    }

    public function push(): bool
    {
        return $this->push;
    }

    public function insistente(): bool
    {
        return $this->insistente;
    }

    public function intervaloMinutos(): int
    {
        return $this->intervaloMinutos;
    }

    public function duracionSegundos(): int
    {
        return $this->duracionSegundos;
    }

    public function cierreAutomatico(): bool
    {
        return $this->cierreAutomatico;
    }

    public function textoPush(): string
    {
        return $this->textoPush;
    }

    public function emisible(): bool
    {
        return $this->emisible;
    }
}

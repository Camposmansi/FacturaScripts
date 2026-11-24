<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Contrato enganchable para plantillas y destinatarios de recordatorios de calendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;

interface CalendarNotificationProviderInterface
{
    public function origenTipo(): string;

    /**
     * @return array<string, string> notification_name => etiqueta
     */
    public function availableNotifications(): array;

    /**
     * @return array{email: string, name: string}|null
     */
    public function resolveRecipient(AuralisCalendarEvent $event): ?array;

    /**
     * @return array<string, mixed>
     */
    public function buildMailParams(AuralisCalendarEvent $event): array;
}

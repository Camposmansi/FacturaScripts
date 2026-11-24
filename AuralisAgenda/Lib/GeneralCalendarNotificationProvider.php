<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Proveedor genérico de recordatorios de calendario (origen general).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;

class GeneralCalendarNotificationProvider implements CalendarNotificationProviderInterface
{
    public const NOTIFICATION_GENERAL = 'auralis-calendar-reminder';

    public function origenTipo(): string
    {
        return 'general';
    }

    public function availableNotifications(): array
    {
        return [
            self::NOTIFICATION_GENERAL => Tools::trans('auralis-calendar-reminder'),
        ];
    }

    public function resolveRecipient(AuralisCalendarEvent $event): ?array
    {
        $nick = trim((string)($event->asignado ?: $event->nick));
        if ($nick === '') {
            return null;
        }

        $user = new User();
        if (false === $user->load($nick)) {
            return null;
        }

        $email = trim((string)($user->email ?? ''));
        if ($email === '' || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'email' => $email,
            'name' => (string)$user->nick,
        ];
    }

    public function buildMailParams(AuralisCalendarEvent $event): array
    {
        $fecha = !empty($event->fecha) ? date('d-m-Y', strtotime((string)$event->fecha)) : '';
        $hora = !empty($event->todo_dia)
            ? Tools::trans('all-day')
            : mb_substr((string)$event->hora_inicio, 0, 5);

        $titulo = (string)$event->titulo;
        $descripcion = (string)($event->descripcion ?? '');
        $asignado = (string)($event->asignado ?: $event->nick);

        // Se entregan varios juegos de claves con el mismo valor: las traducciones usan {titulo}, {title}
        // o nombres propios del idioma (ca/va, eu, it, pl, tr) y MailNotifier solo sustituye las que existan.
        return [
            'titulo' => $titulo,
            'fecha' => $fecha,
            'hora' => $hora,
            'descripcion' => $descripcion,
            'asignado' => $asignado,
            'title' => $titulo,
            'date' => $fecha,
            'time' => $hora,
            'description' => $descripcion,
            'assigned' => $asignado,
            'títol' => $titulo,
            'data' => $fecha,
            'descripcio' => $descripcion,
            'assignat' => $asignado,
            'deskribapena' => $descripcion,
            'esleituta' => $asignado,
            'descrizione' => $descripcion,
            'assegnato' => $asignado,
            'opis' => $descripcion,
            'przydzielono' => $asignado,
            'açıklama' => $descripcion,
            'atanan' => $asignado,
        ];
    }
}

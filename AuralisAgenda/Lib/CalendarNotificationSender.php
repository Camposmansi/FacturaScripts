<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Fan-out de recordatorios de calendario por email con entregas idempotentes.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Email\MailNotifier;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotifDelivery;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use Throwable;

final class CalendarNotificationSender
{
    /**
     * @return bool true si la regla quedó marcada como enviada (todas las entregas intentadas)
     */
    public static function send(AuralisCalendarEventNotification $notif): bool
    {
        $notifId = (int)($notif->id ?? 0);
        if ($notifId <= 0 || (int)$notif->sent === 1) {
            return false;
        }

        $calendarEvent = new AuralisCalendarEvent();
        if (false === $calendarEvent->load((int)$notif->idevent)) {
            return false;
        }

        $provider = CalendarNotificationRegistry::get((string)$calendarEvent->origen_tipo);
        $recipients = CalendarParticipantSync::emailRecipientsForEvent($calendarEvent);
        if ($recipients === []) {
            // Retrocompat: un solo destinatario vía provider.
            $legacy = $provider->resolveRecipient($calendarEvent);
            if ($legacy !== null) {
                $recipients[] = [
                    'tipo' => 'user',
                    'codigo' => (string)($calendarEvent->asignado ?: $calendarEvent->nick),
                    'email' => (string)$legacy['email'],
                    'name' => (string)$legacy['name'],
                ];
            }
        }

        if ($recipients === []) {
            Tools::log()->warning(
                Tools::trans('calendar-notif-no-recipient', ['%id%' => (string)$notifId])
            );
            return false;
        }

        $mailParams = $provider->buildMailParams($calendarEvent);
        $anySent = false;
        $allDone = true;

        foreach ($recipients as $recipient) {
            $delivery = AuralisCalendarEventNotifDelivery::asegurarEntrega(
                $notifId,
                AuralisCalendarEventNotifDelivery::CANAL_EMAIL,
                (string)$recipient['tipo'],
                (string)$recipient['codigo'],
                (string)$recipient['email']
            );
            if ($delivery === null) {
                $allDone = false;
                continue;
            }
            if ((int)$delivery->sent === 1) {
                $anySent = true;
                continue;
            }

            try {
                $sent = MailNotifier::send(
                    (string)$notif->notification_name,
                    (string)$recipient['email'],
                    (string)$recipient['name'],
                    $mailParams
                );
                if ($sent && $delivery->markSent()) {
                    $anySent = true;
                    Tools::log()->notice(
                        Tools::trans('calendar-notif-sent', ['%id%' => (string)$notifId])
                        . ' → ' . AuralisSecurity::maskEmail((string)$recipient['email'])
                    );
                } else {
                    $allDone = false;
                    Tools::log()->warning(
                        Tools::trans('calendar-notif-send-failed', ['%id%' => (string)$notifId])
                    );
                }
            } catch (Throwable $exception) {
                $allDone = false;
                Tools::log()->error('calendar-notif-send-error: ' . $exception->getMessage());
            }
        }

        if ($anySent && $allDone) {
            $notif->sent = 1;
            $notif->sent_at = Tools::dateTime();
            $notif->save();
            return true;
        }

        // Si al menos uno se envió, no reintentamos los ya marcados; dejamos sent=0 para reintentar fallidos.
        return $anySent && $allDone;
    }
}

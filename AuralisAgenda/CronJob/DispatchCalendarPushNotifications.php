<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description CronJob: despacho batch de Web Push pendientes (caps anti-saturación).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\CronJob;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushDispatcher;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;

final class DispatchCalendarPushNotifications extends CronJobClass
{
    public const JOB_NAME = 'auralis-dispatch-calendar-push';

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        // Barata: un COUNT y, solo si hay caducadas, un DELETE (suscripciones sin 2xx en 90 días).
        $purged = AuralisPushSettings::purgeExpiredSubscriptions();

        $result = AuralisPushDispatcher::dispatchPending();

        self::echo(
            ' subs_purged=' . $purged
            . ' sent=' . $result['sent']
            . ' examined=' . $result['examined']
            . ' skipped_online=' . $result['skipped_online']
            . ' skipped_disabled=' . $result['skipped_disabled']
            . ' deferred_online=' . $result['deferred_online']
            . ' deferred_no_sub=' . $result['deferred_no_sub']
            . ' deferred_retry=' . $result['deferred_retry']
            . ' closed_agotado=' . $result['closed_agotado']
            . ' closed_caducado=' . $result['closed_caducado']
        );
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description CronJob: materializa avisos in-app de calendario en auralis_notificaciones.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\CronJob;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisNotificationManager;

final class MaterializeInAppNotifications extends CronJobClass
{
    public const JOB_NAME = 'auralis-materialize-inapp-notifications';

    public const BATCH_LIMIT = 100;

    public static function run(): void
    {
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');

        $result = AuralisNotificationManager::materializeFromCalendar(self::BATCH_LIMIT);

        self::echo(
            ' created=' . $result['created']
            . ' skipped=' . $result['skipped']
            . ' examined=' . ($result['examined'] ?? 0)
        );
    }
}

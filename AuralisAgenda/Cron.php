<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Cron de Auralis: email de agenda + materialización de avisos in-app.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\AuralisAgenda\CronJob\DispatchCalendarPushNotifications;
use FacturaScripts\Plugins\AuralisAgenda\CronJob\MaterializeInAppNotifications;
use FacturaScripts\Plugins\AuralisAgenda\CronJob\SendCalendarNotifications;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use FacturaScripts\Plugins\AuralisAgenda\Worker\AuralisPushDispatchWorker;

class Cron extends CronClass
{
    public function run(): void
    {
        $this->job(SendCalendarNotifications::JOB_NAME)
            ->every('1 minute')
            ->run(static function (): void {
                SendCalendarNotifications::run();
            });

        $this->job(MaterializeInAppNotifications::JOB_NAME)
            ->every('1 minute')
            ->run(static function (): void {
                MaterializeInAppNotifications::run();
            });

        $this->job(DispatchCalendarPushNotifications::JOB_NAME)
            ->every('1 minute')
            ->run(static function (): void {
                DispatchCalendarPushNotifications::run();
            });

        $this->encolarDespachoTardio();
    }

    /**
     * Encola el pase de push que corre en runWorkQueue(), tras el cron de todos los plugins.
     * Barato: sale si push está desactivado o si ya hay un evento pendiente sin procesar.
     */
    private function encolarDespachoTardio(): void
    {
        if (false === AuralisPushSettings::isPushEnabled()) {
            return;
        }

        $pendientes = WorkEvent::count([
            Where::eq('name', AuralisPushDispatchWorker::EVENTO),
            Where::eq('done', false),
        ]);
        if ($pendientes > 0) {
            return;
        }

        WorkQueue::send(AuralisPushDispatchWorker::EVENTO, '1');
    }
}

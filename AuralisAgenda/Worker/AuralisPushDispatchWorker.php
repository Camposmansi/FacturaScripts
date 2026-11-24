<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Worker de despacho tardío de Web Push: corre en runWorkQueue(), después del cron de todos los plugins.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushDispatcher;
use Throwable;

// Sin final: el deploy genera Dinamic\Worker\AuralisPushDispatchWorker extendiendo esta clase.
class AuralisPushDispatchWorker extends WorkerClass
{
    /** Nombre estable del evento que encola Cron::run() y escucha este worker. */
    public const EVENTO = 'Auralis.Push.DispatchLate';

    public function run(WorkEvent $event): bool
    {
        // evita que se reencole otro DispatchLate durante esta misma pasada
        $this->preventNewEvents([self::EVENTO]);

        try {
            // dispatchPending ya gestiona push desactivado
            AuralisPushDispatcher::dispatchPending();
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Despacho tardío de push fallido: ' . $e->getMessage());
        }

        return $this->done();
    }
}

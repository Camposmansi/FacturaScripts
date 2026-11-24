<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Vínculo 1:1 entre evento de calendario y documento de venta (crear → ver).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEvent;

final class CalendarEventDocLink
{
    public const SESSION_IDEVENT = 'auralis_cal_idevent';

    public const FORM_FIELD = 'auralis_idevent';

    /**
     * Guarda el idevent de la query/POST en Session (memoria de petición).
     */
    public static function stashIdeventFromForm(array $formData): void
    {
        $raw = $formData[self::FORM_FIELD] ?? $formData['idevent'] ?? null;
        $idevent = (int) $raw;
        if ($idevent > 0) {
            Session::set(self::SESSION_IDEVENT, $idevent);
            return;
        }
    }

    public static function peekStashedIdevent(): int
    {
        return (int) (Session::get(self::SESSION_IDEVENT) ?? 0);
    }

    public static function clearStashedIdevent(): void
    {
        Session::set(self::SESSION_IDEVENT, null);
    }

    /**
     * Tras el primer INSERT del documento, asocia el evento indicado en Session.
     */
    public static function linkAfterInsert(SalesDocument $document, string $modelo): void
    {
        $modelo = CalendarTimegrid::normalizarTipoDocVenta($modelo);
        $idevent = self::peekStashedIdevent();
        self::clearStashedIdevent();
        if ($idevent <= 0) {
            return;
        }

        $docId = (int) $document->id();
        if ($docId <= 0) {
            return;
        }

        $event = new AuralisCalendarEvent();
        if (false === $event->load($idevent)) {
            return;
        }

        $user = Session::user();
        if (false === self::userCanLinkEvent($user, $event)) {
            Tools::log('Auralis')->warning('calendar-doc-link: sin permiso para vincular evento ' . $idevent);
            return;
        }

        // 1:1 — no sobrescribir un vínculo distinto ya existente hacia otro documento.
        if (!empty($event->doc_id) && (int) $event->doc_id > 0
            && ((string) ($event->doc_modelo ?? '') !== $modelo || (int) $event->doc_id !== $docId)
        ) {
            return;
        }

        $event->doc_modelo = $modelo;
        $event->doc_id = $docId;
        if (false === $event->save()) {
            Tools::log('Auralis')->error('calendar-doc-link: no se pudo guardar vínculo en evento ' . $idevent);
        }
    }

    /**
     * Valida el documento vinculado; si ya no existe, limpia el FK del evento.
     *
     * @return array{doc_modelo: ?string, doc_id: ?int, doc_edit: ?string, doc_label_key: ?string}|null
     */
    public static function resolveLinkedDoc(AuralisCalendarEvent $event, bool $persistClear = true): ?array
    {
        $modelo = trim((string) ($event->doc_modelo ?? ''));
        $docId = (int) ($event->doc_id ?? 0);
        if ($modelo === '' || $docId <= 0) {
            return null;
        }

        $modelo = CalendarTimegrid::normalizarTipoDocVenta($modelo);
        if (false === self::documentExists($modelo, $docId)) {
            $event->doc_modelo = null;
            $event->doc_id = null;
            if ($persistClear && !empty($event->id)) {
                $event->save();
            }

            return null;
        }

        return [
            'doc_modelo' => $modelo,
            'doc_id' => $docId,
            'doc_edit' => CalendarTimegrid::editControllerParaTipoDoc($modelo),
            'doc_label_key' => CalendarTimegrid::labelKeyParaTipoDoc($modelo),
        ];
    }

    public static function documentExists(string $modelo, int $docId): bool
    {
        if ($docId <= 0) {
            return false;
        }

        $model = self::newDocumentModel($modelo);
        if (null === $model) {
            return false;
        }

        return $model->load($docId);
    }

    public static function newDocumentModel(string $modelo): ?SalesDocument
    {
        return match (CalendarTimegrid::normalizarTipoDocVenta($modelo)) {
            'PedidoCliente' => new PedidoCliente(),
            'AlbaranCliente' => new AlbaranCliente(),
            'FacturaCliente' => new FacturaCliente(),
            default => new PresupuestoCliente(),
        };
    }

    public static function userCanLinkEvent(?User $user, AuralisCalendarEvent $event): bool
    {
        if (null === $user || empty($user->nick)) {
            return false;
        }

        $nick = (string) $user->nick;
        if ((string) ($event->nick ?? '') === $nick) {
            return true;
        }

        return (string) ($event->asignado ?? '') === $nick;
    }
}

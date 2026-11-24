<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Lógica AJAX centralizada para guardar, mover y eliminar eventos de calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventStatus;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventType;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisSala;
use Throwable;

final class CalendarEventAjax
{
    public static function seedDefaultStatusesIfEmpty(): void
    {
        try {
            if (AuralisCalendarEventStatus::count() > 0) {
                return;
            }

            $defaults = [
                ['cod' => 'pendiente', 'name' => Tools::trans('pending'), 'order' => 10],
                ['cod' => 'confirmado', 'name' => Tools::trans('auralis-estado-confirmado'), 'order' => 20],
                ['cod' => 'en_espera', 'name' => 'En sala de espera', 'order' => 30],
                ['cod' => 'atendido', 'name' => 'Atendido', 'order' => 40],
                ['cod' => 'cancelado', 'name' => Tools::trans('cancelled'), 'order' => 50],
            ];

            foreach ($defaults as $row) {
                $status = new AuralisCalendarEventStatus();
                $status->codestado = $row['cod'];
                $status->nombre = $row['name'];
                $status->orden = $row['order'];
                $status->origen_tipo = 'general';
                $status->activo = true;
                $status->save();
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error sembrando estados iniciales de calendario: ' . $e->getMessage());
        }
    }

    public static function getEventStatusesList(string $origenTipo = ''): array
    {
        self::seedDefaultStatusesIfEmpty();

        try {
            $where = [Where::eq('activo', true)];
            if ($origenTipo !== '' && $origenTipo !== 'general') {
                $where[] = Where::in('origen_tipo', ['general', $origenTipo]);
            }

            $list = [];
            foreach (AuralisCalendarEventStatus::all($where, ['orden' => 'ASC', 'nombre' => 'ASC']) as $s) {
                $list[] = [
                    'codestado' => (string)$s->codestado,
                    'nombre' => (string)$s->nombre,
                ];
            }
            return $list;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getEventTypesList(string $origenTipo = ''): array
    {
        try {
            $where = [Where::eq('activo', true)];
            if ($origenTipo !== '' && $origenTipo !== 'general') {
                $where[] = Where::in('origen_tipo', ['general', $origenTipo]);
            }

            $list = [];
            foreach (AuralisCalendarEventType::all($where, ['nombre' => 'ASC']) as $t) {
                $list[] = [
                    'id' => (int)$t->id,
                    'nombre' => (string)$t->nombre,
                    'color' => (string)($t->color ?? '#4285f4'),
                    'duracion' => (int)($t->duracion ?? 30),
                ];
            }
            return $list;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getSalasList(?string $codalmacen = null): array
    {
        try {
            $where = [Where::eq('activa', true)];
            if (!empty($codalmacen)) {
                $where[] = Where::eq('codalmacen', $codalmacen);
            }

            $list = [];
            foreach (AuralisSala::all($where, ['nombre' => 'ASC']) as $sala) {
                $list[] = [
                    'id' => (int)$sala->idsala,
                    'nombre' => (string)($sala->nombre ?? $sala->idsala),
                    'codalmacen' => (string)($sala->codalmacen ?? ''),
                ];
            }
            return $list;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getAlmacenesList(): array
    {
        try {
            $list = [];
            foreach (Almacen::all([], ['nombre' => 'ASC']) as $alm) {
                $list[] = [
                    'codalmacen' => (string)$alm->codalmacen,
                    'nombre' => (string)$alm->nombre,
                ];
            }
            return $list;
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * @return array{types: array<int, array{name: string, label: string}>}
     */
    public static function notificationTypes(string $origenTipo): array
    {
        CalendarNotificationRegistry::ensureGeneralProvider();
        $available = CalendarNotificationRegistry::availableNotificationsFor($origenTipo);
        $types = [];
        foreach ($available as $name => $label) {
            $types[] = ['name' => $name, 'label' => $label];
        }

        return ['types' => $types];
    }

    /**
     * @return array<int, array{notification_name: string, offset_value: int, offset_unit: string}>
     */
    public static function parseNotificationsJson(Request $request, string $origenTipo): array
    {
        $raw = (string)$request->input('notifications_json', '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $row) {
            $item = AuralisCalendarEventNotification::normalizarEntrada($row);
            if ($item === null) {
                continue;
            }
            if (!CalendarNotificationRegistry::isNotificationAllowed($origenTipo, $item['notification_name'])) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}>
     */
    public static function parseParticipantsJson(Request $request): array
    {
        return CalendarParticipantSync::parseParticipantsJson((string)$request->input('participants_json', '[]'));
    }

    /**
     * Búsqueda AJAX de asistentes (CSRF rotado por CalendarAjaxResponse en el controlador).
     * Con `expandir=<proveedor:codigo>` devuelve los miembros del grupo (opcional `exclude`, JSON de `tipo:codigo`).
     * Con `sin_grupos=1` la búsqueda no devuelve grupos (filtro de cabecera).
     *
     * @return array{ok: bool, results: list<array<string, mixed>>, truncated?: bool, total?: int}
     */
    public static function searchParticipants(?User $user, Request $request): array
    {
        $expandir = trim((string)$request->input('expandir', $request->query('expandir', '')));
        if ($expandir !== '') {
            $exclude = (string)$request->input('exclude', $request->query('exclude', '[]'));
            return self::expandParticipantGroup($user, $expandir, $exclude);
        }

        $query = (string)$request->input('q', $request->query('q', ''));
        $tipo = (string)$request->input('tipo', $request->query('tipo', ''));
        $sinGrupos = '1' === (string)$request->input('sin_grupos', $request->query('sin_grupos', ''));
        $results = $sinGrupos
            ? CalendarParticipantSearch::searchPersonas($user, $query, $tipo !== '' ? $tipo : null)
            : CalendarParticipantSearch::search($user, $query, $tipo !== '' ? $tipo : null);

        return [
            'ok' => true,
            'results' => $results,
        ];
    }

    /**
     * Expande un grupo a personas tipo user, sin las ya elegidas y con tope MAX_PARTICIPANTS.
     * Proveedor desconocido, grupo inexistente o fallo del proveedor: results vacío (nunca 500).
     *
     * @return array{ok: bool, results: list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>, truncated: bool, total: int}
     */
    public static function expandParticipantGroup(?User $user, string $codigoGrupo, string $excludeJson = '[]'): array
    {
        $vacio = ['ok' => true, 'results' => [], 'truncated' => false, 'total' => 0];

        $codigoGrupo = trim($codigoGrupo);
        $pos = strpos($codigoGrupo, ':');
        if ($pos === false || $pos === 0 || $pos === strlen($codigoGrupo) - 1) {
            return $vacio;
        }
        $clave = substr($codigoGrupo, 0, $pos);
        if (1 !== preg_match(CalendarParticipantGroupRegistry::PATRON_CLAVE, $clave)) {
            return $vacio;
        }
        $provider = CalendarParticipantGroupRegistry::get($clave);
        if ($provider === null) {
            return $vacio;
        }

        try {
            $miembros = $provider->expandir($user, $codigoGrupo);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('calendar-participant-group-provider-error', ['%provider%' => $clave]);
            return $vacio;
        }

        $excluidos = self::parseParticipantExclude($excludeJson);
        $out = [];
        foreach ($miembros as $row) {
            if (!is_array($row) || ($row['tipo'] ?? '') !== AuralisCalendarEventParticipant::TIPO_USER) {
                continue;
            }
            $codigo = trim((string)($row['codigo'] ?? ''));
            $clavePersona = AuralisCalendarEventParticipant::TIPO_USER . ':' . $codigo;
            if ($codigo === '' || isset($excluidos[$clavePersona]) || isset($out[$clavePersona])) {
                continue;
            }
            $email = isset($row['email']) ? (string)$row['email'] : '';
            $out[$clavePersona] = [
                'tipo' => AuralisCalendarEventParticipant::TIPO_USER,
                'codigo' => $codigo,
                'label' => (string)($row['label'] ?? $codigo),
                'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                'group' => (string)($row['group'] ?? Tools::trans('calendar-participants-group-users')),
            ];
        }

        $total = count($out);
        return [
            'ok' => true,
            'results' => array_slice(array_values($out), 0, CalendarParticipantSync::MAX_PARTICIPANTS),
            'truncated' => $total > CalendarParticipantSync::MAX_PARTICIPANTS,
            'total' => $total,
        ];
    }

    /**
     * Claves `tipo:codigo` ya elegidas. Acepta cadenas o {tipo, codigo}; JSON inválido = sin exclusiones.
     *
     * @return array<string, true>
     */
    private static function parseParticipantExclude(string $json): array
    {
        $decoded = json_decode($json, true, 4);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach (array_slice($decoded, 0, 500) as $item) {
            if (is_array($item)) {
                $item = (string)($item['tipo'] ?? '') . ':' . (string)($item['codigo'] ?? '');
            }
            if (is_string($item) && $item !== '') {
                $result[trim($item)] = true;
            }
        }

        return $result;
    }

    public static function defaultsForOrigen(string $origenTipo): array
    {
        return [
            'defaults' => CalendarNotificationDefaults::getForOrigen($origenTipo),
        ];
    }

    /**
     * @param object{allowUpdate: bool} $permissions
     */
    public static function saveEvent(
        Response $response,
        Request $request,
        ?User $user,
        object $permissions,
        ?string $forcedOrigenTipo = null,
        bool $restringirAlUsuario = false
    ): void {
        if (false === $permissions->allowUpdate) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('access-denied')], 403);
            return;
        }

        $id = (int) $request->input('id');
        $scope = (string)$request->input('scope', 'esta');
        if (!in_array($scope, ['esta', 'esta_y_futuras'], true)) {
            $scope = 'esta';
        }

        $event = new AuralisCalendarEvent();
        $isNew = $id <= 0;

        if (!$isNew) {
            if (!$event->load($id)) {
                CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('evento-no-encontrado')], 404);
                return;
            }
            if (!self::canManageEvent($user, $event, $forcedOrigenTipo, $restringirAlUsuario)) {
                CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('access-denied')], 403);
                return;
            }
        } else {
            $event->clear();
            if ($user) {
                $event->nick = $user->nick;
            }
            if ($restringirAlUsuario && $user) {
                $event->nick = $user->nick;
            }
        }

        $data = self::collectCalendarEventRequestData($request, $event, $forcedOrigenTipo, $user);
        $origenTipo = (string)($data['origen_tipo'] ?? 'general');
        if ($origenTipo === CalendarioAcceso::ORIGEN_GENERAL) {
            $idcalendario = self::resolverCalendarioDestino($request, $event, $user, $isNew);
            if ($idcalendario === false) {
                CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::trans('auralis-calendario-sin-permiso-edicion')], 403);
                return;
            }
            $data['idcalendario'] = $idcalendario;
        }
        $notifications = self::parseNotificationsJson($request, $origenTipo);
        $participants = self::parseParticipantsJson($request);

        $seRepiteReq = $request->input('se_repite');
        $seRepite = (!empty($seRepiteReq) && ($seRepiteReq === '1' || $seRepiteReq === 'true' || $seRepiteReq === 'on')) ? 1 : 0;

        if ($isNew) {
            self::applyCalendarEventData($event, $data, true, $forcedOrigenTipo);
            $event->se_repite = $seRepite;
            if ($seRepite === 1) {
                $event->serie_activa = 1;
                $event->rep_generados = 1;
                $event->idparent = null;
            }

            if ($event->save()) {
                $syncResult = CalendarNotificationScheduler::syncForEvent($event, $notifications);
                self::syncParticipantsAfterSave($event, $participants, $seRepite === 1);
                if ($seRepite === 1) {
                    CalendarRecurrence::createInitialWindow($event);
                    self::copyNotificationsToSeriesChildren($event);
                    CalendarParticipantSync::copyToSeriesChildren($event);
                }
                CalendarAjaxResponse::json($response, self::saveEventSuccessPayload($event, $syncResult));
                return;
            }

            CalendarAjaxResponse::json($response, [
                'success' => false,
                'message' => Tools::lang()->trans('calendar-event-save-error', [], 'Auralis'),
            ], 400);
            return;
        }

        $isRecurring = CalendarRecurrence::isRecurring($event);
        $ruleChanged = self::recurrenceRuleChanged($event, $data, $seRepite);

        if ($isRecurring && $scope === 'esta_y_futuras') {
            $data['se_repite'] = $seRepite;
            $ok = CalendarRecurrence::updateThisAndFuture($event, $data, $ruleChanged);
            if ($ok) {
                $syncResult = CalendarNotificationScheduler::syncForEvent($event, $notifications);
                self::syncParticipantsAfterSave($event, $participants, true);
                CalendarNotificationScheduler::rescheduleEvent($event);
                CalendarAjaxResponse::json($response, self::saveEventSuccessPayload($event, $syncResult));
                return;
            }
            CalendarAjaxResponse::json($response, [
                'success' => false,
                'message' => Tools::lang()->trans('calendar-event-save-error', [], 'Auralis'),
            ], 400);
            return;
        }

        self::applyCalendarEventData($event, $data, !$isRecurring, $forcedOrigenTipo);
        if (!$isRecurring) {
            $event->se_repite = $seRepite;
            if ($seRepite === 1) {
                $event->serie_activa = 1;
                $event->rep_generados = 1;
                $event->idparent = null;
            }
        }

        if ($event->save()) {
            $syncResult = CalendarNotificationScheduler::syncForEvent($event, $notifications);
            self::syncParticipantsAfterSave($event, $participants, !$isRecurring && $seRepite === 1);
            if (!$isRecurring && $seRepite === 1) {
                CalendarRecurrence::createInitialWindow($event);
                self::copyNotificationsToSeriesChildren($event);
                CalendarParticipantSync::copyToSeriesChildren($event);
            } else {
                CalendarNotificationScheduler::rescheduleEvent($event);
            }
            CalendarAjaxResponse::json($response, self::saveEventSuccessPayload($event, $syncResult));
            return;
        }

        CalendarAjaxResponse::json($response, [
            'success' => false,
            'message' => Tools::lang()->trans('calendar-event-save-error', [], 'Auralis'),
        ], 400);
    }

    /**
     * @param object{allowUpdate?: bool, allowDelete: bool} $permissions
     */
    public static function deleteEvent(
        Response $response,
        Request $request,
        ?User $user,
        object $permissions,
        ?string $forcedOrigenTipo = null,
        bool $restringirAlUsuario = false
    ): void {
        if (false === ($permissions->allowDelete ?? false)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::trans('access-denied')], 403);
            return;
        }

        $id = (int) $request->input('id');
        if ($id <= 0) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => 'ID no proporcionado'], 400);
            return;
        }

        $scope = (string)$request->input('scope', 'esta');
        if (!in_array($scope, ['esta', 'esta_y_futuras'], true)) {
            $scope = 'esta';
        }

        $event = new AuralisCalendarEvent();
        if (!$event->load($id)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('evento-no-encontrado')], 404);
            return;
        }

        if (!self::canManageEvent($user, $event, $forcedOrigenTipo, $restringirAlUsuario)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('access-denied')], 403);
            return;
        }

        self::logAuditDelete($user, $event, $scope);

        $idevent = (int)$event->id;
        $ok = false;
        if ($scope === 'esta_y_futuras' && CalendarRecurrence::isRecurring($event)) {
            $ok = CalendarRecurrence::deleteThisAndFuture($event);
        } else {
            CalendarNotificationScheduler::cancelForEvent($idevent);
            $ok = $event->delete();
        }

        if ($ok) {
            CalendarAjaxResponse::json($response, [
                'success' => true,
                'message' => Tools::lang()->trans('calendar-event-deleted', [], 'Auralis'),
            ]);
            return;
        }

        CalendarAjaxResponse::json($response, [
            'success' => false,
            'message' => Tools::lang()->trans('calendar-event-delete-error', [], 'Auralis'),
        ], 400);
    }

    /**
     * @param object{allowUpdate: bool} $permissions
     */
    public static function moveEvent(
        Response $response,
        Request $request,
        ?User $user,
        object $permissions,
        ?string $forcedOrigenTipo = null,
        bool $restringirAlUsuario = false
    ): void {
        if (false === $permissions->allowUpdate) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('access-denied')], 403);
            return;
        }

        $id = (int) $request->input('id');
        $fecha = (string)$request->input('fecha', '');
        $horaInicio = (string)$request->input('hora_inicio', '');
        $scope = (string)$request->input('scope', 'esta');

        if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $horaInicio)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('datos-invalidos')], 400);
            return;
        }

        $event = new AuralisCalendarEvent();
        if (!$event->load($id)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('evento-no-encontrado')], 404);
            return;
        }

        if (!self::canManageEvent($user, $event, $forcedOrigenTipo, $restringirAlUsuario)) {
            CalendarAjaxResponse::json($response, ['success' => false, 'message' => Tools::lang()->trans('access-denied')], 403);
            return;
        }

        if ($scope === 'esta_y_futuras' && CalendarRecurrence::isRecurring($event)) {
            $ok = CalendarRecurrence::updateThisAndFuture($event, [
                'fecha' => $fecha,
                'hora_inicio' => $horaInicio,
            ], false);

            if ($ok) {
                CalendarNotificationScheduler::rescheduleEvent($event);
                CalendarAjaxResponse::json($response, [
                    'success' => true,
                    'id' => $event->id,
                    'fecha' => $fecha,
                    'hora_inicio' => $horaInicio,
                    'scope' => 'esta_y_futuras',
                    'message' => Tools::lang()->trans('calendar-event-saved', [], 'Auralis'),
                ]);
                return;
            }
        } else {
            if (!empty($event->idparent) && $scope === 'esta') {
                $event->idparent = null;
                $event->se_repite = 0;
            }
            $event->fecha = $fecha;
            $event->hora_inicio = $horaInicio;
            if (!empty($event->fecha_fin)) {
                $event->fecha_fin = $fecha;
            }

            if ($event->save()) {
                CalendarNotificationScheduler::rescheduleEvent($event);
                CalendarAjaxResponse::json($response, [
                    'success' => true,
                    'id' => $event->id,
                    'fecha' => $event->fecha,
                    'hora_inicio' => $event->hora_inicio,
                    'scope' => 'esta',
                    'message' => Tools::lang()->trans('calendar-event-saved', [], 'Auralis'),
                ]);
                return;
            }
        }

        CalendarAjaxResponse::json($response, [
            'success' => false,
            'message' => Tools::lang()->trans('calendar-event-save-error', [], 'Auralis'),
        ], 400);
    }

    private static function canManageEvent(?User $user, AuralisCalendarEvent $event, ?string $forcedOrigenTipo, bool $restringirAlUsuario = false): bool
    {
        if ($user === null) {
            return false;
        }

        if ($forcedOrigenTipo !== null && (string)$event->origen_tipo !== $forcedOrigenTipo) {
            return false;
        }

        // Evento general en un calendario: creador, organizador o permiso «editar» en ese calendario.
        if ((string)$event->origen_tipo === CalendarioAcceso::ORIGEN_GENERAL && !empty($event->idcalendario)) {
            if (false === CalendarioAcceso::puedeEditarEvento($user, $event)) {
                return false;
            }
        } elseif ($restringirAlUsuario && false === CalendarUserScope::esDueno($user, $event)) {
            return false;
        }

        return AuralisSecurity::canAccessEmpresa($user, $event->idempresa ?? 0);
    }

    /**
     * Calendario de destino de un evento general.
     * Sin idcalendario en la petición: el actual al editar o el de por defecto al crear.
     * Cambiarlo (o crear en él) exige permiso «editar» en el destino.
     *
     * @return int|false|null id destino, null = sin calendario (evento antiguo) o false = sin permiso
     */
    private static function resolverCalendarioDestino(Request $request, AuralisCalendarEvent $event, ?User $user, bool $isNew): int|false|null
    {
        $actual = (int)($event->idcalendario ?? 0);
        $pedido = (int)$request->input('idcalendario', '0');

        if ($pedido <= 0) {
            if (false === $isNew) {
                return $actual > 0 ? $actual : null;
            }

            $defecto = CalendarioAcceso::calendarioDefecto($user);
            $pedido = $defecto === null ? 0 : (int)$defecto->idcalendario;
            if ($pedido <= 0) {
                return null;
            }
        }

        if (false === $isNew && $pedido === $actual) {
            return $actual;
        }

        return CalendarioAcceso::puedeEscribirEn($user, $pedido) ? $pedido : false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function collectCalendarEventRequestData(
        Request $request,
        AuralisCalendarEvent $event,
        ?string $forcedOrigenTipo,
        ?User $user
    ): array {
        $rawFecha = (string)$request->input('fecha', '');
        $fecha = !empty($rawFecha) ? date('Y-m-d', strtotime($rawFecha)) : $event->fecha;

        $rawFechaFin = (string)$request->input('fecha_fin', '');
        $fechaFin = !empty($rawFechaFin) ? date('Y-m-d', strtotime($rawFechaFin)) : null;

        $todoDiaReq = $request->input('todo_dia');
        $todoDia = (!empty($todoDiaReq) && ($todoDiaReq === '1' || $todoDiaReq === 'true' || $todoDiaReq === 'on')) ? 1 : 0;

        $asignadoReq = (string)$request->input('asignado', '');
        $nickReq = (string)$request->input('nick', '');
        $asignado = !empty($asignadoReq) ? $asignadoReq : (!empty($nickReq) ? $nickReq : $event->nick);

        $codagente = (string)$request->input('codagente', '');
        $idsalaRaw = $request->input('idsala');
        $idsala = ($idsalaRaw !== null && $idsalaRaw !== '') ? (int)$idsalaRaw : null;

        $codalmacenRaw = (string)$request->input('codalmacen', '');
        $codalmacen = $codalmacenRaw !== '' ? $codalmacenRaw : null;

        $idtipoRaw = $request->input('idtipo');
        $idtipo = ($idtipoRaw !== null && $idtipoRaw !== '') ? (int)$idtipoRaw : null;

        $estadoRaw = (string)$request->input('estado', '');
        $estado = $estadoRaw !== '' ? $estadoRaw : null;

        $requestedEmpresa = (string) $request->input('idempresa', '');
        $idempresaVal = (int) AuralisSecurity::resolveCodEmpresa($user, $requestedEmpresa);
        if ($idempresaVal <= 0) {
            $idempresaVal = !empty($event->idempresa)
                ? (int) $event->idempresa
                : (int) Tools::settings('default', 'idempresa', 1);
        }

        $repFinFechaRaw = (string)$request->input('rep_fin_fecha', '');
        $repFinFecha = !empty($repFinFechaRaw) ? date('Y-m-d', strtotime($repFinFechaRaw)) : null;

        $origenTipo = ($forcedOrigenTipo !== null && $forcedOrigenTipo !== '')
            ? $forcedOrigenTipo
            : 'general';

        // origen_id / codcliente: el modal los envía (hidden). move-event no usa este collector → no se vacían.
        $origenIdRaw = $request->input('origen_id', null);
        $origenId = null;
        if ($origenIdRaw !== null && $origenIdRaw !== '') {
            $origenId = (int) $origenIdRaw;
            if ($origenId <= 0) {
                $origenId = null;
            }
        }

        $codclienteRaw = trim((string) $request->input('codcliente', ''));
        $codcliente = null;
        if ($codclienteRaw !== '') {
            $candidato = Tools::noHtml($codclienteRaw);
            $cliente = new Cliente();
            // Soft: código inventado no tumba el guardado; se ignora el vínculo.
            if ($candidato !== '' && $cliente->load($candidato)) {
                $codcliente = $candidato;
            }
        }

        return [
            'titulo' => (string)$request->input('titulo', ''),
            'fecha' => $fecha,
            'fecha_fin' => $fechaFin,
            'hora_inicio' => (string)$request->input('hora_inicio', '09:00'),
            'duracion' => (int)$request->input('duracion', CalendarTimegrid::duracionDefault()),
            'descripcion' => (string)$request->input('descripcion', ''),
            'color' => AuralisCalendarEvent::normalizarColorHex((string)$request->input('color', '#4285f4')),
            'origen_tipo' => $origenTipo,
            'origen_id' => $origenId,
            'codcliente' => $codcliente,
            'todo_dia' => $todoDia,
            'asignado' => $asignado,
            'codagente' => $codagente !== '' ? $codagente : null,
            'idsala' => $idsala,
            'codalmacen' => $codalmacen,
            'idtipo' => $idtipo,
            'estado' => $estado,
            'idempresa' => $idempresaVal,
            'rep_intervalo' => (int)$request->input('rep_intervalo', 1),
            'rep_unidad' => (string)$request->input('rep_unidad', 'week'),
            'rep_fin_tipo' => (string)$request->input('rep_fin_tipo', 'nunca'),
            'rep_fin_fecha' => $repFinFecha,
            'rep_fin_count' => (int)$request->input('rep_fin_count', 1),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function applyCalendarEventData(
        AuralisCalendarEvent $event,
        array $data,
        bool $includeRule = true,
        ?string $forcedOrigenTipo = null
    ): void {
        $event->titulo = (string)($data['titulo'] ?? '');
        if (!empty($data['fecha'])) {
            $event->fecha = (string)$data['fecha'];
        }
        $event->fecha_fin = $data['fecha_fin'] ?? null;
        $event->hora_inicio = (string)($data['hora_inicio'] ?? '09:00');
        $event->duracion = (int)($data['duracion'] ?? CalendarTimegrid::duracionDefault());
        $event->descripcion = (string)($data['descripcion'] ?? '');
        $event->color = (string)($data['color'] ?? '#4285f4');
        $event->origen_tipo = $forcedOrigenTipo ?? (string)($data['origen_tipo'] ?? 'general');
        // Solo aplicar si la clave viene en $data (save-event). move-event no pasa por aquí.
        if (array_key_exists('origen_id', $data)) {
            $event->origen_id = $data['origen_id'];
        }
        if (array_key_exists('codcliente', $data)) {
            $event->codcliente = $data['codcliente'];
        }
        $event->todo_dia = (int)($data['todo_dia'] ?? 0);
        $event->asignado = (string)($data['asignado'] ?? $event->nick);
        $event->codagente = $data['codagente'] ?? null;
        $event->idsala = $data['idsala'] ?? null;
        $event->codalmacen = $data['codalmacen'] ?? null;
        $event->idtipo = $data['idtipo'] ?? null;
        if (array_key_exists('estado', $data)) {
            $event->estado = $data['estado'];
        }
        if (isset($data['idempresa'])) {
            $event->idempresa = (int)$data['idempresa'];
        }
        if (array_key_exists('idcalendario', $data)) {
            $event->idcalendario = $data['idcalendario'];
        }
        if ($includeRule) {
            $event->rep_intervalo = isset($data['rep_intervalo']) ? (int)$data['rep_intervalo'] : $event->rep_intervalo;
            $event->rep_unidad = $data['rep_unidad'] ?? $event->rep_unidad;
            $event->rep_fin_tipo = $data['rep_fin_tipo'] ?? $event->rep_fin_tipo;
            $event->rep_fin_fecha = $data['rep_fin_fecha'] ?? $event->rep_fin_fecha;
            $event->rep_fin_count = isset($data['rep_fin_count']) ? (int)$data['rep_fin_count'] : $event->rep_fin_count;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function recurrenceRuleChanged(AuralisCalendarEvent $event, array $data, int $seRepite): bool
    {
        $root = new AuralisCalendarEvent();
        $rootId = CalendarRecurrence::seriesRootId($event);
        if (!$root->load($rootId)) {
            $root = $event;
        }

        if ((int)$root->se_repite !== $seRepite) {
            return true;
        }
        if ((int)($root->rep_intervalo ?? 1) !== (int)($data['rep_intervalo'] ?? 1)) {
            return true;
        }
        if ((string)($root->rep_unidad ?? 'week') !== (string)($data['rep_unidad'] ?? 'week')) {
            return true;
        }
        if ((string)($root->rep_fin_tipo ?? 'nunca') !== (string)($data['rep_fin_tipo'] ?? 'nunca')) {
            return true;
        }
        $oldFinFecha = !empty($root->rep_fin_fecha) ? date('Y-m-d', strtotime((string)$root->rep_fin_fecha)) : '';
        $newFinFecha = !empty($data['rep_fin_fecha']) ? date('Y-m-d', strtotime((string)$data['rep_fin_fecha'])) : '';
        if ($oldFinFecha !== $newFinFecha) {
            return true;
        }
        if ((int)($root->rep_fin_count ?? 0) !== (int)($data['rep_fin_count'] ?? 0)) {
            return true;
        }

        return false;
    }

    private static function copyNotificationsToSeriesChildren(AuralisCalendarEvent $parent): void
    {
        $parentId = (int)($parent->id ?? 0);
        if ($parentId <= 0) {
            return;
        }

        $rules = AuralisCalendarEventNotification::toArrayList($parentId);
        if ($rules === []) {
            return;
        }

        foreach (CalendarRecurrence::seriesChildren($parentId) as $child) {
            if ((int)$child->id === $parentId) {
                continue;
            }
            CalendarNotificationScheduler::syncForEvent($child, $rules);
        }
    }

    /**
     * @param list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}> $participants
     */
    private static function syncParticipantsAfterSave(AuralisCalendarEvent $event, array $participants, bool $copyToChildren): void
    {
        try {
            CalendarParticipantSync::syncForEvent($event, $participants);
            if ($copyToChildren) {
                CalendarParticipantSync::copyToSeriesChildren($event);
            }
        } catch (\Throwable $e) {
            // El evento ya está guardado: no tumbar el AJAX por fallos de pivote/asistentes.
            Tools::log('Auralis')->error('calendar-participants-sync: ' . $e->getMessage());
        }
    }

    private static function logAuditDelete(?User $user, AuralisCalendarEvent $event, string $scope): void
    {
        $nickBorrador = $user ? trim((string)$user->nick) : 'desconocido';
        Tools::log('Auralis')->notice(Tools::trans('calendar-event-audit-deleted-detail', [
            '%id%' => (string)(int)($event->id ?? 0),
            '%titulo%' => (string)($event->titulo ?? ''),
            '%fecha%' => (string)($event->fecha ?? ''),
            '%hora%' => mb_substr((string)($event->hora_inicio ?? ''), 0, 5),
            '%asignado%' => (string)($event->asignado ?: $event->nick),
            '%origen%' => (string)($event->origen_tipo ?? ''),
            '%nick%' => $nickBorrador,
            '%scope%' => $scope,
        ]));
    }

    /**
     * @param array{saved: int, past: int} $syncResult
     *
     * @return array<string, mixed>
     */
    private static function saveEventSuccessPayload(AuralisCalendarEvent $event, array $syncResult): array
    {
        $payload = [
            'success' => true,
            'id' => $event->id,
            'message' => Tools::lang()->trans('calendar-event-saved', [], 'Auralis'),
            'participants' => AuralisCalendarEventParticipant::toArrayList((int)$event->id),
        ];

        if (($syncResult['past'] ?? 0) > 0) {
            $payload['notifications_warning'] = Tools::lang()->trans(
                'calendar-notif-past-saved-warning',
                ['%count%' => (string)$syncResult['past']],
                'Auralis'
            );
        }

        return $payload;
    }
}

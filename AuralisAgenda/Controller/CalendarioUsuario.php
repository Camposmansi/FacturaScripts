<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Agenda personal timegrid del usuario (origen general, solo eventos propios o asignados).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarAjaxResponse;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarEventAjax;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAjax;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarEventDocLink;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarRecurrence;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarUserScope;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisSecurity;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;

class CalendarioUsuario extends Controller
{
    public const ORIGEN_TIPO = 'general';

    /** Acciones AJAX de calendarios (acción => requiere token CSRF). */
    private const ACCIONES_CALENDARIOS = [
        'calendarios-list' => false,
        'calendario-accesos' => false,
        'calendario-preferencia' => true,
        'calendario-guardar' => true,
        'calendario-borrar' => true,
        'calendario-acceso-guardar' => true,
        'calendario-acceso-borrar' => true,
    ];

    /** @var array<int, array{nick: string, nombre: string}> */
    public array $usersList = [];

    /** @var array<int, array{id: int, nombre: string, color: string, duracion: int}> */
    public array $eventTypesList = [];

    /** @var array<int, array{codestado: string, nombre: string}> */
    public array $eventStatusesList = [];

    /** @var array<int, array{id: int, nombre: string, codalmacen?: string}> */
    public array $salasList = [];

    public string $calendarDataJson = '';

    /** Calendarios del lateral y del selector del modal (misma respuesta que calendarios-list). */
    public array $calendariosSidebar = [];

    public string $origenTipo = self::ORIGEN_TIPO;

    /** Controlador Edit* del documento de venta configurable desde Agenda. */
    public string $eventDocEditController = 'EditPresupuestoCliente';

    /** Clave i18n Core del tipo de documento (estimation, order, …). */
    public string $eventDocLabelKey = 'estimation';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'mi-calendario';
        $data['icon'] = 'fa-solid fa-calendar-days';
        $data['showonmenu'] = false;
        return $data;
    }

    public function run(): void
    {
        parent::run();

        $accion = (string)$this->request()->query('action', '') ?: (string)$this->request()->input('action', '');
        if ($accion === 'calendar-events') {
            $this->ajaxEventosCalendario();
            return;
        }
        if ($accion === 'save-event') {
            $this->ajaxGuardarEvento();
            return;
        }
        if ($accion === 'move-event') {
            $this->ajaxMoverEvento();
            return;
        }
        if ($accion === 'delete-event') {
            $this->ajaxBorrarEvento();
            return;
        }
        if ($accion === 'calendar-notification-types') {
            $this->ajaxTiposAviso();
            return;
        }
        if ($accion === 'calendar-notification-defaults') {
            $this->ajaxAvisosDefecto();
            return;
        }
        if ($accion === 'get-event') {
            $this->ajaxObtenerEvento();
            return;
        }
        if ($accion === 'calendar-participant-search') {
            $this->ajaxBuscarParticipantes();
            return;
        }
        if (isset(self::ACCIONES_CALENDARIOS[$accion])) {
            $this->ajaxCalendarios($accion);
            return;
        }

        $this->cargarListaUsuarios();
        $this->eventTypesList = CalendarEventAjax::getEventTypesList(self::ORIGEN_TIPO);
        $this->eventStatusesList = CalendarEventAjax::getEventStatusesList(self::ORIGEN_TIPO);
        $this->salasList = CalendarTimegrid::miCalendarioMuestraSalas()
            ? CalendarEventAjax::getSalasList()
            : [];
        $this->eventDocEditController = CalendarTimegrid::miCalendarioEditDocVenta();
        $this->eventDocLabelKey = CalendarTimegrid::miCalendarioLabelKeyDocVenta();
        $this->cargarCalendariosSidebar();

        $this->calendarDataJson = (string)json_encode([
            'calendarCenterDate' => date('Y-m-d'),
            'origenTipo' => self::ORIGEN_TIPO,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $this->view('@PluginAuralisAgenda/CalendarioUsuario.html.twig');
    }

    private function cargarCalendariosSidebar(): void
    {
        $this->calendariosSidebar = [];
        try {
            [$payload] = CalendarioAjax::listar($this->user);
            if (!empty($payload['ok'])) {
                $this->calendariosSidebar = $payload;
            }
        } catch (\Throwable $excepcion) {
            Tools::log()->warning('Auralis: error cargando los calendarios del lateral: ' . $excepcion->getMessage());
        }
    }

    private function cargarListaUsuarios(): void
    {
        $this->usersList = [];
        try {
            if (!$this->db()->tableExists('users')) {
                return;
            }
            foreach (User::all([], ['nick' => 'ASC']) as $usuario) {
                $this->usersList[] = [
                    'nick' => (string)$usuario->nick,
                    'nombre' => (string)($usuario->nombre ?: $usuario->nick),
                ];
            }
        } catch (\Throwable $excepcion) {
            Tools::log()->warning('Auralis: error cargando usuarios del calendario personal: ' . $excepcion->getMessage());
        }
    }

    private function ajaxEventosCalendario(): void
    {
        CalendarRecurrence::maybeExpandLazy();

        $nick = CalendarUserScope::nickDeUsuario($this->user);
        if ($nick === '') {
            $this->response()->json(['html' => '']);
            return;
        }

        $desde = (string)$this->request()->query('desde', date('Y-m-01'));
        $hasta = (string)$this->request()->query('hasta', date('Y-m-t'));
        [$desde, $hasta] = AuralisSecurity::sanitizeDateRange($desde, $hasta);
        $tipoVista = (string)$this->request()->query('view', 'week');
        $codEmpresa = AuralisSecurity::resolveCodEmpresa(
            $this->user,
            (string)$this->request()->query('idempresa', '')
        );

        $fechaInicio = new \DateTime($desde);
        $fechaFin = new \DateTime($hasta);
        $columnas = ($tipoVista === 'month')
            ? 7
            : max(1, (int)$fechaInicio->diff($fechaFin)->days + 1);

        // Calendarios elegidos (calendarios[]); sin el parámetro, todos los visibles. Incluye invitaciones.
        $filtros = CalendarioAcceso::whereEventos(
            $this->user,
            CalendarioAjax::seleccionDesdePeticion($this->request()),
            $desde,
            $hasta,
            $codEmpresa
        );

        // Con filtros de detalle no entran los «ocupado»: filtrar por cliente o estado revelaría su contenido.
        $filtraDetalle = false;

        $idtipo = (int)$this->request()->query('idtipo', 0);
        if ($idtipo > 0 && CalendarTimegrid::miCalendarioMuestraTipos()) {
            $filtros[] = Where::eq('idtipo', $idtipo);
            $filtraDetalle = true;
        }

        $estado = trim((string)$this->request()->query('estado', ''));
        if ($estado !== '' && CalendarTimegrid::miCalendarioMuestraEstados()) {
            $filtros[] = Where::eq('estado', $estado);
            $filtraDetalle = true;
        }

        $participantRaw = trim((string)$this->request()->query('participant', ''));
        if ($participantRaw !== '') {
            $filtraDetalle = true;
            $parts = explode(':', $participantRaw, 2);
            $pTipo = trim((string)($parts[0] ?? ''));
            $pCodigo = trim((string)($parts[1] ?? ''));
            if ($pTipo !== '' && $pCodigo !== '' && in_array($pTipo, AuralisCalendarEventParticipant::tiposPermitidos(), true)) {
                $ids = AuralisCalendarEventParticipant::eventIdsForTipoCodigo($pTipo, $pCodigo);
                $sub = [];
                if ($ids !== []) {
                    $sub[] = Where::in('id', $ids);
                }
                if ($pTipo === AuralisCalendarEventParticipant::TIPO_USER) {
                    $sub[] = $ids === []
                        ? Where::eq('asignado', $pCodigo)
                        : Where::orEq('asignado', $pCodigo);
                }
                if ($sub !== []) {
                    $filtros[] = count($sub) === 1 ? $sub[0] : Where::sub($sub);
                } else {
                    // Sin coincidencias: forzar vacío
                    $filtros[] = Where::eq('id', 0);
                }
            }
        }

        $eventosBrutos = AuralisCalendarEvent::all($filtros, ['fecha' => 'ASC', 'hora_inicio' => 'ASC']);
        $eventos = [];
        foreach ($eventosBrutos as $evento) {
            $inicio = date('Y-m-d', strtotime((string)$evento->fecha));
            $fin = (!empty($evento->fecha_fin) && !empty($evento->todo_dia))
                ? date('Y-m-d', strtotime((string)$evento->fecha_fin))
                : $inicio;
            if ($inicio <= $hasta && $fin >= $desde) {
                $eventos[] = $evento;
            }
        }

        // Añade permiso/calendario a cada tarjeta y deja en «Ocupado» las de ese permiso.
        $dias = CalendarioAcceso::agruparEventosPorDias($this->user, $eventos, $desde, $hasta, $filtraDetalle);
        $parcial = ($tipoVista !== 'month')
            ? '@PluginAuralisAgenda/Master/_calendar_timegrid.html.twig'
            : '@PluginAuralisAgenda/Master/_calendar_base.html.twig';

        $html = Html::render($parcial, array_merge([
            'calendar_data' => ['days' => $dias],
            'col_count' => $columnas,
            'view_type' => $tipoVista,
            'cal_mode' => 'timegrid',
            'allow_create' => true,
            'origen_tipo' => self::ORIGEN_TIPO,
        ], CalendarTimegrid::twigVars()));

        $this->response()->json(['html' => $html]);
    }

    private function ajaxGuardarEvento(): void
    {
        if (!$this->validateFormToken()) {
            CalendarAjaxResponse::json($this->response(), ['success' => false, 'message' => Tools::trans('invalid-csrf-token')], 403);
            return;
        }

        CalendarEventAjax::saveEvent($this->response(), $this->request(), $this->user, $this->permissions, self::ORIGEN_TIPO, true);
    }

    private function ajaxMoverEvento(): void
    {
        if (!$this->validateFormToken()) {
            CalendarAjaxResponse::json($this->response(), ['success' => false, 'message' => Tools::trans('invalid-csrf-token')], 403);
            return;
        }

        CalendarEventAjax::moveEvent($this->response(), $this->request(), $this->user, $this->permissions, self::ORIGEN_TIPO, true);
    }

    private function ajaxBorrarEvento(): void
    {
        if (!$this->validateFormToken()) {
            CalendarAjaxResponse::json($this->response(), ['success' => false, 'message' => Tools::trans('invalid-csrf-token')], 403);
            return;
        }

        CalendarEventAjax::deleteEvent($this->response(), $this->request(), $this->user, $this->permissions, self::ORIGEN_TIPO, true);
    }

    private function ajaxTiposAviso(): void
    {
        CalendarAjaxResponse::json($this->response(), CalendarEventAjax::notificationTypes(self::ORIGEN_TIPO));
    }

    private function ajaxAvisosDefecto(): void
    {
        CalendarAjaxResponse::json($this->response(), CalendarEventAjax::defaultsForOrigen(self::ORIGEN_TIPO));
    }

    /**
     * Deep-link desde la campana: carga un evento del usuario para abrir el modal.
     */
    private function ajaxObtenerEvento(): void
    {
        $nick = CalendarUserScope::nickDeUsuario($this->user);
        $id = (int)$this->request()->query('id', 0);
        if ($nick === '' || $id <= 0) {
            CalendarAjaxResponse::json($this->response(), ['ok' => false, 'message' => Tools::trans('record-not-found')], 404);
            return;
        }

        $evento = new AuralisCalendarEvent();
        if (false === $evento->load($id)
            || (string) ($evento->origen_tipo ?? '') !== self::ORIGEN_TIPO
            || false === CalendarUserScope::puedeVer($this->user, $evento)
        ) {
            CalendarAjaxResponse::json($this->response(), ['ok' => false, 'message' => Tools::trans('access-denied')], 403);
            return;
        }

        $fecha = !empty($evento->fecha) ? date('Y-m-d', strtotime((string)$evento->fecha)) : date('Y-m-d');
        $fechaFin = (!empty($evento->fecha_fin) && !empty($evento->todo_dia))
            ? date('Y-m-d', strtotime((string)$evento->fecha_fin))
            : $fecha;

        $docLink = CalendarEventDocLink::resolveLinkedDoc($evento);

        CalendarAjaxResponse::json($this->response(), [
            'ok' => true,
            'can_edit' => CalendarioAcceso::puedeEditarEvento($this->user, $evento),
            'permiso' => CalendarioAcceso::permisoEvento($this->user, $evento),
            'event' => [
                'id' => (int)$evento->id,
                'fecha' => $fecha,
                'fecha_fin' => $fechaFin,
                'hora_inicio' => mb_substr((string)($evento->hora_inicio ?? '09:00'), 0, 5),
                'duracion' => (int)($evento->duracion ?? 60),
                'title' => (string)($evento->titulo ?? ''),
                'titulo' => (string)($evento->titulo ?? ''),
                'descripcion' => (string)($evento->descripcion ?? ''),
                'bg_color' => (string)($evento->color ?? ''),
                'nick' => (string)($evento->nick ?? ''),
                'asignado' => (string)($evento->asignado ?: $evento->nick),
                'todo_dia' => (int)($evento->todo_dia ?? 0),
                'origen_tipo' => (string)($evento->origen_tipo ?? self::ORIGEN_TIPO),
                'origen_id' => $evento->origen_id,
                'idcalendario' => !empty($evento->idcalendario) ? (int)$evento->idcalendario : null,
                'doc_modelo' => $docLink['doc_modelo'] ?? null,
                'doc_id' => $docLink['doc_id'] ?? null,
                'doc_edit' => $docLink['doc_edit'] ?? null,
                'doc_label_key' => $docLink['doc_label_key'] ?? null,
                'codagente' => $evento->codagente ?? null,
                'idsala' => $evento->idsala ?? null,
                'codalmacen' => $evento->codalmacen ?? null,
                'idtipo' => $evento->idtipo ?? null,
                'estado' => (string)($evento->estado ?? 'pendiente'),
                'idparent' => $evento->idparent ?? null,
                'se_repite' => (int)($evento->se_repite ?? 0),
                'rep_intervalo' => $evento->rep_intervalo ?? null,
                'rep_unidad' => $evento->rep_unidad ?? null,
                'rep_fin_tipo' => $evento->rep_fin_tipo ?? null,
                'rep_fin_fecha' => $evento->rep_fin_fecha ?? null,
                'rep_fin_count' => $evento->rep_fin_count ?? null,
                'notifications' => AuralisCalendarEventNotification::toArrayList((int)$evento->id),
                'participants' => AuralisCalendarEventParticipant::toArrayList((int)$evento->id),
            ],
        ]);
    }

    /**
     * Calendarios del usuario (lateral de Mi calendario). Lógica y permisos en Lib/CalendarioAjax.
     */
    private function ajaxCalendarios(string $accion): void
    {
        if (self::ACCIONES_CALENDARIOS[$accion] && false === $this->validateFormToken()) {
            CalendarAjaxResponse::json($this->response(), ['success' => false, 'message' => Tools::trans('invalid-csrf-token')], 403);
            return;
        }

        [$payload, $estado] = match ($accion) {
            'calendarios-list' => CalendarioAjax::listar($this->user),
            'calendario-accesos' => CalendarioAjax::listarAccesos($this->user, $this->request()),
            'calendario-preferencia' => CalendarioAjax::guardarPreferencia($this->user, $this->request()),
            'calendario-guardar' => CalendarioAjax::guardarCalendario($this->user, $this->request(), $this->permissions),
            'calendario-borrar' => CalendarioAjax::borrarCalendario($this->user, $this->request(), $this->permissions),
            'calendario-acceso-guardar' => CalendarioAjax::guardarAcceso($this->user, $this->request(), $this->permissions),
            'calendario-acceso-borrar' => CalendarioAjax::borrarAcceso($this->user, $this->request(), $this->permissions),
        };

        CalendarAjaxResponse::json($this->response(), $payload, $estado);
    }

    private function ajaxBuscarParticipantes(): void
    {
        // Validar CSRF sin tokenExist: la búsqueda al teclear dispara varias peticiones
        // y rota newToken en cada respuesta (mismo patrón que autocomplete VIS-053).
        $token = (string)$this->request()->inputOrQuery('multireqtoken', '');
        $protection = new MultiRequestProtection();
        if ($token === '' || false === $protection->validate($token)) {
            CalendarAjaxResponse::json($this->response(), ['ok' => false, 'message' => Tools::trans('invalid-csrf-token')], 403);
            return;
        }

        CalendarAjaxResponse::json(
            $this->response(),
            CalendarEventAjax::searchParticipants($this->user, $this->request())
        );
    }
}
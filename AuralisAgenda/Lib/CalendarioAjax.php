<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Acciones AJAX de los calendarios de Mi calendario: listar, preferencias, calendarios personales extra y accesos compartidos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendario;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioPreferencia;

/**
 * Cada método devuelve [payload, código HTTP]. El controlador valida el token CSRF de las escrituras
 * y responde con CalendarAjaxResponse::json() (newToken). Lecturas: clave «ok»; escrituras: «success».
 */
final class CalendarioAjax
{
    /**
     * Selección de calendarios del endpoint de eventos: calendarios[]=5&calendarios[]=cal:7 o calendarios=5,7.
     * null si no llega el parámetro (= todos los visibles según preferencias).
     *
     * @return array<int, int|string>|null
     */
    public static function seleccionDesdePeticion(Request $request): ?array
    {
        if ($request->query->has('calendarios')) {
            $lista = $request->query->getArray('calendarios');
            return $lista !== [] ? $lista : self::trocear((string)$request->query('calendarios', ''));
        }

        if ($request->request->has('calendarios')) {
            $lista = $request->request->getArray('calendarios');
            return $lista !== [] ? $lista : self::trocear((string)$request->input('calendarios', ''));
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function listar(?User $user): array
    {
        if ($user === null) {
            return [['ok' => false, 'message' => Tools::trans('access-denied')], 403];
        }

        $defecto = CalendarioAcceso::calendarioDefecto($user);

        return [[
            'ok' => true,
            'calendarios' => CalendarioAcceso::calendariosVisibles($user),
            'defecto' => $defecto === null ? null : (int)$defecto->idcalendario,
            'permitir_compartir' => CalendarioAcceso::permitirCompartir(),
            'permitir_calendarios_extra' => CalendarioAcceso::permitirCalendariosExtra(),
            'puede_gestionar_comunes' => CalendarioAcceso::puedeGestionarComunes($user),
            'permisos' => AuralisCalendarioAcceso::permisosPermitidos(),
        ], 200];
    }

    /**
     * Preferencia del usuario sobre un calendario que ve: visible (0/1) y color (#rrggbb, vacío = el del calendario).
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function guardarPreferencia(?User $user, Request $request): array
    {
        $item = self::calendarioVisible($user, self::idDesdePeticion($request));
        if ($item === null) {
            return self::error('auralis-calendario-no-encontrado', 404);
        }

        $preferencia = new AuralisCalendarioPreferencia();
        if (false === $preferencia->loadWhere([Where::eq('nick', $user->nick), Where::eq('clave', $item['clave'])])) {
            $preferencia->clear();
            $preferencia->nick = $user->nick;
            $preferencia->clave = $item['clave'];
            // Parte de lo que el usuario ve ahora: cambiar solo el color de un común desmarcado no lo marca.
            $preferencia->visible = (bool)$item['visible'];
        }

        if ($request->request->has('visible')) {
            $preferencia->visible = filter_var((string)$request->input('visible'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->request->has('color')) {
            $preferencia->color = trim((string)$request->input('color', ''));
        }

        if (false === $preferencia->save()) {
            return self::error('auralis-calendario-error-guardar', 400);
        }

        return [['success' => true, 'calendario' => self::calendarioVisible($user, $item['idcalendario'])], 200];
    }

    /**
     * Crea (idcalendario vacío) o edita un calendario personal propio: nombre, color y descripción.
     *
     * @param object{allowUpdate: bool} $permissions
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function guardarCalendario(?User $user, Request $request, object $permissions): array
    {
        if ($user === null || false === ($permissions->allowUpdate ?? false)) {
            return self::error('access-denied', 403);
        }

        $id = self::idDesdePeticion($request);
        $calendario = new AuralisCalendario();
        if ($id > 0) {
            $calendario = self::personalPropio($user, $id);
            if ($calendario === null) {
                return self::error('auralis-calendario-no-encontrado', 404);
            }
        } else {
            if (false === CalendarioAcceso::permitirCalendariosExtra()) {
                return self::error('auralis-calendario-extra-desactivado', 403);
            }
            CalendarioAcceso::principalDe($user);
            $calendario->tipo = AuralisCalendario::TIPO_PERSONAL;
            $calendario->propietario = $user->nick;
            $calendario->principal = false;
            $calendario->idempresa = CalendarioAcceso::empresaDeUsuario($user);
        }

        if ($request->request->has('nombre')) {
            $calendario->nombre = (string)$request->input('nombre', '');
        }
        if ($request->request->has('color')) {
            $calendario->color = (string)$request->input('color', '');
        }
        if ($request->request->has('descripcion')) {
            $calendario->descripcion = (string)$request->input('descripcion', '');
        }

        if (false === $calendario->save()) {
            return self::error('auralis-calendario-error-guardar', 400);
        }

        return [[
            'success' => true,
            'message' => Tools::trans('record-updated-correctly'),
            'calendario' => self::calendarioVisible($user, (int)$calendario->idcalendario),
        ], 200];
    }

    /**
     * Borra un calendario personal propio (no el principal) y todos sus eventos. Exige confirmar=1.
     *
     * @param object{allowDelete: bool} $permissions
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function borrarCalendario(?User $user, Request $request, object $permissions): array
    {
        if ($user === null || false === ($permissions->allowDelete ?? false)) {
            return self::error('access-denied', 403);
        }

        $calendario = self::personalPropio($user, self::idDesdePeticion($request));
        if ($calendario === null) {
            return self::error('auralis-calendario-no-encontrado', 404);
        }
        if ((bool)$calendario->principal) {
            return self::error('auralis-calendario-principal-no-borrable', 400);
        }
        if (false === filter_var((string)$request->input('confirmar', ''), FILTER_VALIDATE_BOOLEAN)) {
            return self::error('auralis-calendario-confirmar-borrado', 400);
        }

        $eventos = AuralisCalendarEvent::count([Where::eq('idcalendario', (int)$calendario->idcalendario)]);
        if (false === $calendario->delete()) {
            return self::error('auralis-calendario-error-borrar', 400);
        }

        return [[
            'success' => true,
            'message' => Tools::trans('record-deleted-correctly'),
            'eventos_borrados' => $eventos,
        ], 200];
    }

    /**
     * Accesos de un calendario personal propio + candidatos (usuarios activos y roles).
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function listarAccesos(?User $user, Request $request): array
    {
        if ($user === null) {
            return [['ok' => false, 'message' => Tools::trans('access-denied')], 403];
        }
        if (false === CalendarioAcceso::permitirCompartir()) {
            return [['ok' => false, 'message' => Tools::trans('auralis-calendario-compartir-desactivado')], 403];
        }

        $id = (int)$request->inputOrQuery('idcalendario', '0');
        $calendario = self::personalPropio($user, $id);
        if ($calendario === null) {
            return [['ok' => false, 'message' => Tools::trans('auralis-calendario-no-encontrado')], 404];
        }

        return [[
            'ok' => true,
            'idcalendario' => (int)$calendario->idcalendario,
            'accesos' => self::accesosDe((int)$calendario->idcalendario),
            'usuarios' => self::usuariosCandidatos((string)$user->nick),
            'roles' => self::rolesCandidatos(),
            'permisos' => AuralisCalendarioAcceso::permisosPermitidos(),
        ], 200];
    }

    /**
     * Comparte un calendario personal propio con un usuario (nick), un rol (codrole) o toda la
     * empresa (ambos vacíos). Si ya existe el acceso, actualiza el permiso.
     *
     * @param object{allowUpdate: bool} $permissions
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function guardarAcceso(?User $user, Request $request, object $permissions): array
    {
        if ($user === null || false === ($permissions->allowUpdate ?? false)) {
            return self::error('access-denied', 403);
        }
        if (false === CalendarioAcceso::permitirCompartir()) {
            return self::error('auralis-calendario-compartir-desactivado', 403);
        }

        $calendario = self::personalPropio($user, self::idDesdePeticion($request));
        if ($calendario === null) {
            return self::error('auralis-calendario-no-encontrado', 404);
        }

        $nick = trim((string)$request->input('nick', ''));
        $codrole = trim((string)$request->input('codrole', ''));
        $permiso = trim((string)$request->input('permiso', AuralisCalendarioAcceso::PERMISO_VER));

        if ($nick !== '' && $codrole !== '') {
            return self::error('auralis-calendario-acceso-usuario-o-rol', 400);
        }
        if ($nick !== '' && ($nick === (string)$user->nick || false === (new User())->load($nick))) {
            return self::error('auralis-calendario-acceso-destino-invalido', 400);
        }
        if ($codrole !== '' && false === (new Role())->load($codrole)) {
            return self::error('auralis-calendario-acceso-destino-invalido', 400);
        }
        if (false === in_array($permiso, AuralisCalendarioAcceso::permisosPermitidos(), true)) {
            return self::error('auralis-calendario-permiso-invalido', 400);
        }

        $idcalendario = (int)$calendario->idcalendario;
        $acceso = new AuralisCalendarioAcceso();
        $existe = $acceso->loadWhere([
            Where::eq('idcalendario', $idcalendario),
            $nick === '' ? Where::isNull('nick') : Where::eq('nick', $nick),
            $codrole === '' ? Where::isNull('codrole') : Where::eq('codrole', $codrole),
        ]);
        if (false === $existe) {
            $acceso->clear();
            $acceso->idcalendario = $idcalendario;
            $acceso->nick = $nick;
            $acceso->codrole = $codrole;
        }
        $acceso->permiso = $permiso;

        if (false === $acceso->save()) {
            return self::error('auralis-calendario-error-guardar', 400);
        }

        return [['success' => true, 'accesos' => self::accesosDe($idcalendario)], 200];
    }

    /**
     * Quita un acceso (id) de un calendario personal propio.
     *
     * @param object{allowUpdate: bool} $permissions
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function borrarAcceso(?User $user, Request $request, object $permissions): array
    {
        if ($user === null || false === ($permissions->allowUpdate ?? false)) {
            return self::error('access-denied', 403);
        }

        $acceso = new AuralisCalendarioAcceso();
        if (false === $acceso->load((int)$request->input('id', '0'))) {
            return self::error('auralis-calendario-no-encontrado', 404);
        }

        $idcalendario = (int)$acceso->idcalendario;
        if (self::personalPropio($user, $idcalendario) === null) {
            return self::error('auralis-calendario-no-encontrado', 404);
        }

        if (false === $acceso->delete()) {
            return self::error('auralis-calendario-error-borrar', 400);
        }

        return [['success' => true, 'accesos' => self::accesosDe($idcalendario)], 200];
    }

    /**
     * @return list<array{id: int, nick: ?string, codrole: ?string, permiso: string, alcance: string}>
     */
    private static function accesosDe(int $idcalendario): array
    {
        $lista = [];
        foreach (AuralisCalendarioAcceso::all([Where::eq('idcalendario', $idcalendario)], ['id' => 'ASC'], 0, 0) as $acceso) {
            $lista[] = [
                'id' => (int)$acceso->id,
                'nick' => $acceso->nick === null ? null : (string)$acceso->nick,
                'codrole' => $acceso->codrole === null ? null : (string)$acceso->codrole,
                'permiso' => (string)$acceso->permiso,
                'alcance' => !empty($acceso->nick) ? 'usuario' : (!empty($acceso->codrole) ? 'rol' : 'empresa'),
            ];
        }

        return $lista;
    }

    /**
     * @return list<array{nick: string, nombre: string}>
     */
    private static function usuariosCandidatos(string $nickPropio): array
    {
        $lista = [];
        foreach (User::all([Where::eq('enabled', true)], ['nick' => 'ASC'], 0, 0) as $usuario) {
            if ((string)$usuario->nick === $nickPropio) {
                continue;
            }
            $lista[] = ['nick' => (string)$usuario->nick, 'nombre' => (string)($usuario->nombre ?? '') ?: (string)$usuario->nick];
        }

        return $lista;
    }

    /**
     * @return list<array{codrole: string, descripcion: string}>
     */
    private static function rolesCandidatos(): array
    {
        $lista = [];
        foreach (Role::all([], ['codrole' => 'ASC'], 0, 0) as $rol) {
            $lista[] = ['codrole' => (string)$rol->codrole, 'descripcion' => (string)($rol->descripcion ?: $rol->codrole)];
        }

        return $lista;
    }

    private static function personalPropio(?User $user, int $id): ?AuralisCalendario
    {
        if ($user === null || $id <= 0) {
            return null;
        }

        $calendario = new AuralisCalendario();
        if (false === $calendario->load($id)
            || false === $calendario->esPersonal()
            || (string)$calendario->propietario !== (string)$user->nick) {
            return null;
        }

        return $calendario;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function calendarioVisible(?User $user, int $id): ?array
    {
        if ($user === null || $id <= 0) {
            return null;
        }

        foreach (CalendarioAcceso::calendariosVisibles($user) as $item) {
            if ($item['idcalendario'] === $id) {
                return $item;
            }
        }

        return null;
    }

    private static function idDesdePeticion(Request $request): int
    {
        $valor = trim((string)$request->input('idcalendario', $request->input('clave', '')));
        if (str_starts_with($valor, 'cal:')) {
            $valor = mb_substr($valor, 4);
        }

        return ctype_digit($valor) ? (int)$valor : 0;
    }

    /**
     * @return string[]
     */
    private static function trocear(string $texto): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $texto)), static fn(string $v): bool => $v !== ''));
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function error(string $clave, int $estado): array
    {
        return [['success' => false, 'message' => Tools::trans($clave)], $estado];
    }
}

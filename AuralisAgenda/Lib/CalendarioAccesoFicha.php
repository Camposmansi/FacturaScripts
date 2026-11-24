<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Pestañas «Calendarios» de las fichas de usuario y de rol: qué calendarios puede asignar el gestor, filtros de cada lista y altas, cambios y bajas de accesos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendario;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioAcceso;

final class CalendarioAccesoFicha
{
    /** EditRole: accesos del rol (editable). */
    public const VISTA_ROL = 'EditAuralisCalendarioAccesoRol';

    /** EditUser: accesos directos del usuario (editable), con el resumen de solo lectura en su pie. */
    public const VISTA_USUARIO = 'EditAuralisCalendarioAccesoUsuario';

    /** Ajuste de la vista de usuario con el resumen que pinta Block/CalendarioAccesoResumen. */
    public const AJUSTE_RESUMEN = 'auralis_resumen';

    public const ALCANCE_ROL = 'rol';
    public const ALCANCE_USUARIO = 'usuario';

    /** Acciones de las pestañas editables que atiende esta clase en lugar del Core. */
    public const ACCIONES = ['insert', 'edit', 'delete'];

    public static function pestanaVisible(?User $user): bool
    {
        return CalendarioAcceso::puedeGestionarComunes($user);
    }

    /**
     * Calendarios que el gestor puede asignar: comunes y personales con dueño de sus empresas, nunca de módulo.
     * Con un usuario de destino no salen sus propios personales (ya es su dueño) ni los comunes de otras
     * empresas (no los vería: CalendarioAcceso solo enseña los comunes de las empresas del usuario).
     *
     * @return array<int, AuralisCalendario> por idcalendario
     */
    public static function calendariosAsignables(?User $gestor, ?User $destino = null): array
    {
        if (false === self::pestanaVisible($gestor)) {
            return [];
        }

        $lista = [];
        $where = [Where::in('tipo', [AuralisCalendario::TIPO_COMUN, AuralisCalendario::TIPO_PERSONAL])];
        foreach (AuralisCalendario::all($where, ['tipo' => 'ASC', 'nombre' => 'ASC'], 0, 0) as $calendario) {
            if ($calendario->esPersonal() && empty($calendario->propietario)) {
                continue;
            }
            if (false === AuralisSecurity::canAccessEmpresa($gestor, $calendario->idempresa ?? 0)) {
                continue;
            }
            if ($destino !== null) {
                if ($calendario->esPersonal() && (string)$calendario->propietario === (string)$destino->nick) {
                    continue;
                }
                if ($calendario->esComun() && false === AuralisSecurity::canAccessEmpresa($destino, $calendario->idempresa ?? 0)) {
                    continue;
                }
            }

            $lista[(int)$calendario->idcalendario] = $calendario;
        }

        return $lista;
    }

    /**
     * Opciones del select de calendario, agrupadas en comunes y personales.
     *
     * @param array<int, AuralisCalendario> $calendarios
     *
     * @return list<array{value: string, title: string, group: string}>
     */
    public static function opcionesCalendario(array $calendarios): array
    {
        $grupoComun = Tools::trans('auralis-calendario-tipo-comun');
        $grupoPersonal = Tools::trans('auralis-calendario-tipo-personal');

        $opciones = [];
        foreach ($calendarios as $id => $calendario) {
            $titulo = $calendario->esComun()
                ? (string)$calendario->nombre
                : Tools::trans('auralis-calendario-personal-de', [
                    '%nick%' => (string)$calendario->propietario,
                    '%nombre%' => (string)$calendario->nombre,
                ]);
            if (false === (bool)$calendario->activo) {
                $titulo .= ' (' . Tools::trans('auralis-calendario-inactivo') . ')';
            }

            $opciones[] = [
                'value' => (string)$id,
                'title' => $titulo,
                'group' => $calendario->esComun() ? $grupoComun : $grupoPersonal,
            ];
        }

        return $opciones;
    }

    /**
     * Lista editable: accesos directos del rol o del usuario a calendarios que el gestor puede asignar.
     *
     * @param int[] $ids
     *
     * @return Where[]
     */
    public static function whereAccesos(string $alcance, string $destino, array $ids): array
    {
        return [
            $alcance === self::ALCANCE_ROL ? Where::eq('codrole', $destino) : Where::eq('nick', $destino),
            Where::in('idcalendario', $ids === [] ? [0] : $ids),
        ];
    }

    /**
     * Accesos a los calendarios personales del usuario (con quién los comparte), de las empresas del gestor.
     *
     * @return Where[]
     */
    public static function whereCompartidos(?User $gestor, string $nick): array
    {
        $ids = [];
        $where = [Where::eq('tipo', AuralisCalendario::TIPO_PERSONAL), Where::eq('propietario', $nick)];
        foreach (AuralisCalendario::all($where, [], 0, 0) as $calendario) {
            if (AuralisSecurity::canAccessEmpresa($gestor, $calendario->idempresa ?? 0)) {
                $ids[] = (int)$calendario->idcalendario;
            }
        }

        return [Where::in('idcalendario', $ids === [] ? [0] : $ids)];
    }

    /**
     * Accesos que le llegan al usuario por sus roles o por ser de la empresa (nick y codrole vacíos).
     *
     * @param int[] $ids calendarios que el gestor puede asignar a ese usuario
     *
     * @return Where[]
     */
    public static function whereHeredados(string $nick, array $ids): array
    {
        $alcance = [Where::sub([Where::isNull('nick'), Where::isNull('codrole')])];
        $roles = self::rolesDe($nick);
        if ($roles !== []) {
            $alcance[] = Where::orIn('codrole', $roles);
        }

        return [Where::sub($alcance), Where::in('idcalendario', $ids === [] ? [0] : $ids)];
    }

    /**
     * Resumen de solo lectura del pie de la pestaña: con quién comparte el usuario sus calendarios
     * personales y qué le llega por sus grupos o por ser de la empresa.
     *
     * @return array{
     *     compartidos: list<array{calendario: string, con: string, permiso: string}>,
     *     heredados: list<array{calendario: string, via: string, permiso: string}>
     * }
     */
    public static function resumenUsuario(?User $gestor, User $usuario): array
    {
        $resumen = ['compartidos' => [], 'heredados' => []];
        $nick = (string)$usuario->nick;
        if (false === self::pestanaVisible($gestor) || $nick === '') {
            return $resumen;
        }

        foreach (self::accesos(self::whereCompartidos($gestor, $nick)) as [$acceso, $calendario]) {
            $resumen['compartidos'][] = [
                'calendario' => (string)$calendario->nombre,
                'con' => empty($acceso->nick) ? self::grupoOEmpresa($acceso) : (string)$acceso->nick,
                'permiso' => Tools::trans('auralis-calendario-permiso-' . $acceso->permiso),
            ];
        }

        $ids = array_keys(self::calendariosAsignables($gestor, $usuario));
        foreach (self::accesos(self::whereHeredados($nick, $ids)) as [$acceso, $calendario]) {
            $resumen['heredados'][] = [
                'calendario' => (string)$calendario->nombre,
                'via' => self::grupoOEmpresa($acceso),
                'permiso' => Tools::trans('auralis-calendario-permiso-' . $acceso->permiso),
            ];
        }

        return $resumen;
    }

    /**
     * Alta, cambio o baja pedida desde la pestaña. El token ya lo ha validado el controlador.
     *
     * @return bool true si ha guardado o borrado algo
     */
    public static function atender(?User $gestor, Request $request, string $accion, string $alcance, string $destino): bool
    {
        switch ($accion) {
            case 'insert':
                return self::guardar($gestor, $alcance, $destino, 0, (int)$request->input('idcalendario', '0'), (string)$request->input('permiso', ''));

            case 'edit':
                return self::guardar($gestor, $alcance, $destino, (int)$request->input('code', '0'), (int)$request->input('idcalendario', '0'), (string)$request->input('permiso', ''));

            case 'delete':
                $ids = $request->request->getArray('codes');
                if ($ids === []) {
                    $ids = [$request->input('code', '')];
                }
                return self::borrar($gestor, $alcance, $destino, $ids) > 0;
        }

        return false;
    }

    /**
     * Da de alta ($id = 0) o cambia un acceso del rol o del usuario. El usuario o rol de destino lo fija
     * esta clase, nunca la petición; la validación final es la del test() del modelo.
     */
    public static function guardar(?User $gestor, string $alcance, string $destino, int $id, int $idcalendario, string $permiso): bool
    {
        if (false === self::pestanaVisible($gestor)) {
            Tools::log()->warning('not-allowed-modify');
            return false;
        }

        $usuario = null;
        if (false === self::destinoValido($alcance, $destino, $usuario)) {
            Tools::log()->warning('auralis-calendario-acceso-destino-invalido');
            return false;
        }

        $asignables = self::calendariosAsignables($gestor, $usuario);
        if (false === isset($asignables[$idcalendario])) {
            Tools::log()->warning('auralis-calendario-acceso-calendario-invalido');
            return false;
        }

        $acceso = new AuralisCalendarioAcceso();
        $anterior = null;
        if ($id > 0) {
            if (false === $acceso->load($id)
                || false === self::esDelDestino($acceso, $alcance, $destino)
                || false === isset($asignables[(int)$acceso->idcalendario])) {
                Tools::log()->warning('not-allowed-modify');
                return false;
            }
            $anterior = (int)$acceso->idcalendario;
        }

        $acceso->idcalendario = $idcalendario;
        $acceso->permiso = $permiso;
        $acceso->nick = $alcance === self::ALCANCE_USUARIO ? $destino : null;
        $acceso->codrole = $alcance === self::ALCANCE_ROL ? $destino : null;
        if (false === $acceso->save()) {
            Tools::log()->error('record-save-error');
            return false;
        }

        // Cambiar de calendario es quitar el acceso a uno y darlo en otro.
        if ($anterior !== null && $anterior !== $idcalendario) {
            self::auditar('baja', $asignables[$anterior], $alcance, $destino, $permiso, $gestor);
        }
        if ($anterior !== $idcalendario) {
            self::auditar('alta', $asignables[$idcalendario], $alcance, $destino, $permiso, $gestor);
        }

        Tools::log()->notice('record-updated-correctly');
        return true;
    }

    /**
     * Quita accesos del rol o del usuario. Los que no son suyos o no puede gestionar se saltan.
     *
     * @param array<int, mixed> $ids
     *
     * @return int accesos borrados
     */
    public static function borrar(?User $gestor, string $alcance, string $destino, array $ids): int
    {
        $usuario = null;
        if (false === self::pestanaVisible($gestor) || false === self::destinoValido($alcance, $destino, $usuario)) {
            Tools::log()->warning('not-allowed-delete');
            return 0;
        }

        $asignables = self::calendariosAsignables($gestor, $usuario);
        $borrados = 0;
        foreach ($ids as $id) {
            $acceso = new AuralisCalendarioAcceso();
            if (false === is_scalar($id)
                || false === $acceso->load((int)$id)
                || false === self::esDelDestino($acceso, $alcance, $destino)
                || false === isset($asignables[(int)$acceso->idcalendario])) {
                Tools::log()->warning('not-allowed-delete');
                continue;
            }

            $permiso = (string)$acceso->permiso;
            if ($acceso->delete()) {
                ++$borrados;
                self::auditar('baja', $asignables[(int)$acceso->idcalendario], $alcance, $destino, $permiso, $gestor);
            }
        }

        if ($borrados > 0) {
            Tools::log()->notice('record-deleted-correctly');
        }

        return $borrados;
    }

    private static function auditar(string $tipo, AuralisCalendario $calendario, string $alcance, string $destino, string $permiso, ?User $gestor): void
    {
        Tools::log('Auralis')->notice(Tools::trans('auralis-calendario-acceso-audit-' . $tipo, [
            '%calendario%' => (string)$calendario->nombre . ' (#' . (int)$calendario->idcalendario . ')',
            '%destino%' => Tools::trans($alcance === self::ALCANCE_ROL ? 'role' : 'user') . ' ' . $destino,
            '%permiso%' => Tools::trans('auralis-calendario-permiso-' . $permiso),
            '%gestor%' => (string)($gestor->nick ?? ''),
        ]));
    }

    /**
     * El rol o el usuario existe; con usuario lo devuelve en $usuario.
     */
    private static function destinoValido(string $alcance, string $destino, ?User &$usuario): bool
    {
        if ($destino === '') {
            return false;
        }

        if ($alcance === self::ALCANCE_ROL) {
            return (new Role())->load($destino);
        }

        if ($alcance !== self::ALCANCE_USUARIO) {
            return false;
        }

        $usuario = new User();
        if ($usuario->load($destino)) {
            return true;
        }

        $usuario = null;
        return false;
    }

    private static function esDelDestino(AuralisCalendarioAcceso $acceso, string $alcance, string $destino): bool
    {
        if ($alcance === self::ALCANCE_ROL) {
            return (string)$acceso->codrole === $destino && empty($acceso->nick);
        }

        return (string)$acceso->nick === $destino && empty($acceso->codrole);
    }

    /**
     * Accesos con su calendario, ordenados por nombre de calendario.
     *
     * @param Where[] $where
     *
     * @return list<array{0: AuralisCalendarioAcceso, 1: AuralisCalendario}>
     */
    private static function accesos(array $where): array
    {
        $accesos = AuralisCalendarioAcceso::all($where, ['id' => 'ASC'], 0, 0);
        $ids = array_values(array_unique(array_map(static fn($acceso): int => (int)$acceso->idcalendario, $accesos)));
        if ($ids === []) {
            return [];
        }

        $calendarios = [];
        foreach (AuralisCalendario::all([Where::in('idcalendario', $ids)], [], 0, 0) as $calendario) {
            $calendarios[(int)$calendario->idcalendario] = $calendario;
        }

        $filas = [];
        foreach ($accesos as $acceso) {
            $calendario = $calendarios[(int)$acceso->idcalendario] ?? null;
            if ($calendario !== null) {
                $filas[] = [$acceso, $calendario];
            }
        }
        usort($filas, static fn(array $a, array $b): int => strcasecmp((string)$a[1]->nombre, (string)$b[1]->nombre));

        return $filas;
    }

    /**
     * Descripción del grupo del acceso o «Toda la empresa» si no tiene grupo.
     */
    private static function grupoOEmpresa(AuralisCalendarioAcceso $acceso): string
    {
        if (empty($acceso->codrole)) {
            return Tools::trans('auralis-calendario-toda-empresa');
        }

        $rol = new Role();
        return $rol->load((string)$acceso->codrole) && !empty($rol->descripcion)
            ? (string)$rol->descripcion
            : (string)$acceso->codrole;
    }

    /**
     * @return string[]
     */
    private static function rolesDe(string $nick): array
    {
        $roles = [];
        foreach (RoleUser::all([Where::eq('nick', $nick)], [], 0, 0) as $relacion) {
            $codrole = trim((string)$relacion->codrole);
            if ($codrole !== '') {
                $roles[$codrole] = $codrole;
            }
        }

        return array_values($roles);
    }
}

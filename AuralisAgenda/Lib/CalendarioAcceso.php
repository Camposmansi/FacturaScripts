<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description SSOT de visibilidad y permisos de los calendarios (personal, común y de módulo): qué ve cada usuario, con qué permiso y qué eventos le llegan.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendario;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioPreferencia;

final class CalendarioAcceso
{
    public const SETTING_COMUNES_GESTIONAN = 'comunes_gestionan';
    public const SETTING_COMUNES_ROLES = 'comunes_roles_gestores';
    public const SETTING_CALENDARIO_DEFECTO = 'calendario_defecto';
    public const SETTING_PERMITIR_COMPARTIR = 'permitir_compartir';
    public const SETTING_PERMITIR_EXTRA = 'permitir_calendarios_extra';

    public const GESTIONAN_ADMIN = 'admin';
    public const GESTIONAN_ROLES = 'roles';
    public const DEFECTO_PERSONAL = 'personal';

    public const ORIGEN_GENERAL = 'general';

    /** Código del común «Empresa» de cada empresa: empresa_{idempresa} (el mismo que usa la migración 1.75). */
    public const PREFIJO_COMUN_EMPRESA = 'empresa_';
    public const COLOR_COMUN_EMPRESA = '#0b8043';

    public const OCUPADO = AuralisCalendarioAcceso::PERMISO_OCUPADO;
    public const VER = AuralisCalendarioAcceso::PERMISO_VER;
    public const EDITAR = AuralisCalendarioAcceso::PERMISO_EDITAR;

    // ── Configuración (grupo auralis_calendario) ─────────────────────────────

    public static function modoGestionComunes(): string
    {
        $modo = (string)Tools::settings(CalendarTimegrid::SETTING_GROUP, self::SETTING_COMUNES_GESTIONAN, self::GESTIONAN_ADMIN);
        return $modo === self::GESTIONAN_ROLES ? self::GESTIONAN_ROLES : self::GESTIONAN_ADMIN;
    }

    /**
     * Roles (codrole) que gestionan comunes cuando el modo es «roles».
     *
     * @return string[]
     */
    public static function rolesGestores(): array
    {
        return self::normalizarListaRoles(
            Tools::settings(CalendarTimegrid::SETTING_GROUP, self::SETTING_COMUNES_ROLES, '')
        );
    }

    /**
     * @param mixed $valor Lista separada por comas o array (select múltiple).
     *
     * @return string[]
     */
    public static function normalizarListaRoles($valor): array
    {
        $items = is_array($valor) ? $valor : explode(',', (string)$valor);
        $roles = [];
        foreach ($items as $item) {
            if (false === is_scalar($item)) {
                continue;
            }
            $codrole = Tools::noHtml(trim((string)$item));
            if ($codrole !== '' && mb_strlen($codrole) <= 20) {
                $roles[$codrole] = $codrole;
            }
        }

        return array_values($roles);
    }

    public static function permitirCompartir(): bool
    {
        return self::settingBool(self::SETTING_PERMITIR_COMPARTIR);
    }

    public static function permitirCalendariosExtra(): bool
    {
        return self::settingBool(self::SETTING_PERMITIR_EXTRA);
    }

    /**
     * «personal» o el id (texto) de un común.
     */
    public static function calendarioDefectoConfig(): string
    {
        $valor = trim((string)Tools::settings(CalendarTimegrid::SETTING_GROUP, self::SETTING_CALENDARIO_DEFECTO, self::DEFECTO_PERSONAL));
        return ctype_digit($valor) && (int)$valor > 0 ? (string)(int)$valor : self::DEFECTO_PERSONAL;
    }

    // ── Calendarios ───────────────────────────────────────────────────────────

    /**
     * Calendario personal principal del usuario; lo crea al vuelo si no existe.
     */
    public static function principalDe(?User $user): ?AuralisCalendario
    {
        $nick = self::nick($user);
        if ($nick === '') {
            return null;
        }

        return self::principalDeNick($nick, self::empresaDeUsuario($user));
    }

    /**
     * Igual que principalDe() a partir del nick (eventos guardados fuera de la agenda, migración).
     */
    public static function principalDeNick(string $nick, ?int $idempresa = null): ?AuralisCalendario
    {
        $nick = trim($nick);
        if ($nick === '') {
            return null;
        }

        $where = [
            Where::eq('tipo', AuralisCalendario::TIPO_PERSONAL),
            Where::eq('propietario', $nick),
            Where::eq('principal', true),
        ];

        $calendario = new AuralisCalendario();
        if ($calendario->loadWhere($where)) {
            return $calendario;
        }

        $calendario->clear();
        $calendario->tipo = AuralisCalendario::TIPO_PERSONAL;
        $calendario->propietario = $nick;
        $calendario->principal = true;
        $calendario->nombre = $nick;
        $calendario->idempresa = $idempresa ?? self::empresaDeUsuario(null);
        if ($calendario->save()) {
            return $calendario;
        }

        // Otra petición pudo crearlo a la vez.
        $existente = new AuralisCalendario();
        return $existente->loadWhere($where) ? $existente : null;
    }

    /**
     * Crea (idempotente) la fila de cada módulo activo que aún no la tenga. Nunca desde Init::init().
     *
     * @param array<string, array{plugin: string, controlador: string, etiqueta: string, color: string}>|null $modulos
     *        null = CalendarOrigins::modulosActivos()
     *
     * @return int filas creadas
     */
    public static function asegurarCalendariosModulo(?array $modulos = null): int
    {
        $modulos ??= CalendarOrigins::modulosActivos();
        if ($modulos === []) {
            return 0;
        }

        $creados = 0;
        try {
            $existentes = [];
            foreach (AuralisCalendario::all([Where::in('codigo', array_keys($modulos))], [], 0, 0) as $calendario) {
                $existentes[(string)$calendario->codigo] = true;
            }

            foreach ($modulos as $codigo => $modulo) {
                if (isset($existentes[$codigo])) {
                    continue;
                }

                $calendario = new AuralisCalendario();
                $calendario->tipo = AuralisCalendario::TIPO_MODULO;
                $calendario->codigo = $codigo;
                $calendario->nombre = Tools::trans($modulo['etiqueta']);
                $calendario->color = $modulo['color'];
                $calendario->activo = true;
                if ($calendario->save()) {
                    ++$creados;
                }
            }
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-calendarios-modulo: ' . $e->getMessage());
        }

        return $creados;
    }

    /**
     * Crea el común «Empresa» de la empresa, con acceso de toda la empresa en «ver», si aún no tiene
     * ningún común. Lo llaman una sola vez la migración CrearComunEmpresa y el alta de una empresa:
     * si el admin lo borra, no vuelve. Nunca desde Init::init().
     *
     * @return bool true si lo ha creado
     */
    public static function asegurarComunEmpresa(int $idempresa): bool
    {
        if ($idempresa <= 0) {
            return false;
        }

        try {
            if (false === (new Empresa())->load($idempresa)) {
                return false;
            }

            $comunes = AuralisCalendario::count([
                Where::eq('tipo', AuralisCalendario::TIPO_COMUN),
                Where::eq('idempresa', $idempresa),
            ]);
            if ($comunes > 0) {
                return false;
            }

            $calendario = new AuralisCalendario();
            $calendario->tipo = AuralisCalendario::TIPO_COMUN;
            $calendario->codigo = self::PREFIJO_COMUN_EMPRESA . $idempresa;
            $calendario->nombre = Tools::trans('auralis-calendario-comun-empresa');
            $calendario->color = self::COLOR_COMUN_EMPRESA;
            $calendario->idempresa = $idempresa;
            $calendario->activo = true;
            if (false === $calendario->save()) {
                return false;
            }

            // Toda la empresa (nick y codrole vacíos) en «ver».
            $acceso = new AuralisCalendarioAcceso();
            $acceso->idcalendario = (int)$calendario->idcalendario;
            $acceso->permiso = self::VER;
            if (false === $acceso->save()) {
                $calendario->delete();
                return false;
            }

            Tools::log('Auralis')->notice(Tools::trans('auralis-calendario-audit-comun-empresa', [
                '%id%' => (string)$calendario->idcalendario,
                '%idempresa%' => (string)$idempresa,
            ]));

            return true;
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-calendario-comun-empresa: ' . $e->getMessage());
            return false;
        }
    }

    public static function puedeGestionarComunes(?User $user): bool
    {
        if (self::nick($user) === '') {
            return false;
        }

        return self::esGestorComunes($user, self::rolesDe($user));
    }

    /**
     * Calendarios que ve el usuario, con su permiso, color y visibilidad efectivos.
     * Orden: propios (principal primero), comunes, compartidos por otros y módulos.
     *
     * @return list<array{
     *     idcalendario: int, clave: string, codigo: ?string, nombre: string, tipo: string,
     *     propietario: ?string, propio: bool, principal: bool, idempresa: ?int, activo: bool,
     *     permiso: string, color: string, color_calendario: string, visible: bool,
     *     puede_gestionar: bool, url: ?string
     * }>
     */
    public static function calendariosVisibles(?User $user): array
    {
        $nick = self::nick($user);
        if ($nick === '') {
            return [];
        }

        try {
            $mapa = self::mapaVisibles($user);
            $preferencias = self::preferenciasDe($nick);
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-calendarios-visibles: ' . $e->getMessage());
            return [];
        }

        $items = [];
        foreach ($mapa as $entrada) {
            $items[] = self::formatear($entrada['calendario'], $entrada['permiso'], $nick, $preferencias, $entrada['gestiona']);
        }

        usort($items, static function (array $a, array $b): int {
            $orden = static fn(array $i): int => match (true) {
                $i['propio'] => 0,
                $i['tipo'] === AuralisCalendario::TIPO_COMUN => 1,
                $i['tipo'] === AuralisCalendario::TIPO_PERSONAL => 2,
                default => 3,
            };

            return [$orden($a), $b['principal'] ? 1 : 0, mb_strtolower($a['nombre'])]
                <=> [$orden($b), $a['principal'] ? 1 : 0, mb_strtolower($b['nombre'])];
        });

        return $items;
    }

    /**
     * Regla única de si un calendario se muestra marcado (lateral y eventos que se piden sin calendarios[]).
     * Con preferencia guardada manda la preferencia. Sin ella se ven todos salvo los comunes de la empresa,
     * que nacen desmarcados (1.77): el usuario los marca en «Calendarios de la empresa» y entonces se guarda
     * su preferencia. Los personales (propios o compartidos) y los de módulo siguen marcados.
     */
    public static function esVisible(AuralisCalendario $calendario, ?AuralisCalendarioPreferencia $preferencia): bool
    {
        if ($preferencia !== null) {
            return (bool)$preferencia->visible;
        }

        return false === $calendario->esComun();
    }

    /**
     * Permiso del usuario sobre un calendario: propietario o gestor de comunes > editar > ver > ocupado.
     * Si alcanzan varios accesos gana el mayor. Cadena vacía = sin acceso.
     */
    public static function permiso(?User $user, int $idcalendario): string
    {
        if ($idcalendario <= 0 || self::nick($user) === '') {
            return '';
        }

        try {
            $mapa = self::mapaVisibles($user);
        } catch (\Throwable $e) {
            return '';
        }

        return $mapa[$idcalendario]['permiso'] ?? '';
    }

    /**
     * Calendario de destino de un evento nuevo según la configuración: un común (si el usuario
     * puede editarlo y está activo) o, si no, su personal principal.
     */
    public static function calendarioDefecto(?User $user): ?AuralisCalendario
    {
        $config = self::calendarioDefectoConfig();
        if ($config !== self::DEFECTO_PERSONAL) {
            $comun = new AuralisCalendario();
            if ($comun->load((int)$config)
                && $comun->esComun()
                && (bool)$comun->activo
                && self::permiso($user, (int)$comun->idcalendario) === self::EDITAR) {
                return $comun;
            }
        }

        return self::principalDe($user);
    }

    /**
     * Calendario donde se puede escribir un evento general: personal o común, activo y con permiso editar.
     */
    public static function puedeEscribirEn(?User $user, int $idcalendario): bool
    {
        if ($idcalendario <= 0 || self::nick($user) === '') {
            return false;
        }

        try {
            $mapa = self::mapaVisibles($user);
        } catch (\Throwable $e) {
            return false;
        }

        $entrada = $mapa[$idcalendario] ?? null;
        if ($entrada === null || $entrada['permiso'] !== self::EDITAR) {
            return false;
        }

        $calendario = $entrada['calendario'];
        return false === $calendario->esModulo() && (bool)$calendario->activo;
    }

    // ── Eventos ───────────────────────────────────────────────────────────────

    /**
     * Filtro de eventos visibles para el usuario en [desde, hasta].
     *
     * - Eventos general de los calendarios personales/comunes elegidos (idcalendario).
     * - Eventos de los módulos elegidos (origen_tipo = codigo), salvo con $soloGenerales.
     * - Invitaciones (creador, organizador o asistente) como hoy, si está elegido el principal propio
     *   y el evento no está en un calendario visible que el usuario haya ocultado.
     *
     * @param array<int, int|string>|null $idsOClaves ids o claves «cal:{id}»; null = los visibles según preferencias
     *
     * @return Where[]
     */
    public static function whereEventos(
        ?User $user,
        ?array $idsOClaves,
        string $desde,
        string $hasta,
        string $empresaSolicitada = '',
        bool $soloGenerales = false
    ): array {
        $nick = self::nick($user);
        if ($nick === '') {
            return [Where::eq('id', 0)];
        }

        $visibles = self::calendariosVisibles($user);
        $elegidos = self::seleccionar($visibles, $idsOClaves);

        $generalesElegidos = [];
        $generalesVisibles = [];
        $modulos = [];
        $invitaciones = false;
        foreach ($visibles as $item) {
            if ($item['tipo'] !== AuralisCalendario::TIPO_MODULO) {
                $generalesVisibles[] = $item['idcalendario'];
            }
        }
        foreach ($elegidos as $item) {
            if (false === $item['activo']) {
                continue;
            }
            if ($item['tipo'] === AuralisCalendario::TIPO_MODULO) {
                if (false === $soloGenerales && $item['codigo'] !== null) {
                    $modulos[] = $item['codigo'];
                }
                continue;
            }
            $generalesElegidos[] = $item['idcalendario'];
            if ($item['propio'] && $item['principal']) {
                $invitaciones = true;
            }
        }

        $alternativas = [];
        if ($generalesElegidos !== []) {
            $alternativas[] = Where::sub([
                Where::eq('origen_tipo', self::ORIGEN_GENERAL),
                Where::in('idcalendario', $generalesElegidos),
            ]);
        }
        if ($modulos !== []) {
            $alternativas[] = $alternativas === [] ? Where::in('origen_tipo', $modulos) : Where::orIn('origen_tipo', $modulos);
        }
        if ($invitaciones) {
            $mias = [Where::eq('nick', $nick), Where::orEq('asignado', $nick)];
            $idsInvitado = self::idsParticipante($nick);
            if ($idsInvitado !== []) {
                $mias[] = Where::orIn('id', $idsInvitado);
            }

            $grupo = [Where::eq('origen_tipo', self::ORIGEN_GENERAL), Where::sub($mias)];
            $ocultos = array_values(array_diff($generalesVisibles, $generalesElegidos));
            if ($ocultos !== []) {
                $grupo[] = Where::sub([Where::isNull('idcalendario'), Where::orNotIn('idcalendario', $ocultos)]);
            }
            $alternativas[] = Where::sub($grupo, $alternativas === [] ? 'AND' : 'OR');
        }

        if ($alternativas === []) {
            return [Where::eq('id', 0)];
        }

        $where = [
            Where::lte('fecha', $hasta),
            Where::sub([Where::gte('fecha', $desde), Where::orGte('fecha_fin', $desde)]),
            Where::sub($alternativas),
        ];

        $codEmpresa = AuralisSecurity::resolveCodEmpresa($user, $empresaSolicitada);
        if ($codEmpresa !== '') {
            $where[] = Where::eq('idempresa', (int)$codEmpresa);
        }

        return $where;
    }

    /**
     * Permiso efectivo del usuario sobre un evento: el de su calendario, editar si es creador u
     * organizador y al menos ver si es asistente. Los de módulo se ven (se editan en su página).
     *
     * @param array{cal: array<int, array<string, mixed>>, mod: array<string, array<string, mixed>>}|null $indice
     * @param int[]|null $idsInvitado
     */
    public static function permisoEvento(?User $user, ?object $evento, ?array $indice = null, ?array $idsInvitado = null): string
    {
        $nick = self::nick($user);
        if ($nick === '' || $evento === null) {
            return '';
        }

        if (false === AuralisSecurity::canAccessEmpresa($user, $evento->idempresa ?? 0)) {
            return '';
        }

        $indice ??= self::indice(self::calendariosVisibles($user));
        $origen = (string)($evento->origen_tipo ?? '');

        if ($origen !== self::ORIGEN_GENERAL) {
            return isset($indice['mod'][$origen]) ? self::VER : '';
        }

        $nivel = 0;
        $idcalendario = (int)($evento->idcalendario ?? 0);
        if ($idcalendario > 0 && isset($indice['cal'][$idcalendario])) {
            $nivel = AuralisCalendarioAcceso::nivel((string)$indice['cal'][$idcalendario]['permiso']);
        }

        if (CalendarUserScope::esDueno($user, $evento)) {
            $nivel = max($nivel, AuralisCalendarioAcceso::nivel(self::EDITAR));
        } else {
            $idsInvitado ??= self::idsParticipante($nick);
            if (in_array((int)($evento->id ?? 0), $idsInvitado, true)) {
                $nivel = max($nivel, AuralisCalendarioAcceso::nivel(self::VER));
            }
        }

        return self::permisoDeNivel($nivel);
    }

    /**
     * Permiso sobre un evento en las pantallas centrales (ListAuralisCalendarEvent / EditAuralisCalendarEvent),
     * admins incluidos:
     * - general: permisoEvento() (su calendario, creador u organizador, invitado).
     * - módulo: el de la página de su calendario (actualizar → editar; solo acceso → ver).
     * - otros orígenes (plugins sin calendario): los permisos de la propia pantalla (editar).
     */
    public static function permisoEventoCentral(?User $user, ?object $evento): string
    {
        if (self::nick($user) === '' || $evento === null) {
            return '';
        }

        $origen = (string)($evento->origen_tipo ?? '');
        if ($origen === self::ORIGEN_GENERAL) {
            return self::permisoEvento($user, $evento);
        }

        if (false === AuralisSecurity::canAccessEmpresa($user, $evento->idempresa ?? 0)) {
            return '';
        }

        $modulo = CalendarOrigins::MODULOS[$origen] ?? null;
        if ($modulo === null) {
            return self::EDITAR;
        }

        if ($user->can($modulo['controlador'], 'update')) {
            return self::EDITAR;
        }

        return $user->can($modulo['controlador']) ? self::VER : '';
    }

    /**
     * Todos los eventos indicados existen y el usuario tiene «editar» sobre ellos en las pantallas centrales.
     * Códigos que no existen no cuentan (el Core ya avisa de que no hay registro).
     *
     * @param array<int, int|string> $ids
     */
    public static function puedeEditarEventosCentral(?User $user, array $ids): bool
    {
        foreach ($ids as $id) {
            $evento = new AuralisCalendarEvent();
            if (is_scalar($id) && $evento->load($id) && self::permisoEventoCentral($user, $evento) !== self::EDITAR) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filtro del listado central de eventos: lo mismo que permisoEventoCentral() con al menos «ver».
     * Un «ocupado» no entra (el listado enseña título y notas).
     *
     * @return Where[]
     */
    public static function whereEventosCentral(?User $user): array
    {
        $nick = self::nick($user);
        if ($nick === '') {
            return [Where::eq('id', 0)];
        }

        $calendarios = [];
        foreach (self::calendariosVisibles($user) as $item) {
            if ($item['tipo'] !== AuralisCalendario::TIPO_MODULO
                && AuralisCalendarioAcceso::nivel($item['permiso']) >= AuralisCalendarioAcceso::nivel(self::VER)) {
                $calendarios[] = $item['idcalendario'];
            }
        }

        // General: calendarios con ver/editar, o creador, organizador o invitado.
        $suyos = [];
        if ($calendarios !== []) {
            $suyos[] = Where::in('idcalendario', $calendarios);
        }
        $suyos[] = $suyos === [] ? Where::eq('nick', $nick) : Where::orEq('nick', $nick);
        $suyos[] = Where::orEq('asignado', $nick);
        $idsInvitado = self::idsParticipante($nick);
        if ($idsInvitado !== []) {
            $suyos[] = Where::orIn('id', $idsInvitado);
        }
        $alternativas = [Where::sub([Where::eq('origen_tipo', self::ORIGEN_GENERAL), Where::sub($suyos)])];

        // Módulos: quien tiene acceso a la página de su calendario.
        $modulos = [];
        foreach (CalendarOrigins::MODULOS as $codigo => $modulo) {
            if ($user->can($modulo['controlador'])) {
                $modulos[] = $codigo;
            }
        }
        if ($modulos !== []) {
            $alternativas[] = Where::orIn('origen_tipo', $modulos);
        }

        // Orígenes sin calendario: como hasta ahora.
        $alternativas[] = Where::orNotIn('origen_tipo', array_merge([self::ORIGEN_GENERAL], array_keys(CalendarOrigins::MODULOS)));

        $where = [Where::sub($alternativas)];
        if (AuralisSecurity::isEmpresaLocked($user)) {
            $where[] = Where::sub([Where::eq('idempresa', (int)$user->idempresa), Where::orLte('idempresa', 0)]);
        }

        return $where;
    }

    public static function puedeVerEvento(?User $user, ?object $evento): bool
    {
        return AuralisCalendarioAcceso::nivel(self::permisoEvento($user, $evento))
            >= AuralisCalendarioAcceso::nivel(self::VER);
    }

    public static function puedeEditarEvento(?User $user, ?object $evento): bool
    {
        return self::permisoEvento($user, $evento) === self::EDITAR;
    }

    /**
     * Agrupa los eventos por días (groupEventsByDays) y añade a cada tarjeta permiso, calendario y color.
     * Con permiso «ocupado» la tarjeta solo lleva el hueco: título «Ocupado», sin descripción,
     * cliente, asistentes ni avisos.
     *
     * @param object[] $eventos
     * @param bool $excluirOcupados true si la consulta filtró por detalle (cliente, tipo, estado): un «ocupado»
     *                              que pasa ese filtro delataría su contenido, así que no se pinta.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function agruparEventosPorDias(?User $user, array $eventos, string $desde, string $hasta, bool $excluirOcupados = false): array
    {
        $indice = self::indice(self::calendariosVisibles($user));
        $idsInvitado = self::idsParticipante(self::nick($user));

        $permisos = [];
        $calendarios = [];
        $preparados = [];
        foreach ($eventos as $evento) {
            $permiso = self::permisoEvento($user, $evento, $indice, $idsInvitado);
            if ($permiso === '' || ($excluirOcupados && $permiso === self::OCUPADO)) {
                continue;
            }

            $id = (int)($evento->id ?? 0);
            $calendario = self::calendarioDeEvento($evento, $indice);
            $permisos[$id] = $permiso;
            $calendarios[$id] = $calendario;
            $preparados[] = $permiso === self::OCUPADO
                ? self::enmascararOcupado($evento, (string)($calendario['color'] ?? AuralisCalendario::COLOR_DEFECTO))
                : $evento;
        }

        $dias = AuralisCalendarEvent::groupEventsByDays($preparados, $desde, $hasta);
        foreach ($dias as &$dia) {
            foreach (['cards', 'allday_cards', 'timed_cards'] as $lista) {
                if (empty($dia[$lista]) || false === is_array($dia[$lista])) {
                    continue;
                }
                foreach ($dia[$lista] as &$tarjeta) {
                    $id = (int)($tarjeta['id'] ?? 0);
                    $tarjeta = self::decorarTarjeta($tarjeta, $permisos[$id] ?? self::VER, $calendarios[$id] ?? null);
                }
                unset($tarjeta);
            }
        }
        unset($dia);

        return $dias;
    }

    /**
     * IDs de evento en los que el nick figura como asistente interno.
     *
     * @return int[]
     */
    public static function idsParticipante(string $nick): array
    {
        if (trim($nick) === '') {
            return [];
        }

        try {
            return AuralisCalendarEventParticipant::eventIdsForUser($nick);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{calendario: AuralisCalendario, permiso: string, gestiona: bool}>
     */
    private static function mapaVisibles(User $user): array
    {
        $nick = self::nick($user);
        $roles = self::rolesDe($user);
        $admin = (bool)($user->admin ?? false);
        $gestor = self::esGestorComunes($user, $roles);
        $mapa = [];

        self::principalDe($user);

        // 1) Propios: el dueño edita y gestiona.
        $propios = AuralisCalendario::all([
            Where::eq('tipo', AuralisCalendario::TIPO_PERSONAL),
            Where::eq('propietario', $nick),
        ], [], 0, 0);
        foreach ($propios as $calendario) {
            $mapa[(int)$calendario->idcalendario] = ['calendario' => $calendario, 'permiso' => self::EDITAR, 'gestiona' => true];
        }

        // 2) Accesos que alcanzan al usuario (su nick, sus roles o toda la empresa).
        $alcance = [Where::eq('nick', $nick), Where::orSub([Where::isNull('nick'), Where::isNull('codrole')])];
        if ($roles !== []) {
            $alcance[] = Where::orIn('codrole', $roles);
        }
        $niveles = [];
        $todaEmpresa = [];
        foreach (AuralisCalendarioAcceso::all([Where::sub($alcance)], [], 0, 0) as $acceso) {
            $id = (int)$acceso->idcalendario;
            $nivel = AuralisCalendarioAcceso::nivel((string)$acceso->permiso);
            if ($acceso->esTodaLaEmpresa()) {
                $todaEmpresa[$id] = max($todaEmpresa[$id] ?? 0, $nivel);
                continue;
            }
            $niveles[$id] = max($niveles[$id] ?? 0, $nivel);
        }

        $ids = array_values(array_diff(array_keys($niveles + $todaEmpresa), array_keys($mapa)));
        if ($ids !== []) {
            $compartidos = AuralisCalendario::all([Where::in('idcalendario', $ids), Where::eq('activo', true)], [], 0, 0);
            foreach ($compartidos as $calendario) {
                $id = (int)$calendario->idcalendario;
                if ($calendario->esModulo()) {
                    continue;
                }
                // Un personal sin propietario solo lo ven los admin (más abajo).
                if ($calendario->esPersonal() && empty($calendario->propietario)) {
                    continue;
                }

                $enSuEmpresa = AuralisSecurity::canAccessEmpresa($user, $calendario->idempresa ?? 0);
                $nivel = $niveles[$id] ?? 0;
                if ($enSuEmpresa) {
                    $nivel = max($nivel, $todaEmpresa[$id] ?? 0);
                }
                // Los comunes solo se ven en las empresas del usuario.
                if ($calendario->esComun() && false === $enSuEmpresa) {
                    continue;
                }
                if ($nivel > 0) {
                    $mapa[$id] = ['calendario' => $calendario, 'permiso' => self::permisoDeNivel($nivel), 'gestiona' => false];
                }
            }
        }

        // 3) Gestores de comunes (y admin): todos los comunes activos de sus empresas, con editar.
        if ($gestor) {
            $comunes = AuralisCalendario::all([
                Where::eq('tipo', AuralisCalendario::TIPO_COMUN),
                Where::eq('activo', true),
            ], [], 0, 0);
            foreach ($comunes as $calendario) {
                if (AuralisSecurity::canAccessEmpresa($user, $calendario->idempresa ?? 0)) {
                    $mapa[(int)$calendario->idcalendario] = ['calendario' => $calendario, 'permiso' => self::EDITAR, 'gestiona' => true];
                }
            }
        }

        // 4) Admin: personales que se quedaron sin propietario.
        if ($admin) {
            $huerfanos = AuralisCalendario::all([
                Where::eq('tipo', AuralisCalendario::TIPO_PERSONAL),
                Where::isNull('propietario'),
            ], [], 0, 0);
            foreach ($huerfanos as $calendario) {
                $mapa[(int)$calendario->idcalendario] = ['calendario' => $calendario, 'permiso' => self::EDITAR, 'gestiona' => true];
            }
        }

        // 5) Módulos activos: los ve quien tiene permiso a la página de su calendario.
        foreach (self::calendariosModulo() as $calendario) {
            $modulo = CalendarOrigins::MODULOS[(string)$calendario->codigo] ?? null;
            if ($modulo === null || false === (bool)$calendario->activo) {
                continue;
            }
            if ($user->can($modulo['controlador'])) {
                $mapa[(int)$calendario->idcalendario] = ['calendario' => $calendario, 'permiso' => self::VER, 'gestiona' => false];
            }
        }

        return $mapa;
    }

    /**
     * Filas de módulo de los plugins activos (crea las que falten).
     *
     * @return AuralisCalendario[]
     */
    private static function calendariosModulo(): array
    {
        $activos = CalendarOrigins::modulosActivos();
        if ($activos === []) {
            return [];
        }

        $where = [Where::eq('tipo', AuralisCalendario::TIPO_MODULO), Where::in('codigo', array_keys($activos))];
        $filas = AuralisCalendario::all($where, [], 0, 0);
        if (count($filas) < count($activos) && self::asegurarCalendariosModulo($activos) > 0) {
            $filas = AuralisCalendario::all($where, [], 0, 0);
        }

        return $filas;
    }

    /**
     * @param array<string, AuralisCalendarioPreferencia> $preferencias
     *
     * @return array<string, mixed>
     */
    private static function formatear(
        AuralisCalendario $calendario,
        string $permiso,
        string $nick,
        array $preferencias,
        bool $gestiona
    ): array {
        $id = (int)$calendario->idcalendario;
        $clave = AuralisCalendarioPreferencia::claveDe($id);
        $preferencia = $preferencias[$clave] ?? null;
        $colorCalendario = AuralisCalendarEvent::normalizarColorHex((string)$calendario->color, AuralisCalendario::COLOR_DEFECTO);
        $modulo = $calendario->esModulo() ? (CalendarOrigins::MODULOS[(string)$calendario->codigo] ?? null) : null;

        return [
            'idcalendario' => $id,
            'clave' => $clave,
            'codigo' => $calendario->codigo === null ? null : (string)$calendario->codigo,
            'nombre' => (string)$calendario->nombre,
            'tipo' => (string)$calendario->tipo,
            'propietario' => $calendario->propietario === null ? null : (string)$calendario->propietario,
            'propio' => $calendario->esPersonal() && (string)$calendario->propietario === $nick,
            'principal' => $calendario->esPersonal() && (bool)$calendario->principal,
            'idempresa' => empty($calendario->idempresa) ? null : (int)$calendario->idempresa,
            'activo' => (bool)$calendario->activo,
            'permiso' => $permiso,
            'color' => $preferencia !== null && !empty($preferencia->color) ? (string)$preferencia->color : $colorCalendario,
            'color_calendario' => $colorCalendario,
            'visible' => self::esVisible($calendario, $preferencia),
            'puede_gestionar' => $gestiona,
            'url' => $modulo !== null ? $modulo['controlador'] : null,
        ];
    }

    /**
     * @return array<string, AuralisCalendarioPreferencia>
     */
    private static function preferenciasDe(string $nick): array
    {
        $preferencias = [];
        foreach (AuralisCalendarioPreferencia::all([Where::eq('nick', $nick)], [], 0, 0) as $preferencia) {
            $preferencias[(string)$preferencia->clave] = $preferencia;
        }

        return $preferencias;
    }

    /**
     * @param list<array<string, mixed>> $visibles
     * @param array<int, int|string>|null $idsOClaves
     *
     * @return list<array<string, mixed>>
     */
    private static function seleccionar(array $visibles, ?array $idsOClaves): array
    {
        if ($idsOClaves === null) {
            return array_values(array_filter($visibles, static fn(array $item): bool => $item['visible']));
        }

        $ids = [];
        foreach ($idsOClaves as $valor) {
            if (false === is_scalar($valor)) {
                continue;
            }
            $texto = trim((string)$valor);
            if (str_starts_with($texto, 'cal:')) {
                $texto = mb_substr($texto, 4);
            }
            if (ctype_digit($texto) && (int)$texto > 0) {
                $ids[(int)$texto] = true;
            }
        }

        return array_values(array_filter($visibles, static fn(array $item): bool => isset($ids[$item['idcalendario']])));
    }

    /**
     * @param list<array<string, mixed>> $visibles
     *
     * @return array{cal: array<int, array<string, mixed>>, mod: array<string, array<string, mixed>>}
     */
    private static function indice(array $visibles): array
    {
        $indice = ['cal' => [], 'mod' => []];
        foreach ($visibles as $item) {
            $indice['cal'][$item['idcalendario']] = $item;
            if ($item['tipo'] === AuralisCalendario::TIPO_MODULO && $item['codigo'] !== null && $item['activo']) {
                $indice['mod'][$item['codigo']] = $item;
            }
        }

        return $indice;
    }

    /**
     * @param array{cal: array<int, array<string, mixed>>, mod: array<string, array<string, mixed>>} $indice
     *
     * @return array<string, mixed>|null
     */
    private static function calendarioDeEvento(object $evento, array $indice): ?array
    {
        $origen = (string)($evento->origen_tipo ?? '');
        if ($origen !== self::ORIGEN_GENERAL) {
            return $indice['mod'][$origen] ?? null;
        }

        return $indice['cal'][(int)($evento->idcalendario ?? 0)] ?? null;
    }

    /**
     * Copia del evento con solo el hueco horario: nada de texto, cliente ni vínculos.
     */
    private static function enmascararOcupado(object $evento, string $color): object
    {
        $copia = clone $evento;
        $copia->titulo = Tools::trans('auralis-calendario-ocupado');
        $copia->descripcion = null;
        $copia->codcliente = null;
        $copia->idcontacto = null;
        $copia->codagente = null;
        $copia->origen_id = null;
        $copia->doc_modelo = null;
        $copia->doc_id = null;
        $copia->idtipo = null;
        $copia->idsala = null;
        $copia->codalmacen = null;
        $copia->estado = null;
        $copia->color = $color;

        return $copia;
    }

    /**
     * @param array<string, mixed> $tarjeta
     * @param array<string, mixed>|null $calendario
     *
     * @return array<string, mixed>
     */
    private static function decorarTarjeta(array $tarjeta, string $permiso, ?array $calendario): array
    {
        $tarjeta['permiso'] = $permiso;
        $tarjeta['can_edit'] = $permiso === self::EDITAR;
        $tarjeta['ocupado'] = $permiso === self::OCUPADO;
        $tarjeta['calendar_clave'] = $calendario['clave'] ?? null;
        $tarjeta['calendar_color'] = $calendario['color'] ?? null;

        if ($permiso !== self::OCUPADO) {
            return $tarjeta;
        }

        $tarjeta['title'] = Tools::trans('auralis-calendario-ocupado');
        $tarjeta['descripcion'] = null;
        $tarjeta['subtitle'] = empty($tarjeta['todo_dia'])
            ? (string)($tarjeta['hora_inicio'] ?? '') . ' - ' . self::horaFinTarjeta($tarjeta)
            : '';
        $tarjeta['participants'] = [];
        $tarjeta['notifications'] = [];
        $tarjeta['url'] = '';
        $tarjeta['nick'] = '';
        $tarjeta['asignado'] = '';
        foreach (['origen_id', 'codcliente', 'doc_modelo', 'doc_id', 'doc_edit', 'codagente', 'idsala', 'codalmacen', 'idtipo', 'estado', 'estado_nombre'] as $campo) {
            $tarjeta[$campo] = null;
        }

        return $tarjeta;
    }

    /**
     * @param array<string, mixed> $tarjeta
     */
    private static function horaFinTarjeta(array $tarjeta): string
    {
        $inicio = AuralisCalendarEvent::minutosDesdeMedianoche((string)($tarjeta['hora_inicio'] ?? '00:00'));
        $fin = ($inicio + (int)($tarjeta['duracion'] ?? 0)) % 1440;

        return sprintf('%02d:%02d', intdiv($fin, 60), $fin % 60);
    }

    /**
     * @param string[] $roles
     */
    private static function esGestorComunes(?User $user, array $roles): bool
    {
        if ($user === null) {
            return false;
        }
        if ((bool)($user->admin ?? false)) {
            return true;
        }
        if (self::modoGestionComunes() !== self::GESTIONAN_ROLES) {
            return false;
        }

        $gestores = self::rolesGestores();
        return $gestores !== [] && array_intersect($gestores, $roles) !== [];
    }

    /**
     * @return string[]
     */
    private static function rolesDe(?User $user): array
    {
        $nick = self::nick($user);
        if ($nick === '') {
            return [];
        }

        $roles = [];
        foreach (RoleUser::all([Where::eq('nick', $nick)], [], 0, 0) as $relacion) {
            $codrole = trim((string)$relacion->codrole);
            if ($codrole !== '') {
                $roles[$codrole] = $codrole;
            }
        }

        return array_values($roles);
    }

    private static function permisoDeNivel(int $nivel): string
    {
        $permisos = AuralisCalendarioAcceso::permisosPermitidos();
        return $nivel > 0 ? ($permisos[$nivel - 1] ?? '') : '';
    }

    /**
     * Empresa del usuario o, si no tiene, la de por defecto; null si no existe (la FK fallaría).
     */
    public static function empresaDeUsuario(?User $user): ?int
    {
        foreach ([(int)($user->idempresa ?? 0), (int)Tools::settings('default', 'idempresa', 0)] as $idempresa) {
            if ($idempresa > 0 && (new Empresa())->load($idempresa)) {
                return $idempresa;
            }
        }

        return null;
    }

    private static function nick(?User $user): string
    {
        return $user === null ? '' : trim((string)($user->nick ?? ''));
    }

    private static function settingBool(string $clave): bool
    {
        $valor = Tools::settings(CalendarTimegrid::SETTING_GROUP, $clave, '1');
        if (is_bool($valor)) {
            return $valor;
        }

        return filter_var((string)$valor, FILTER_VALIDATE_BOOLEAN);
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Modelo de datos para eventos de calendario polimórficos centralizados en Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Session;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarMonthGrid;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarNotificationScheduler;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarParticipantSync;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventStatus;

class AuralisCalendarEvent extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $id;

    /** @var string */
    public $fecha;

    /** @var string|null */
    public $fecha_fin;

    /** @var string */
    public $hora_inicio;

    /** @var int */
    public $duracion;

    /** @var string */
    public $titulo;

    /** @var string|null */
    public $descripcion;

    /** @var string */
    public $nick;

    /** @var string|null */
    public $asignado;

    /** @var string|null */
    public $codagente;

    /** @var int|null */
    public $idsala;

    /** @var string|null */
    public $codalmacen;

    /** @var int|null */
    public $idtipo;

    /** @var int|null */
    public $idparent;

    /** @var int */
    public $se_repite;

    /** @var int|null */
    public $rep_intervalo;

    /** @var string|null */
    public $rep_unidad;

    /** @var string|null */
    public $rep_fin_tipo;

    /** @var string|null */
    public $rep_fin_fecha;

    /** @var int|null */
    public $rep_fin_count;

    /** @var int */
    public $serie_activa;

    /** @var int */
    public $rep_generados;

    /** @var int */
    public $todo_dia;

    /** @var string */
    public $origen_tipo;

    /** @var int|null */
    public $origen_id;

    /** @var string|null Modelo Dinamic del documento de venta vinculado (PresupuestoCliente, …). */
    public $doc_modelo;

    /** @var int|null PK del documento de venta vinculado. */
    public $doc_id;

    /** @var string */
    public $color;

    /** @var string|null */
    public $estado;

    /** @var string|null */
    public $codcliente;

    /** @var int|null */
    public $idcontacto;

    /** @var int */
    public $idempresa;

    /** @var int|null Calendario (auralis_calendarios) de los eventos general; NULL en los de módulo. */
    public $idcalendario;

    /** @var string|null */
    public $creacion;

    /** @var string|null */
    public $fechamod;

    public function clear(): void
    {
        parent::clear();
        $this->fecha = date('Y-m-d');
        $this->fecha_fin = null;
        $this->hora_inicio = '09:00:00';
        $this->duracion = 30;
        $this->titulo = '';
        $this->descripcion = null;
        $user = Session::user();
        $this->nick = $user ? $user->nick : '';
        $this->asignado = $this->nick;
        $this->codagente = null;
        $this->idsala = null;
        $this->codalmacen = $this->defaultCodalmacen();
        $this->idtipo = null;
        $this->idparent = null;
        $this->se_repite = 0;
        $this->rep_intervalo = 1;
        $this->rep_unidad = 'week';
        $this->rep_fin_tipo = 'nunca';
        $this->rep_fin_fecha = null;
        $this->rep_fin_count = null;
        $this->serie_activa = 1;
        $this->rep_generados = 1;
        $this->todo_dia = 0;
        $this->origen_tipo = 'general';
        $this->origen_id = null;
        $this->doc_modelo = null;
        $this->doc_id = null;
        $this->color = '#4285f4';
        $this->estado = null;
        $this->codcliente = null;
        $this->idcontacto = null;
        $this->idcalendario = null;

        // Si el usuario tiene idempresa en sesión se usa esa, de lo contrario se usa la del panel de control
        $empresaId = ($user && !empty($user->idempresa)) ? (int)$user->idempresa : (int)Tools::settings('default', 'idempresa', 1);
        $this->idempresa = $empresaId > 0 ? $empresaId : 1;

        $this->creacion = Tools::dateTime();
        $this->fechamod = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_events';
    }

    public function delete(): bool
    {
        $id = (int)($this->id ?? 0);
        $titulo = (string)($this->titulo ?? '');
        $nick = (string)($this->nick ?? '');
        $user = Session::user();
        $deletedBy = $user ? $user->nick : 'desconocido';

        if ($id > 0) {
            CalendarNotificationScheduler::cancelForEvent($id);
        }

        if (parent::delete()) {
            Tools::log()->notice(
                Tools::trans(
                    'calendar-event-audit-deleted',
                    ['%id%' => (string)$id, '%titulo%' => $titulo, '%nick%' => $nick, '%deletedBy%' => $deletedBy]
                )
            );
            return true;
        }

        return false;
    }

    public function test(): bool
    {
        $this->titulo = trim(Tools::noHtml($this->titulo ?? ''));
        if (empty($this->titulo)) {
            Tools::log()->warning('calendar-event-title-required');
            return false;
        }

        if ($this->descripcion !== null && $this->descripcion !== '') {
            $this->descripcion = Tools::noHtml((string)$this->descripcion);
        } else {
            $this->descripcion = null;
        }

        $this->color = self::normalizarColorHex((string)($this->color ?? ''));

        $this->todo_dia = !empty($this->todo_dia) ? 1 : 0;
        $this->se_repite = !empty($this->se_repite) ? 1 : 0;
        $this->serie_activa = !empty($this->serie_activa) ? 1 : 0;
        $this->rep_generados = max(1, (int)($this->rep_generados ?? 1));

        if ($this->se_repite === 1) {
            $this->rep_intervalo = max(1, (int)($this->rep_intervalo ?? 1));
            $validUnits = ['day', 'week', 'month'];
            if (!in_array((string)$this->rep_unidad, $validUnits, true)) {
                $this->rep_unidad = 'week';
            }
            $validEnds = ['nunca', 'fecha', 'count'];
            if (!in_array((string)$this->rep_fin_tipo, $validEnds, true)) {
                $this->rep_fin_tipo = 'nunca';
            }
            if ($this->rep_fin_tipo === 'fecha' && !empty($this->rep_fin_fecha)) {
                $this->rep_fin_fecha = date('Y-m-d', strtotime((string)$this->rep_fin_fecha));
            } else {
                $this->rep_fin_fecha = null;
            }
            if ($this->rep_fin_tipo === 'count') {
                $this->rep_fin_count = max(1, (int)($this->rep_fin_count ?? 1));
            } else {
                $this->rep_fin_count = null;
            }
        } else {
            $this->rep_intervalo = null;
            $this->rep_unidad = null;
            $this->rep_fin_tipo = null;
            $this->rep_fin_fecha = null;
            $this->rep_fin_count = null;
        }

        if ($this->codagente !== null && $this->codagente !== '') {
            $this->codagente = Tools::noHtml((string)$this->codagente);
        } else {
            $this->codagente = null;
        }

        if ($this->idsala !== null && $this->idsala !== '') {
            $this->idsala = (int)$this->idsala;
        } else {
            $this->idsala = null;
        }

        if ($this->codalmacen !== null && $this->codalmacen !== '') {
            $this->codalmacen = Tools::noHtml((string)$this->codalmacen);
        } else {
            $this->codalmacen = null;
        }

        if ($this->idtipo !== null && $this->idtipo !== '') {
            $this->idtipo = (int)$this->idtipo;
        } else {
            $this->idtipo = null;
        }

        if ($this->idparent !== null && $this->idparent !== '') {
            $this->idparent = (int)$this->idparent;
        } else {
            $this->idparent = null;
        }

        if ($this->todo_dia === 1) {
            $this->hora_inicio = '00:00:00';
            $this->duracion = 0;
            if (!empty($this->fecha_fin)) {
                $this->fecha_fin = date('Y-m-d', strtotime((string)$this->fecha_fin));
                if ($this->fecha_fin < $this->fecha) {
                    $this->fecha_fin = $this->fecha;
                }
            } else {
                $this->fecha_fin = $this->fecha;
            }
        } else {
            $this->fecha_fin = null;
            $this->duracion = (int)$this->duracion;
            if ($this->duracion < 5 || $this->duracion > 480) {
                $this->duracion = 30;
            }

            if (empty($this->hora_inicio)) {
                $this->hora_inicio = '09:00:00';
            } elseif (mb_strlen($this->hora_inicio) === 5) {
                $this->hora_inicio .= ':00';
            }
        }

        if (!empty($this->estado)) {
            $estado = mb_strtolower(trim((string)$this->estado));
            if ($estado === '' || false === $this->esEstadoEventoValido($estado)) {
                $this->estado = $estado === '' ? null : 'pendiente';
            } else {
                $this->estado = $estado;
            }
        } else {
            $this->estado = null;
        }

        if (empty($this->origen_tipo)) {
            $this->origen_tipo = 'general';
        }

        // Solo los eventos general viven en un calendario; los de módulo se enlazan por origen_tipo.
        $this->idcalendario = (empty($this->idcalendario) || $this->origen_tipo !== 'general')
            ? null
            : (int)$this->idcalendario;
        if ($this->origen_tipo === 'general' && $this->idcalendario === null) {
            // Alta fuera de la agenda (ficha central, otros plugins): al principal del organizador.
            $this->idcalendario = $this->calendarioPrincipalOrganizador();
        }

        $this->fechamod = Tools::dateTime();
        return parent::test();
    }

    private function calendarioPrincipalOrganizador(): ?int
    {
        $organizador = trim((string)($this->asignado ?: $this->nick));
        if ($organizador === '') {
            return null;
        }

        try {
            $principal = CalendarioAcceso::principalDeNick($organizador);
            return $principal === null ? null : (int)$principal->idcalendario;
        } catch (\Throwable) {
            // Tabla de calendarios aún sin crear: el evento se guarda sin calendario (lo recoge la migración).
            return null;
        }
    }

    /**
     * Valida el código de estado contra el catálogo dinámico (y legacy hardcodeado).
     * Evita reescribir silenciosamente códigos del seed (en_espera, atendido, …).
     */
    private function esEstadoEventoValido(string $codestado): bool
    {
        $codestado = mb_strtolower(trim($codestado));
        if ($codestado === '' || mb_strlen($codestado) > 20) {
            return false;
        }

        // Seed actual + códigos históricos: sin consulta BD (rendimiento y tests).
        if (in_array($codestado, [
            'pendiente',
            'confirmado',
            'completado',
            'cancelado',
            'en_espera',
            'atendido',
        ], true)) {
            return true;
        }

        // Códigos personalizados del catálogo Agenda → Estados.
        try {
            $status = new AuralisCalendarEventStatus();
            return $status->load($codestado);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function normalizarColorHex(string $color, string $default = '#4285f4'): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $default;
    }

    /**
     * Calcula la hora de fin sumando la duración en minutos.
     */
    public function horaFin(): string
    {
        $parts = explode(':', $this->hora_inicio);
        $hours = (int)($parts[0] ?? 9);
        $minutes = (int)($parts[1] ?? 0);
        $totalMinutes = ($hours * 60) + $minutes + $this->duracion;

        $endHour = floor($totalMinutes / 60) % 24;
        $endMin = $totalMinutes % 60;

        return sprintf('%02d:%02d', $endHour, $endMin);
    }

    /**
     * Calcula el porcentaje superior (top) y de altura (height) para renderizar en Timegrid.
     */
    public function calcularPosicion(int $horaInicioRango = 0, int $rangoHoras = CalendarTimegrid::TOTAL_HORAS): array
    {
        $parts = explode(':', $this->hora_inicio);
        $hours = (int)($parts[0] ?? 9);
        $minutes = (int)($parts[1] ?? 0);

        $startMinutesTotal = ($hours * 60) + $minutes;
        $rangeStartMinutes = $horaInicioRango * 60;
        $rangeTotalMinutes = $rangoHoras * 60;

        $topPct = max(0.0, (($startMinutesTotal - $rangeStartMinutes) / $rangeTotalMinutes) * 100);
        $heightPct = max(1.38, ($this->duracion / $rangeTotalMinutes) * 100);

        return [
            'top_pct' => round($topPct, 2),
            'height_pct' => round($heightPct, 2),
        ];
    }

    /**
     * Genera la URL dinámica según el origen_tipo y origen_id para navegar al registro padre.
     */
    public function url(string $type = 'auto', string $list = 'List'): string
    {
        $return = $this->pipe('url', $type, $list);
        if ($return) {
            return $return;
        }

        if ((string)$this->origen_tipo === 'zsalud') {
            $value = $this->id();
            return match ($type) {
                'list' => 'ListZSaludEventos',
                'new' => 'EditAuralisCalendarEvent',
                'edit' => is_null($value)
                    ? 'EditAuralisCalendarEvent'
                    : 'EditAuralisCalendarEvent?code=' . rawurlencode((string)$value),
                default => is_null($value)
                    ? 'ListZSaludEventos'
                    : 'EditAuralisCalendarEvent?code=' . rawurlencode((string)$value),
            };
        }

        if ((string)$this->origen_tipo === 'zveterinaria') {
            $value = $this->id();
            $origenId = $this->origen_id;
            $listUrl = empty($origenId)
                ? 'ListMascota'
                : 'EditMascota?code=' . rawurlencode((string)$origenId) . '&activetab=ListAuralisCalendarEvent';

            return match ($type) {
                'list' => $listUrl,
                'new' => 'EditAuralisCalendarEvent',
                'edit' => is_null($value)
                    ? 'EditAuralisCalendarEvent'
                    : 'EditAuralisCalendarEvent?code=' . rawurlencode((string)$value),
                default => is_null($value)
                    ? $listUrl
                    : 'EditAuralisCalendarEvent?code=' . rawurlencode((string)$value),
            };
        }

        if (empty($this->origen_tipo) || $this->origen_tipo === 'general' || empty($this->origen_id)) {
            return '#';
        }

        switch ($this->origen_tipo) {
            case 'citas':
                return 'EditCita?code=' . $this->origen_id;
            case 'crm':
                return 'EditOportunidad?code=' . $this->origen_id;
            default:
                return '#';
        }
    }

    /**
     * Comprueba si el organizador o un participante interno tiene solape en esa franja.
     */
    public function solapaCon(string $fecha, string $horaInicio, int $duracion, ?int $excludeId = null): bool
    {
        $parts = explode(':', $horaInicio);
        $startMin = ((int)($parts[0] ?? 0) * 60) + (int)($parts[1] ?? 0);
        $endMin = $startMin + $duracion;

        $organizador = trim((string)($this->asignado ?: $this->nick));
        $nicks = $organizador !== '' ? [$organizador] : [];
        foreach (CalendarParticipantSync::internalNicksForEvent($this) as $nick) {
            if ($nick !== '' && !in_array($nick, $nicks, true)) {
                $nicks[] = $nick;
            }
        }
        if ($nicks === []) {
            return false;
        }

        $where = [
            Where::eq('fecha', $fecha),
            Where::sub([
                Where::in('asignado', $nicks),
                Where::orIn('nick', $nicks),
            ]),
        ];

        if ($excludeId) {
            $where[] = Where::neq('id', $excludeId);
        }

        $eventos = static::all($where);
        foreach ($eventos as $ev) {
            if (!empty($ev->todo_dia)) {
                continue;
            }
            $evParts = explode(':', (string)$ev->hora_inicio);
            $evStartMin = ((int)($evParts[0] ?? 0) * 60) + (int)($evParts[1] ?? 0);
            $evEndMin = $evStartMin + (int)$ev->duracion;

            if ($startMin < $evEndMin && $endMin > $evStartMin) {
                // Solo contar solape si el organizador/participante en común está implicado.
                $evOrg = trim((string)($ev->asignado ?: $ev->nick));
                $evNicks = CalendarParticipantSync::internalNicksForEvent($ev);
                if ($evOrg !== '' && !in_array($evOrg, $evNicks, true)) {
                    $evNicks[] = $evOrg;
                }
                if (array_intersect($nicks, $evNicks) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calcula la fecha de inicio y fin según el rango especificado ('today', '1day', '3days', 'week', 'month', 'custom').
     */
    public static function calculateDateRange(string $rango = 'month', string $desde = '', string $hasta = ''): array
    {
        if (!empty($desde) && !empty($hasta)) {
            return ['desde' => $desde, 'hasta' => $hasta];
        }

        $now = new \DateTime();
        switch ($rango) {
            case 'today':
            case '1day':
                $startDate = clone $now;
                $endDate = clone $now;
                break;
            case '3days':
                $startDate = clone $now;
                $endDate = (clone $now)->modify('+2 days');
                break;
            case 'week':
                $dayOfWeek = (int)$now->format('N'); // 1 (Lun) a 7 (Dom)
                $startDate = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days');
                $endDate = (clone $startDate)->modify('+6 days');
                break;
            case 'month':
            default:
                $startDate = (clone $now)->modify('first day of this month');
                $endDate = (clone $now)->modify('last day of this month');
                break;
        }

        return [
            'desde' => $startDate->format('Y-m-d'),
            'hasta' => $endDate->format('Y-m-d')
        ];
    }

    /**
     * Convierte hora HH:MM(:SS) a minutos desde medianoche.
     */
    public static function minutosDesdeMedianoche(string $hora): int
    {
        $parts = explode(':', $hora);
        return ((int)($parts[0] ?? 0) * 60) + (int)($parts[1] ?? 0);
    }

    /**
     * Packing horizontal estilo Google Calendar / Outlook: columnas laterales para eventos coincidentes.
     * Añade left_pct y width_pct a cada card (0/100 si no hay solape).
     *
     * @param array<int, array<string, mixed>> $timedCards
     */
    public static function aplicarLayoutSolapes(array &$timedCards): void
    {
        $n = count($timedCards);
        if ($n === 0) {
            return;
        }

        if ($n === 1) {
            $timedCards[0]['left_pct'] = 0.0;
            $timedCards[0]['width_pct'] = 100.0;
            return;
        }

        $items = [];
        foreach ($timedCards as $idx => $card) {
            $start = self::minutosDesdeMedianoche((string)($card['hora_inicio'] ?? '00:00'));
            $duracion = max(1, (int)($card['duracion'] ?? 60));
            $items[] = [
                'idx' => $idx,
                'start' => $start,
                'end' => $start + $duracion,
                'duracion' => $duracion,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }
            return $b['duracion'] <=> $a['duracion'];
        });

        $assigned = array_fill(0, $n, false);
        $clusters = [];

        foreach ($items as $i => $item) {
            if ($assigned[$i]) {
                continue;
            }

            $cluster = [$i];
            $assigned[$i] = true;
            $queue = [$i];

            while ($queue !== []) {
                $cur = array_shift($queue);
                $curItem = $items[$cur];
                foreach ($items as $j => $other) {
                    if ($assigned[$j]) {
                        continue;
                    }
                    if ($curItem['start'] < $other['end'] && $other['start'] < $curItem['end']) {
                        $assigned[$j] = true;
                        $cluster[] = $j;
                        $queue[] = $j;
                    }
                }
            }

            $clusters[] = $cluster;
        }

        foreach ($clusters as $cluster) {
            $clusterItems = [];
            foreach ($cluster as $itemPos) {
                $clusterItems[] = $items[$itemPos];
            }

            usort($clusterItems, static function (array $a, array $b): int {
                if ($a['start'] !== $b['start']) {
                    return $a['start'] <=> $b['start'];
                }
                return $b['duracion'] <=> $a['duracion'];
            });

            $colEnds = [];
            $colByIdx = [];

            foreach ($clusterItems as $ci) {
                $col = 0;
                while (isset($colEnds[$col]) && $colEnds[$col] > $ci['start']) {
                    $col++;
                }
                $colEnds[$col] = $ci['end'];
                $colByIdx[$ci['idx']] = $col;
            }

            $colCount = max(1, count($colEnds));
            $widthPct = round(100.0 / $colCount, 2);

            foreach ($colByIdx as $cardIdx => $colIndex) {
                $timedCards[$cardIdx]['left_pct'] = round($colIndex * $widthPct, 2);
                $timedCards[$cardIdx]['width_pct'] = $widthPct;
            }
        }
    }

    /**
     * Color del texto de una tarjeta sobre su fondo: blanco salvo que no llegue a 3:1 de contraste (WCAG AA
     * para texto en negrita / UI) y el oscuro contraste más. Un color no hexadecimal (clase CSS) deja blanco.
     */
    public static function colorTextoSobre(string $fondo): string
    {
        $blanco = '#ffffff';
        $oscuro = '#1f2937';
        $hex = ltrim(trim($fondo), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || false === ctype_xdigit($hex)) {
            return $blanco;
        }

        $luminancia = self::luminanciaRelativa($hex);
        $contrasteBlanco = 1.05 / ($luminancia + 0.05);
        $contrasteOscuro = ($luminancia + 0.05) / (self::luminanciaRelativa(ltrim($oscuro, '#')) + 0.05);

        return ($contrasteBlanco < 3.0 && $contrasteOscuro > $contrasteBlanco) ? $oscuro : $blanco;
    }

    private static function luminanciaRelativa(string $hex): float
    {
        $canales = [];
        foreach ([0, 2, 4] as $pos) {
            $c = hexdec(substr($hex, $pos, 2)) / 255;
            $canales[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    /**
     * Nombre de cada estado usado por los eventos (codestado => nombre), en una sola consulta.
     *
     * @param array<int, object> $events
     *
     * @return array<string, string>
     */
    private static function nombresEstado(array $events): array
    {
        $codigos = [];
        foreach ($events as $ev) {
            if (!empty($ev->estado)) {
                $codigos[(string)$ev->estado] = true;
            }
        }
        if ($codigos === []) {
            return [];
        }

        $nombres = [];
        try {
            foreach (AuralisCalendarEventStatus::all([Where::in('codestado', array_keys($codigos))], [], 0, 0) as $estado) {
                $nombres[(string)$estado->codestado] = (string)$estado->nombre;
            }
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('Auralis: no se pudieron leer los nombres de estado del calendario: ' . $e->getMessage());
            return [];
        }

        return $nombres;
    }

    /**
     * Agrupa una lista de AuralisCalendarEvent por días para pasarlos al partial Twig del calendario.
     */
    public static function groupEventsByDays(array $events, string $desde, string $hasta): array
    {
        $eventsByDate = [];
        $eventsAlldayByDate = [];
        $eventsTimedByDate = [];

        $nombresEstado = self::nombresEstado($events);

        $parentsById = [];
        foreach ($events as $ev) {
            if (empty($ev->idparent) && !empty($ev->se_repite)) {
                $parentsById[(int)$ev->id] = $ev;
            }
        }
        // Cargar padres faltantes si en el rango solo hay hijos
        foreach ($events as $ev) {
            $pid = (int)($ev->idparent ?? 0);
            if ($pid > 0 && !isset($parentsById[$pid])) {
                $parent = new static();
                if ($parent->load($pid)) {
                    if (empty($parent->idempresa) || empty($ev->idempresa) || (int)$parent->idempresa === (int)$ev->idempresa) {
                        $parentsById[$pid] = $parent;
                    }
                }
            }
        }

        foreach ($events as $ev) {
            $startDateStr = !empty($ev->fecha) ? date('Y-m-d', strtotime($ev->fecha)) : date('Y-m-d');
            $endDateStr = (!empty($ev->fecha_fin) && !empty($ev->todo_dia)) ? date('Y-m-d', strtotime($ev->fecha_fin)) : $startDateStr;
            if ($endDateStr < $startDateStr) {
                $endDateStr = $startDateStr;
            }

            $pos = $ev->calcularPosicion(0, CalendarTimegrid::TOTAL_HORAS);
            $subtitle = !empty($ev->todo_dia) ?
                ($ev->descripcion ? (string)$ev->descripcion : '') :
                (substr($ev->hora_inicio, 0, 5) . ' - ' . $ev->horaFin() . ($ev->descripcion ? ' | ' . $ev->descripcion : ''));

            $ruleSource = $ev;
            $pid = (int)($ev->idparent ?? 0);
            if ($pid > 0 && isset($parentsById[$pid])) {
                $ruleSource = $parentsById[$pid];
            }

            $cardData = [
                'id' => $ev->id,
                'fecha' => $startDateStr,
                'fecha_fin' => $endDateStr,
                'hora_inicio' => substr($ev->hora_inicio, 0, 5),
                'duracion' => $ev->duracion,
                'title' => $ev->titulo,
                'descripcion' => $ev->descripcion,
                'subtitle' => $subtitle,
                'badge_color' => 'blue',
                'badge_text' => 'Evento',
                'bg_color' => $ev->color,
                'border_color' => $ev->color,
                'text_color' => self::colorTextoSobre((string)($ev->color ?? '')),
                'top_pct' => $pos['top_pct'],
                'height_pct' => $pos['height_pct'],
                'url' => $ev->url(),
                'nick' => $ev->nick,
                'asignado' => $ev->asignado ?: $ev->nick,
                'todo_dia' => (int)($ev->todo_dia ?? 0),
                'origen_tipo' => $ev->origen_tipo,
                'origen_id' => $ev->origen_id,
                'idcalendario' => !empty($ev->idcalendario) ? (int)$ev->idcalendario : null,
                'doc_modelo' => $ev->doc_modelo ?? null,
                'doc_id' => !empty($ev->doc_id) ? (int) $ev->doc_id : null,
                'doc_edit' => !empty($ev->doc_modelo) && !empty($ev->doc_id)
                    ? CalendarTimegrid::editControllerParaTipoDoc((string) $ev->doc_modelo)
                    : null,
                'codagente' => $ev->codagente ?? null,
                'idsala' => $ev->idsala ?? null,
                'codalmacen' => $ev->codalmacen ?? null,
                'idtipo' => $ev->idtipo ?? null,
                'estado' => $ev->estado ?? 'pendiente',
                'estado_nombre' => $nombresEstado[(string)($ev->estado ?? 'pendiente')] ?? null,
                'idparent' => $ev->idparent ?? null,
                'se_repite' => (int)($ruleSource->se_repite ?? 0),
                'serie_activa' => (int)($ruleSource->serie_activa ?? 0),
                'rep_intervalo' => $ruleSource->rep_intervalo ?? null,
                'rep_unidad' => $ruleSource->rep_unidad ?? null,
                'rep_fin_tipo' => $ruleSource->rep_fin_tipo ?? null,
                'rep_fin_fecha' => $ruleSource->rep_fin_fecha ?? null,
                'rep_fin_count' => $ruleSource->rep_fin_count ?? null,
                'icon' => 'fa-solid fa-calendar-check',
                'notifications' => AuralisCalendarEventNotification::toArrayList((int)$ev->id),
                'participants' => AuralisCalendarEventParticipant::toArrayList((int)$ev->id),
            ];

            $currEv = new \DateTime($startDateStr);
            $endEv = new \DateTime($endDateStr);
            while ($currEv <= $endEv) {
                $dateKey = $currEv->format('Y-m-d');
                $eventsByDate[$dateKey][] = $cardData;
                if (!empty($ev->todo_dia)) {
                    $eventsAlldayByDate[$dateKey][] = $cardData;
                } else {
                    $eventsTimedByDate[$dateKey][] = $cardData;
                }
                $currEv->modify('+1 day');
            }
        }

        $dayOfWeekNamesFull = array_map(
            static fn(string $clave): string => Tools::trans($clave),
            ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
        );
        $days = [];
        $start = new \DateTime(!empty($desde) ? $desde : date('Y-m-01'));
        $end = new \DateTime(!empty($hasta) ? $hasta : date('Y-m-t'));
        $current = clone $start;

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $dayOfWeek = (int)$current->format('w');

            $timedCards = $eventsTimedByDate[$dateStr] ?? [];
            self::aplicarLayoutSolapes($timedCards);

            $days[] = [
                'day_name' => $dayOfWeekNamesFull[$dayOfWeek],
                'day_num' => $current->format('d'),
                'label' => $dayOfWeekNamesFull[$dayOfWeek] . ' ' . $current->format('d'),
                'date' => $dateStr,
                'is_today' => $dateStr === date('Y-m-d'),
                'net_amount' => '',
                'net_class' => '',
                'cards' => $eventsByDate[$dateStr] ?? [],
                'allday_cards' => $eventsAlldayByDate[$dateStr] ?? [],
                'timed_cards' => $timedCards,
            ];

            $current->modify('+1 day');
        }

        return CalendarMonthGrid::prependFillers(
            $days,
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        );
    }

    private function defaultCodalmacen(): ?string
    {
        $user = Session::user();
        if ($user && !empty($user->codalmacen)) {
            return (string)$user->codalmacen;
        }

        $def = (string)Tools::settings('default', 'codalmacen', '');
        return $def !== '' ? $def : null;
    }
}

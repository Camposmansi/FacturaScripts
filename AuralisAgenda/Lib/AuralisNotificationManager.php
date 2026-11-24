<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Fachada estática del motor in-app de notificaciones Auralis (calendario + novedades).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Dinamic\Model\AuralisNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;

final class AuralisNotificationManager
{
    public const SETTING_INAPP_ENABLED = 'calendar_inapp_notifications';

    public const SETTING_POLL_SECONDS = 'calendar_inapp_poll_seconds';

    public const DEFAULT_POLL_SECONDS = 75;

    public const DROPDOWN_LIMIT = 10;

    public const SNOOZE_MINUTES = [5, 15, 60];

    public const ALARM_HORIZON_SECONDS = 7200;

    /** Días hacia atrás para materializar avisos perdidos (missed reminders). */
    public const MISSED_LOOKBACK_DAYS = 7;

    /** @var bool|null */
    private static $tableReady = null;

    /**
     * Fuerza la sincronización XML→BD (DbUpdater) e indica si la tabla está lista.
     * Idempotente: tras el primer check, ModelClass no vuelve a inspeccionar el schema.
     */
    public static function ensureSchema(): bool
    {
        try {
            new AuralisNotification();
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-notif-schema: ' . $e->getMessage());
            self::$tableReady = false;
            return false;
        }

        self::$tableReady = null;
        return self::tableReady();
    }

    public static function tableReady(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }

        $db = new DataBase();
        if (false === $db->connected()) {
            $db->connect();
        }

        self::$tableReady = $db->tableExists(AuralisNotification::tableName());
        return self::$tableReady;
    }

    /** @internal Solo para tests: resetea la caché de existencia de tabla. */
    public static function resetTableReadyCache(): void
    {
        self::$tableReady = null;
    }

    public static function isInAppEnabled(): bool
    {
        return (int)Tools::settings(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_INAPP_ENABLED,
            '1'
        ) === 1;
    }

    public static function pollSeconds(): int
    {
        $seconds = (int)Tools::settings(
            CalendarTimegrid::SETTING_GROUP,
            self::SETTING_POLL_SECONDS,
            (string)self::DEFAULT_POLL_SECONDS
        );

        return max(60, min(90, $seconds));
    }

    /**
     * Crea avisos de un tipo personal para los destinatarios indicados.
     * Los suscribibles no se reparten aquí. Devuelve cuántas filas nuevas se guardaron.
     *
     * @param list<string>|null $destinatarios
     */
    public static function emit(
        string $tipo,
        string $referencia,
        string $titulo,
        string $url,
        ?array $destinatarios = null
    ): int {
        $tipo = Tools::noHtml(trim($tipo));
        $definicion = NotificationTypeRegistry::get($tipo);
        if ($definicion === null || false === $definicion->emisible()) {
            return 0;
        }

        if ($definicion->audiencia() !== NotificationType::AUDIENCIA_PERSONAL) {
            return 0;
        }

        $referencia = trim($referencia);
        $titulo = Tools::noHtml(trim($titulo));
        if ($referencia === '' || $titulo === '') {
            return 0;
        }

        $nicks = self::nicksUnicos($destinatarios);
        if ($nicks === []) {
            return 0;
        }

        $creadas = 0;
        foreach ($nicks as $nick) {
            if (false === self::destinatarioPuedeRecibir($nick, $definicion->permiso())) {
                continue;
            }

            $ok = self::notify([
                'nick' => $nick,
                'tipo' => $tipo,
                'titulo' => $titulo,
                'url' => $url,
                'referencia' => $referencia,
                'dedup_key' => self::buildDedupKey($tipo, $nick, $referencia),
            ]);

            // false no es un error: el aviso ya existía (dedup) o el guardado no creó fila.
            // No se anota en el log: una emisión repetida lo dispararía en cada pasada.
            if ($ok) {
                ++$creadas;
            }
        }

        return $creadas;
    }

    public static function buildDedupKey(string $tipo, string $nick, string $payload): string
    {
        $raw = $tipo . '|' . $nick . '|' . $payload;
        return substr(hash('sha256', $raw), 0, 40);
    }

    /**
     * Crea una notificación in-app si no existe (UNIQUE nick+dedup_key).
     *
     * @param array{
     *     nick: string,
     *     tipo?: string,
     *     titulo: string,
     *     cuerpo?: string,
     *     url?: string,
     *     idevent?: int|null,
     *     idcalendarnotif?: int|null,
     *     dedup_key: string,
     *     referencia?: string|null,
     *     missed?: bool,
     *     skip_exists_check?: bool
     * } $data
     */
    public static function notify(array $data): bool
    {
        if (false === self::ensureSchema()) {
            return false;
        }

        $nick = Tools::noHtml(trim((string)($data['nick'] ?? '')));
        $dedup = Tools::noHtml(trim((string)($data['dedup_key'] ?? '')));
        $titulo = Tools::noHtml(trim((string)($data['titulo'] ?? '')));
        $missed = !empty($data['missed']);

        if ($nick === '' || $dedup === '' || $titulo === '') {
            return false;
        }

        if (empty($data['skip_exists_check'])) {
            $existing = new AuralisNotification();
            if ($existing->loadWhere([
                Where::eq('nick', $nick),
                Where::eq('dedup_key', $dedup),
            ])) {
                return false;
            }
        }

        $row = new AuralisNotification();
        $row->nick = $nick;
        $row->tipo = (string)($data['tipo'] ?? AuralisNotification::TYPE_CALENDAR);
        $row->titulo = $titulo;
        $row->cuerpo = (string)($data['cuerpo'] ?? '');
        $row->url = (string)($data['url'] ?? '');
        $row->idevent = isset($data['idevent']) ? (int)$data['idevent'] : null;
        $row->idcalendarnotif = isset($data['idcalendarnotif']) ? (int)$data['idcalendarnotif'] : null;
        $row->dedup_key = $dedup;
        $referencia = NotificationInsistence::normalizarReferencia(
            Tools::noHtml(trim((string)($data['referencia'] ?? '')))
        );
        $row->referencia = $referencia !== '' ? $referencia : null;
        $row->leida = false;
        $row->missed = $missed;
        $row->created_at = Tools::dateTime();
        // Missed: sin push de catch-up (evitar bombardeo al reconectar).
        $row->push_sent_at = $missed ? Tools::dateTime() : null;
        $row->push_estado = $missed ? AuralisNotification::PUSH_MISSED : AuralisNotification::PUSH_PENDIENTE;

        if (false === $row->save()) {
            return false;
        }

        if ($missed) {
            return true;
        }

        // Canal push: best-effort, independiente del in-app/email (no bloquea).
        try {
            AuralisPushDispatcher::tryPushForNotification($row);
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-push-after-notify: ' . $e->getMessage());
        }

        return true;
    }

    public static function countUnread(string $nick): int
    {
        $nick = trim($nick);
        if ($nick === '' || false === self::isInAppEnabled()) {
            return 0;
        }

        // Guardián [CTRL-134]: no consultar si el plugin aún no ha sincronizado el XML.
        if (false === self::tableReady() && false === self::ensureSchema()) {
            return 0;
        }

        $claves = self::clavesVisiblesDe($nick);
        if ($claves === []) {
            return 0;
        }

        $now = Tools::dateTime();
        $db = new DataBase();
        if (false === $db->connected()) {
            $db->connect();
        }

        $sql = 'SELECT COUNT(1) AS total FROM ' . AuralisNotification::tableName()
            . ' WHERE nick = ' . $db->var2str($nick)
            . ' AND tipo IN (' . self::sqlLista($db, $claves) . ')'
            . ' AND (leida = 0 OR leida = false OR leida IS NULL)'
            . ' AND (snoozed_until IS NULL OR snoozed_until <= ' . $db->var2str($now) . ')';

        $rows = $db->select($sql);
        if (empty($rows)) {
            return 0;
        }

        return (int)($rows[0]['total'] ?? 0);
    }

    public static function countUnreadForCurrentUser(): int
    {
        $user = Session::user();
        if (empty($user->nick)) {
            return 0;
        }

        return self::countUnread((string)$user->nick);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForDropdown(string $nick, int $limit = self::DROPDOWN_LIMIT): array
    {
        $nick = trim($nick);
        if ($nick === '' || (false === self::tableReady() && false === self::ensureSchema())) {
            return [];
        }

        $claves = self::clavesVisiblesDe($nick);
        if ($claves === []) {
            return [];
        }

        $now = Tools::dateTime();
        $items = [];

        foreach (AuralisNotification::all(
            [
                Where::eq('nick', $nick),
                Where::in('tipo', $claves),
            ],
            ['created_at' => 'DESC', 'id' => 'DESC'],
            0,
            max(1, $limit * 3)
        ) as $row) {
            if ($row->isSnoozed($now) && empty($row->leida)) {
                continue;
            }

            $items[] = self::toArray($row);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Alias histórico. Devuelve las ventanas de cualquier tipo que las tenga activas.
     *
     * @return list<array<string, mixed>>
     */
    public static function listDueCalendarPopups(string $nick, int $limit = 10): array
    {
        return self::listDuePopups($nick, $limit);
    }

    /**
     * Ventanas emergentes de los tipos visibles que las declaran. Los missed de agenda no entran.
     *
     * @return list<array<string, mixed>>
     */
    public static function listDuePopups(string $nick, int $limit = 10): array
    {
        $nick = trim($nick);
        if ($nick === '' || false === self::isInAppEnabled()) {
            return [];
        }
        if (false === self::tableReady() && false === self::ensureSchema()) {
            return [];
        }

        $claves = self::clavesConVentana($nick);
        if ($claves === []) {
            return [];
        }

        $now = Tools::dateTime();
        $items = [];

        foreach (AuralisNotification::all(
            [
                Where::eq('nick', $nick),
                Where::in('tipo', $claves),
                Where::eq('leida', false),
                Where::eq('missed', false),
            ],
            ['created_at' => 'ASC', 'id' => 'ASC'],
            0,
            100
        ) as $row) {
            if ($row->isMissed() || $row->isSnoozed($now)) {
                continue;
            }

            $items[] = self::toArray($row);
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public static function markRead(int $id, string $nick): bool
    {
        $row = self::loadOwned($id, $nick);
        if ($row === null || false === self::filaVisibleParaNick($row, $nick)) {
            return false;
        }

        $row->leida = true;
        $row->snoozed_until = null;
        return $row->save();
    }

    public static function markAllRead(string $nick): int
    {
        $nick = trim($nick);
        if ($nick === '') {
            return 0;
        }

        $claves = self::clavesVisiblesDe($nick);
        if ($claves === []) {
            return 0;
        }

        $updated = 0;
        foreach (AuralisNotification::all(
            [
                Where::eq('nick', $nick),
                Where::in('tipo', $claves),
                Where::eq('leida', false),
            ],
            ['id' => 'ASC'],
            0,
            500
        ) as $row) {
            $row->leida = true;
            $row->snoozed_until = null;
            if ($row->save()) {
                ++$updated;
            }
        }

        return $updated;
    }

    /**
     * Marca leídos los avisos pendientes de esa referencia, de cualquier usuario.
     * Solo si el tipo está registrado y se cierra solo. No hay acción equivalente en el ajax.
     */
    public static function resolve(string $tipo, string $referencia): int
    {
        $tipo = Tools::noHtml(trim($tipo));
        $referencia = NotificationInsistence::normalizarReferencia(Tools::noHtml(trim($referencia)));
        $definicion = NotificationTypeRegistry::get($tipo);
        if ($definicion === null || $referencia === '' || false === $definicion->cierreAutomatico()) {
            return 0;
        }

        if (false === self::tableReady() && false === self::ensureSchema()) {
            return 0;
        }

        $cerradas = 0;
        foreach (AuralisNotification::all(
            [
                Where::eq('tipo', $tipo),
                Where::eq('referencia', $referencia),
                Where::eq('leida', false),
            ],
            ['id' => 'ASC'],
            0,
            500
        ) as $row) {
            if (false === NotificationInsistence::debeCerrar(
                $definicion,
                (string)$row->tipo,
                (string)($row->referencia ?? ''),
                !empty($row->leida),
                $tipo,
                $referencia
            )) {
                continue;
            }

            $row->leida = true;
            $row->snoozed_until = null;
            if ($row->save()) {
                ++$cerradas;
            }
        }

        return $cerradas;
    }

    public static function snooze(int $id, string $nick, int $minutes): bool
    {
        if (!in_array($minutes, self::SNOOZE_MINUTES, true)) {
            return false;
        }

        $row = self::loadOwned($id, $nick);
        if ($row === null || false === self::filaVisibleParaNick($row, $nick)) {
            return false;
        }

        try {
            $until = (new \DateTimeImmutable('now'))->modify('+' . $minutes . ' minutes');
        } catch (\Exception) {
            return false;
        }

        $row->snoozed_until = $until->format('Y-m-d H:i:s');
        $row->leida = false;
        return $row->save();
    }

    public static function dismiss(int $id, string $nick): bool
    {
        return self::markRead($id, $nick);
    }

    /**
     * Materializa avisos in-app desde recordatorios de calendario con scheduled_at vencido.
     *
     * @return array{created: int, skipped: int, examined: int}
     */
    public static function materializeFromCalendar(int $batchLimit = 100): array
    {
        if (false === self::isInAppEnabled() || false === self::ensureSchema()) {
            return ['created' => 0, 'skipped' => 0, 'examined' => 0];
        }

        $now = new \DateTimeImmutable('now');
        $limit = max(1, min(200, $batchLimit));

        return self::materializeFromSqlRows(self::selectDueCalendarRows($now, $limit, null), $now, null);
    }

    /**
     * JIT acotado al usuario en sesión: solo eventos donde asignado/nick coinciden.
     *
     * @return array{created: int, skipped: int, examined: int}
     */
    public static function materializeForUser(string $nick, int $batchLimit = 30): array
    {
        $nick = trim($nick);
        if ($nick === '' || false === self::isInAppEnabled() || false === self::ensureSchema()) {
            return ['created' => 0, 'skipped' => 0, 'examined' => 0];
        }

        $now = new \DateTimeImmutable('now');
        $limit = max(1, min(100, $batchLimit));

        return self::materializeFromSqlRows(self::selectDueCalendarRows($now, $limit, $nick), $now, $nick);
    }

    /**
     * Segundos hasta la próxima alarma del usuario (calendario o snooze) en el horizonte.
     */
    public static function nextAlarmInSeconds(string $nick, int $horizonSeconds = self::ALARM_HORIZON_SECONDS): int
    {
        $nick = trim($nick);
        if ($nick === '' || false === self::isInAppEnabled()) {
            return 0;
        }

        $db = new DataBase();
        if (false === $db->connected()) {
            $db->connect();
        }

        $nowTs = time();
        $nowStr = date('Y-m-d H:i:s', $nowTs);
        $horizon = max(60, min(self::ALARM_HORIZON_SECONDS, $horizonSeconds));
        $untilStr = date('Y-m-d H:i:s', $nowTs + $horizon);
        $candidates = [];
        $claves = self::clavesVisiblesDe($nick);

        $eventsTable = AuralisCalendarEvent::tableName();
        $calNotifTable = AuralisCalendarEventNotification::tableName();
        $participantsTable = AuralisCalendarEventParticipant::tableName();
        if (in_array(AuralisNotification::TYPE_CALENDAR, $claves, true)
            && $db->tableExists($eventsTable)
            && $db->tableExists($calNotifTable)) {
            $nickSql = $db->var2str($nick);
            $participantClause = '';
            if ($db->tableExists($participantsTable)) {
                $participantClause = ' OR EXISTS ('
                    . ' SELECT 1 FROM ' . $participantsTable . ' p'
                    . ' WHERE p.idevent = e.id AND p.tipo = ' . $db->var2str('user')
                    . ' AND p.codigo = ' . $nickSql
                    . ' )';
            }
            $sql = 'SELECT MIN(n.scheduled_at) AS next_at FROM ' . $calNotifTable . ' n'
                . ' INNER JOIN ' . $eventsTable . ' e ON e.id = n.idevent'
                . ' WHERE n.scheduled_at IS NOT NULL'
                . ' AND n.scheduled_at > ' . $db->var2str($nowStr)
                . ' AND n.scheduled_at <= ' . $db->var2str($untilStr)
                . ' AND (e.asignado = ' . $nickSql . ' OR e.nick = ' . $nickSql . $participantClause . ')';
            $rows = $db->select($sql);
            $nextCal = (string)($rows[0]['next_at'] ?? '');
            if ($nextCal !== '') {
                $ts = strtotime($nextCal);
                if ($ts !== false) {
                    $candidates[] = $ts;
                }
            }
        }

        if ($claves !== [] && (self::tableReady() || self::ensureSchema())) {
            $sqlSnooze = 'SELECT MIN(snoozed_until) AS next_at FROM ' . AuralisNotification::tableName()
                . ' WHERE nick = ' . $db->var2str($nick)
                . ' AND tipo IN (' . self::sqlLista($db, $claves) . ')'
                . ' AND (leida = 0 OR leida = false OR leida IS NULL)'
                . ' AND (missed = 0 OR missed = false OR missed IS NULL)'
                . ' AND snoozed_until IS NOT NULL'
                . ' AND snoozed_until > ' . $db->var2str($nowStr)
                . ' AND snoozed_until <= ' . $db->var2str($untilStr);
            $rowsSnooze = $db->select($sqlSnooze);
            $nextSnooze = (string)($rowsSnooze[0]['next_at'] ?? '');
            if ($nextSnooze !== '') {
                $ts = strtotime($nextSnooze);
                if ($ts !== false) {
                    $candidates[] = $ts;
                }
            }
        }

        if ($candidates === []) {
            return 0;
        }

        return max(0, min($candidates) - $nowTs);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{created: int, skipped: int, examined: int}
     */
    private static function materializeFromSqlRows(array $rows, \DateTimeImmutable $now, ?string $onlyNick): array
    {
        if ($rows === []) {
            return ['created' => 0, 'skipped' => 0, 'examined' => 0];
        }

        $pending = [];
        $eventsById = [];
        foreach ($rows as $row) {
            $notifId = (int)($row['notif_id'] ?? 0);
            $eventId = (int)($row['event_id'] ?? ($row['idevent'] ?? 0));
            if ($notifId <= 0 || $eventId <= 0) {
                continue;
            }

            $calendarNotif = new AuralisCalendarEventNotification();
            $calendarNotif->loadFromData([
                'id' => $notifId,
                'idevent' => $eventId,
            ]);
            $pending[] = $calendarNotif;

            if (isset($eventsById[$eventId])) {
                continue;
            }

            $event = new AuralisCalendarEvent();
            $event->loadFromData([
                'id' => $eventId,
                'titulo' => (string)($row['titulo'] ?? ''),
                'fecha' => (string)($row['fecha'] ?? ''),
                'hora_inicio' => (string)($row['hora_inicio'] ?? ''),
                'todo_dia' => $row['todo_dia'] ?? false,
                'asignado' => (string)($row['asignado'] ?? ''),
                'nick' => (string)($row['event_nick'] ?? ''),
            ]);
            $eventsById[$eventId] = $event;
        }

        $nicksByEvent = CalendarParticipantSync::internalNicksForEvents(array_values($eventsById));
        $existing = self::existingInAppKeys($pending, $onlyNick);

        return self::materializeCalendarRows($pending, $now, $onlyNick, $eventsById, $nicksByEvent, $existing);
    }

    /**
     * @param list<AuralisCalendarEventNotification> $pending
     * @return array<string, true>
     */
    private static function existingInAppKeys(array $pending, ?string $onlyNick): array
    {
        $ids = [];
        foreach ($pending as $calendarNotif) {
            $id = (int)($calendarNotif->id ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $where = [Where::in('idcalendarnotif', $ids)];
        $nick = $onlyNick !== null ? trim($onlyNick) : '';
        if ($nick !== '') {
            $where[] = Where::eq('nick', $nick);
        }

        $keys = [];
        foreach (AuralisNotification::all($where, ['id' => 'ASC'], 0, max(1, min(5000, count($ids) * 20))) as $row) {
            $notifId = (int)($row->idcalendarnotif ?? 0);
            $rowNick = trim((string)$row->nick);
            if ($notifId <= 0 || $rowNick === '') {
                continue;
            }
            $keys[$notifId . "\0" . $rowNick] = true;
        }

        return $keys;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function selectDueCalendarRows(\DateTimeImmutable $now, int $limit, ?string $onlyNick): array
    {
        $db = new DataBase();
        if (false === $db->connected()) {
            $db->connect();
        }

        $eventsTable = AuralisCalendarEvent::tableName();
        $notifTable = AuralisCalendarEventNotification::tableName();
        $inAppTable = AuralisNotification::tableName();
        $participantsTable = AuralisCalendarEventParticipant::tableName();
        if (false === $db->tableExists($eventsTable) || false === $db->tableExists($notifTable)) {
            return [];
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        $lookbackDate = $now->modify('-' . self::MISSED_LOOKBACK_DAYS . ' days')->format('Y-m-d');
        $hasParticipants = $db->tableExists($participantsTable);
        $hasInApp = $db->tableExists($inAppTable);

        $sql = 'SELECT n.id AS notif_id, n.idevent, e.id AS event_id, e.titulo, e.fecha,'
            . ' e.hora_inicio, e.todo_dia, e.asignado, e.nick AS event_nick'
            . ' FROM ' . $notifTable . ' n'
            . ' INNER JOIN ' . $eventsTable . ' e ON e.id = n.idevent'
            . ' WHERE n.scheduled_at IS NOT NULL'
            . ' AND n.scheduled_at <= ' . $db->var2str($nowStr)
            . ' AND e.fecha >= ' . $db->var2str($lookbackDate);

        $onlyNick = $onlyNick !== null ? trim($onlyNick) : '';
        if ($onlyNick !== '') {
            $nickSql = $db->var2str($onlyNick);
            $participantClause = '';
            if ($hasParticipants) {
                $participantClause = ' OR EXISTS ('
                    . ' SELECT 1 FROM ' . $participantsTable . ' p'
                    . ' WHERE p.idevent = e.id AND p.tipo = ' . $db->var2str('user')
                    . ' AND p.codigo = ' . $nickSql
                    . ' )';
            }
            $sql .= ' AND (e.asignado = ' . $nickSql . ' OR e.nick = ' . $nickSql . $participantClause . ')';
            if ($hasInApp) {
                $sql .= ' AND NOT EXISTS ('
                    . ' SELECT 1 FROM ' . $inAppTable . ' z'
                    . ' WHERE z.idcalendarnotif = n.id AND z.nick = ' . $nickSql
                    . ')';
            }
        } elseif ($hasInApp) {
            $sql .= self::sqlMissingRecipientClause($db, $inAppTable, $participantsTable, $hasParticipants);
        }

        $sql .= ' ORDER BY n.scheduled_at ASC, n.id ASC LIMIT ' . $limit;

        $rows = $db->select($sql);
        return is_array($rows) ? $rows : [];
    }

    private static function sqlMissingRecipientClause(
        DataBase $db,
        string $inAppTable,
        string $participantsTable,
        bool $hasParticipants
    ): string {
        $empty = $db->var2str('');
        $userTipo = $db->var2str('user');
        $missingOrg = '(e.asignado IS NOT NULL AND e.asignado <> ' . $empty
            . ' AND NOT EXISTS ('
            . ' SELECT 1 FROM ' . $inAppTable . ' z'
            . ' WHERE z.idcalendarnotif = n.id AND z.nick = e.asignado'
            . '))';
        $missingCreator = '(e.nick IS NOT NULL AND e.nick <> ' . $empty
            . ' AND NOT EXISTS ('
            . ' SELECT 1 FROM ' . $inAppTable . ' z'
            . ' WHERE z.idcalendarnotif = n.id AND z.nick = e.nick'
            . '))';
        $sql = ' AND (' . $missingOrg . ' OR ' . $missingCreator;
        if ($hasParticipants) {
            $sql .= ' OR EXISTS ('
                . ' SELECT 1 FROM ' . $participantsTable . ' p'
                . ' WHERE p.idevent = e.id AND p.tipo = ' . $userTipo
                . ' AND p.codigo IS NOT NULL AND p.codigo <> ' . $empty
                . ' AND NOT EXISTS ('
                . ' SELECT 1 FROM ' . $inAppTable . ' z'
                . ' WHERE z.idcalendarnotif = n.id AND z.nick = p.codigo'
                . '))';
        }

        return $sql . ')';
    }

    /**
     * @param list<AuralisCalendarEventNotification> $pending
     * @param array<int, AuralisCalendarEvent> $eventsById
     * @param array<int, list<string>> $nicksByEvent
     * @param array<string, true> $existingKeys
     * @return array{created: int, skipped: int, examined: int}
     */
    private static function materializeCalendarRows(
        array $pending,
        \DateTimeImmutable $now,
        ?string $onlyNick,
        array $eventsById = [],
        array $nicksByEvent = [],
        array $existingKeys = []
    ): array {
        $created = 0;
        $skipped = 0;
        $onlyNick = $onlyNick !== null ? trim($onlyNick) : null;
        $lookbackStart = $now->modify('-' . self::MISSED_LOOKBACK_DAYS . ' days');

        $allUserNicks = null;
        if (CalendarTimegrid::notifAllUsers()) {
            $allUserNicks = [];
            foreach (User::all([Where::eq('enabled', true)], [], 0, 0) as $user) {
                $nick = trim((string) $user->nick);
                if ($nick !== '') {
                    $allUserNicks[] = $nick;
                }
            }
        }

        foreach ($pending as $calendarNotif) {
            $eventId = (int)$calendarNotif->idevent;
            $event = $eventsById[$eventId] ?? null;
            if ($event === null) {
                $event = new AuralisCalendarEvent();
                if (false === $event->load($eventId)) {
                    ++$skipped;
                    continue;
                }
            }

            $eventStart = AuralisCalendarEventNotification::eventDateTime($event);
            if ($eventStart === null) {
                ++$skipped;
                continue;
            }

            // Fuera del lookback: no reconstruir avisos antiguos (anti-inundación Actividad).
            if ($eventStart < $lookbackStart) {
                ++$skipped;
                continue;
            }

            $isMissed = $eventStart <= $now;

            $nicks = $allUserNicks ?? ($nicksByEvent[$eventId] ?? CalendarParticipantSync::internalNicksForEvent($event));
            if ($nicks === []) {
                ++$skipped;
                continue;
            }

            if ($onlyNick !== null) {
                if (!in_array($onlyNick, $nicks, true)) {
                    ++$skipped;
                    continue;
                }
                $nicks = [$onlyNick];
            }

            $notifId = (int)($calendarNotif->id ?? 0);

            $titulo = trim((string)($event->titulo ?? ''));
            if ($titulo === '') {
                $titulo = Tools::trans('auralis-inapp-calendar-fallback-title');
            }

            $cuerpo = Tools::trans('auralis-inapp-calendar-body', [
                '%fecha%' => (string)$event->fecha,
                '%hora%' => mb_substr((string)($event->hora_inicio ?? ''), 0, 5),
            ]);

            foreach ($nicks as $nick) {
                if (isset($existingKeys[$notifId . "\0" . $nick])) {
                    ++$skipped;
                    continue;
                }

                $dedup = self::buildDedupKey(
                    AuralisNotification::TYPE_CALENDAR,
                    $nick,
                    'event:' . $eventId . '|notif:' . $notifId
                );

                $ok = self::notify([
                    'nick' => $nick,
                    'tipo' => AuralisNotification::TYPE_CALENDAR,
                    'titulo' => $titulo,
                    'cuerpo' => $cuerpo,
                    'url' => CalendarTimegrid::topbarController() . '?idevent=' . $eventId,
                    'idevent' => $eventId,
                    'idcalendarnotif' => $notifId,
                    'dedup_key' => $dedup,
                    'missed' => $isMissed,
                    'skip_exists_check' => true,
                ]);

                if ($ok) {
                    ++$created;
                    $existingKeys[$notifId . "\0" . $nick] = true;
                } else {
                    ++$skipped;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'examined' => count($pending)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(AuralisNotification $row): array
    {
        $idevent = $row->idevent !== null ? (int)$row->idevent : null;
        $url = (string)($row->url ?? '');
        if ($idevent !== null && $idevent > 0) {
            // Deep-link: agenda + abrir modal del evento.
            $url = CalendarTimegrid::topbarController() . '?idevent=' . $idevent;
        }

        $insistencia = self::insistenciaDe((string)$row->tipo);

        return [
            'id' => (int)$row->id,
            'tipo' => (string)$row->tipo,
            'titulo' => (string)$row->titulo,
            'cuerpo' => (string)($row->cuerpo ?? ''),
            'url' => $url,
            'idevent' => $idevent,
            'leida' => !empty($row->leida),
            'missed' => $row->isMissed(),
            'snoozed_until' => $row->snoozed_until,
            'created_at' => $row->created_at,
            'insistente' => $insistencia['insistente'],
            'intervalo_minutos' => $insistencia['intervalo_minutos'],
            'duracion_segundos' => $insistencia['duracion_segundos'],
        ];
    }

    /**
     * @return array{insistente: bool, intervalo_minutos: int, duracion_segundos: int}
     */
    private static function insistenciaDe(string $tipo): array
    {
        $definicion = NotificationTypeRegistry::get($tipo);
        if ($definicion === null || false === $definicion->insistente()) {
            return [
                'insistente' => false,
                'intervalo_minutos' => 0,
                'duracion_segundos' => 0,
            ];
        }

        return [
            'insistente' => true,
            'intervalo_minutos' => $definicion->intervaloMinutos(),
            'duracion_segundos' => $definicion->duracionSegundos(),
        ];
    }

    private static function loadOwned(int $id, string $nick): ?AuralisNotification
    {
        if ($id <= 0 || trim($nick) === '') {
            return null;
        }

        $row = new AuralisNotification();
        if (false === $row->load($id)) {
            return null;
        }

        if ((string)$row->nick !== trim($nick)) {
            return null;
        }

        return $row;
    }

    public static function filaVisibleParaNick(AuralisNotification $row, string $nick): bool
    {
        if ((string)$row->nick !== trim($nick)) {
            return false;
        }

        $user = self::usuarioActivo($nick);
        if ($user === null) {
            return false;
        }

        return NotificationDeliveryPolicy::visibleEnCampana(
            NotificationTypeRegistry::get((string)$row->tipo),
            static function (string $permiso) use ($user): bool {
                return $user->can($permiso);
            }
        );
    }

    /**
     * @return list<string>
     */
    private static function clavesVisiblesDe(string $nick): array
    {
        $user = self::usuarioActivo($nick);
        if ($user === null) {
            return [];
        }

        return NotificationDeliveryPolicy::clavesCampana(
            static function (string $permiso) use ($user): bool {
                return $user->can($permiso);
            }
        );
    }

    /**
     * @return list<string>
     */
    private static function clavesConVentana(string $nick): array
    {
        $user = self::usuarioActivo($nick);
        if ($user === null) {
            return [];
        }

        return NotificationDeliveryPolicy::clavesVentana(
            static function (string $permiso) use ($user): bool {
                return $user->can($permiso);
            }
        );
    }

    /**
     * @param list<string> $claves
     */
    private static function sqlLista(DataBase $db, array $claves): string
    {
        $partes = [];
        foreach ($claves as $clave) {
            $partes[] = $db->var2str((string)$clave);
        }

        return implode(', ', $partes);
    }

    /**
     * @param list<string>|null $destinatarios
     * @return list<string>
     */
    private static function nicksUnicos(?array $destinatarios): array
    {
        if ($destinatarios === null) {
            return [];
        }

        $nicks = [];
        foreach ($destinatarios as $nick) {
            $nick = Tools::noHtml(trim((string)$nick));
            if ($nick === '' || isset($nicks[$nick])) {
                continue;
            }
            $nicks[$nick] = $nick;
        }

        return array_values($nicks);
    }

    public static function destinatarioPuedeRecibir(string $nick, string $permiso): bool
    {
        $user = self::usuarioActivo($nick);
        if ($user === null) {
            return false;
        }

        if ($permiso === '') {
            return true;
        }

        return $user->can($permiso);
    }

    private static function usuarioActivo(string $nick): ?User
    {
        $nick = trim($nick);
        if ($nick === '') {
            return null;
        }

        $user = new User();
        if (false === $user->load($nick) || empty($user->enabled)) {
            return null;
        }

        return $user;
    }
}

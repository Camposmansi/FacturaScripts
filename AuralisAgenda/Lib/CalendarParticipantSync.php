<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Sincronización segura de asistentes N:M del calendario (allowlist, techo, espejo cabecera).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\DbUpdater;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Dinamic\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotifDelivery;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;
use ReflectionProperty;

final class CalendarParticipantSync
{
    public const MAX_PARTICIPANTS = 50;

    /**
     * Garantiza tablas pivote de asistentes/entregas.
     * Autocura el estado "checked en db-updater.json pero tabla ausente" (CREATE fallido → Core marca igual).
     */
    public static function ensureSchema(): bool
    {
        try {
            $db = new DataBase();
            if (false === $db->connected()) {
                $db->connect();
            }

            $tables = [
                AuralisCalendarEventParticipant::tableName(),
                AuralisCalendarEventNotifDelivery::tableName(),
            ];

            foreach ($tables as $table) {
                if (false === $db->tableExists($table) && DbUpdater::isTableChecked($table)) {
                    self::uncheckTable($table);
                    Cache::delete('model-fields-' . self::modelClassFromTable($table));
                }
            }

            new AuralisCalendarEventParticipant();
            new AuralisCalendarEventNotifDelivery();

            if (false === $db->tableExists(AuralisCalendarEventParticipant::tableName())) {
                Tools::log('Auralis')->warning(
                    'auralis-calendar-participants-schema: tabla ausente tras createOrUpdate. '
                    . DbUpdater::getLastError()
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-calendar-participants-schema: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Quita una tabla de MyFiles/db-updater.json y de la caché estática de DbUpdater.
     */
    private static function uncheckTable(string $tableName): void
    {
        try {
            $file = Tools::folder('MyFiles', 'db-updater.json');
            $checked = [];
            if (is_file($file)) {
                $decoded = json_decode((string)file_get_contents($file), true);
                $checked = is_array($decoded) ? $decoded : [];
            }

            $checked = array_values(array_filter(
                $checked,
                static fn($name): bool => (string)$name !== $tableName
            ));
            file_put_contents($file, json_encode($checked, JSON_PRETTY_PRINT));

            if (class_exists(DbUpdater::class) && property_exists(DbUpdater::class, 'checked_tables')) {
                $prop = new ReflectionProperty(DbUpdater::class, 'checked_tables');
                $prop->setAccessible(true);
                $prop->setValue(null, $checked);
            }

            Tools::log('Auralis')->notice('auralis-calendar-schema-uncheck:' . $tableName);
        } catch (\Throwable $e) {
            Tools::log('Auralis')->warning('auralis-uncheck-table-failed: ' . $e->getMessage());
        }
    }

    private static function modelClassFromTable(string $tableName): string
    {
        return match ($tableName) {
            AuralisCalendarEventParticipant::tableName() => 'AuralisCalendarEventParticipant',
            AuralisCalendarEventNotifDelivery::tableName() => 'AuralisCalendarEventNotifDelivery',
            default => $tableName,
        };
    }

    /**
     * @return list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}>
     */
    public static function parseParticipantsJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::normalizarEntrada($row);
            if ($item === null) {
                continue;
            }
            $key = $item['tipo'] . '|' . $item['codigo'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
            if (count($result) >= self::MAX_PARTICIPANTS) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}|null
     */
    public static function normalizarEntrada(array $row): ?array
    {
        $tipo = trim((string)($row['tipo'] ?? ''));
        $codigo = Tools::noHtml(trim((string)($row['codigo'] ?? '')));
        if ($codigo === '' || !in_array($tipo, AuralisCalendarEventParticipant::tiposPermitidos(), true)) {
            return null;
        }
        if (mb_strlen($codigo) > 50) {
            $codigo = mb_substr($codigo, 0, 50);
        }

        try {
            $resolved = self::resolverEntidad($tipo, $codigo);
        } catch (\Throwable $e) {
            return null;
        }
        if ($resolved === null) {
            return null;
        }

        $estado = trim((string)($row['estado_asistencia'] ?? AuralisCalendarEventParticipant::ESTADO_PENDIENTE));
        if (!in_array($estado, [
            AuralisCalendarEventParticipant::ESTADO_PENDIENTE,
            AuralisCalendarEventParticipant::ESTADO_ACEPTADO,
            AuralisCalendarEventParticipant::ESTADO_RECHAZADO,
        ], true)) {
            $estado = AuralisCalendarEventParticipant::ESTADO_PENDIENTE;
        }

        $email = $resolved['email'];
        $emailReq = trim((string)($row['email'] ?? ''));
        if ($emailReq !== '' && filter_var($emailReq, FILTER_VALIDATE_EMAIL)) {
            $email = $emailReq;
        }

        $nombre = $resolved['nombre'];
        $nombreReq = Tools::noHtml(trim((string)($row['nombre_snapshot'] ?? '')));
        if ($nombreReq !== '') {
            $nombre = mb_substr($nombreReq, 0, 200);
        }

        return [
            'tipo' => $tipo,
            'codigo' => $codigo,
            'email' => $email,
            'estado_asistencia' => $estado,
            'nombre_snapshot' => $nombre,
        ];
    }

    /**
     * @param list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}> $participants
     * @return array{saved: int, removed: int}
     */
    public static function syncForEvent(AuralisCalendarEvent $event, array $participants): array
    {
        $idevent = (int)($event->id ?? 0);
        if ($idevent <= 0) {
            return ['saved' => 0, 'removed' => 0];
        }

        if (false === self::ensureSchema()) {
            return ['saved' => 0, 'removed' => 0];
        }

        $desired = [];
        foreach ($participants as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::normalizarEntrada($row);
            if ($item === null) {
                continue;
            }
            $desired[$item['tipo'] . '|' . $item['codigo']] = $item;
            if (count($desired) >= self::MAX_PARTICIPANTS) {
                break;
            }
        }

        // Espejo obligatorio del organizador y cabecera 1:1.
        foreach (self::headerMirrorRows($event) as $mirror) {
            $key = $mirror['tipo'] . '|' . $mirror['codigo'];
            if (!isset($desired[$key])) {
                $desired[$key] = $mirror;
            }
        }

        $existing = AuralisCalendarEventParticipant::allByEvent($idevent);
        $existingByKey = [];
        foreach ($existing as $row) {
            $existingByKey[$row->tipo . '|' . $row->codigo] = $row;
        }

        $saved = 0;
        foreach ($desired as $key => $item) {
            $model = $existingByKey[$key] ?? new AuralisCalendarEventParticipant();
            $model->idevent = $idevent;
            $model->tipo = $item['tipo'];
            $model->codigo = $item['codigo'];
            $model->email = $item['email'];
            // El modal envía siempre «pendiente»: en filas existentes se conserva la respuesta guardada.
            $estadoGuardado = isset($existingByKey[$key]) ? (string)($model->estado_asistencia ?? '') : '';
            $model->estado_asistencia = ($item['estado_asistencia'] === AuralisCalendarEventParticipant::ESTADO_PENDIENTE && $estadoGuardado !== '')
                ? $estadoGuardado
                : $item['estado_asistencia'];
            $model->nombre_snapshot = $item['nombre_snapshot'];
            if ($model->save()) {
                ++$saved;
            }
            unset($existingByKey[$key]);
        }

        $removed = 0;
        foreach ($existingByKey as $orphan) {
            if ($orphan->delete()) {
                ++$removed;
            }
        }

        return ['saved' => $saved, 'removed' => $removed];
    }

    /**
     * Inserta espejo de cabecera sin borrar invitados ya existentes.
     */
    public static function mirrorHeaderIntoParticipants(AuralisCalendarEvent $event): int
    {
        $idevent = (int)($event->id ?? 0);
        if ($idevent <= 0) {
            return 0;
        }

        if (false === self::ensureSchema()) {
            return 0;
        }

        $saved = 0;
        foreach (self::headerMirrorRows($event) as $item) {
            $exists = new AuralisCalendarEventParticipant();
            if ($exists->loadWhere([
                Where::eq('idevent', $idevent),
                Where::eq('tipo', $item['tipo']),
                Where::eq('codigo', $item['codigo']),
            ])) {
                continue;
            }

            $row = new AuralisCalendarEventParticipant();
            $row->idevent = $idevent;
            $row->tipo = $item['tipo'];
            $row->codigo = $item['codigo'];
            $row->email = $item['email'];
            $row->estado_asistencia = $item['estado_asistencia'];
            $row->nombre_snapshot = $item['nombre_snapshot'];
            if ($row->save()) {
                ++$saved;
            }
        }

        return $saved;
    }

    /**
     * Copia participantes del padre a cada hijo de serie (idempotente por UNIQUE).
     */
    public static function copyToSeriesChildren(AuralisCalendarEvent $parent): void
    {
        $parentId = (int)($parent->id ?? 0);
        if ($parentId <= 0) {
            return;
        }

        $rows = AuralisCalendarEventParticipant::toArrayList($parentId);
        if ($rows === []) {
            return;
        }

        foreach (CalendarRecurrence::seriesChildren($parentId) as $child) {
            if ((int)$child->id === $parentId) {
                continue;
            }
            self::syncForEvent($child, $rows);
        }
    }

    /**
     * Destinatarios internos (nicks) para in-app/push: organizador + participantes user.
     *
     * @return list<string>
     */
    public static function internalNicksForEvent(AuralisCalendarEvent $event): array
    {
        $map = self::internalNicksForEvents([$event]);
        $id = (int)($event->id ?? 0);

        return $map[$id] ?? [];
    }

    /**
     * Mapa idevent → nicks internos en un solo WHERE IN (anti N+1, [MOD-050]).
     *
     * @param list<AuralisCalendarEvent> $events
     * @return array<int, list<string>>
     */
    public static function internalNicksForEvents(array $events): array
    {
        $base = [];
        $ids = [];
        foreach ($events as $event) {
            if (false === ($event instanceof AuralisCalendarEvent)) {
                continue;
            }
            $id = (int)($event->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $ids[] = $id;
            $nicks = [];
            $org = trim((string)($event->asignado ?: $event->nick));
            if ($org !== '') {
                $nicks[$org] = true;
            }
            $creator = trim((string)($event->nick ?? ''));
            if ($creator !== '') {
                $nicks[$creator] = true;
            }
            $base[$id] = $nicks;
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $limit = max(1, min(5000, count($ids) * self::MAX_PARTICIPANTS));
        foreach (AuralisCalendarEventParticipant::all(
            [
                Where::in('idevent', $ids),
                Where::eq('tipo', AuralisCalendarEventParticipant::TIPO_USER),
            ],
            ['idevent' => 'ASC', 'id' => 'ASC'],
            0,
            $limit
        ) as $p) {
            $eid = (int)($p->idevent ?? 0);
            $nick = trim((string)$p->codigo);
            if ($eid <= 0 || $nick === '' || false === isset($base[$eid])) {
                continue;
            }
            // Quien rechazó no recibe avisos (NULL legacy sí cuenta). Organizador/creador ya están en $base.
            if ((string)($p->estado_asistencia ?? '') === AuralisCalendarEventParticipant::ESTADO_RECHAZADO) {
                continue;
            }
            $base[$eid][$nick] = true;
        }

        $out = [];
        foreach ($base as $id => $nicks) {
            $out[$id] = array_keys($nicks);
        }

        return $out;
    }

    /**
     * Destinatarios con email para fan-out de correo.
     *
     * @return list<array{tipo: string, codigo: string, email: string, name: string}>
     */
    public static function emailRecipientsForEvent(AuralisCalendarEvent $event): array
    {
        $out = [];
        $seenEmail = [];

        $add = static function (string $tipo, string $codigo, string $email, string $name) use (&$out, &$seenEmail): void {
            $email = strtolower(trim($email));
            if ($email === '' || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            if (isset($seenEmail[$email])) {
                return;
            }
            $seenEmail[$email] = true;
            $out[] = [
                'tipo' => $tipo,
                'codigo' => $codigo,
                'email' => $email,
                'name' => $name !== '' ? $name : $codigo,
            ];
        };

        // Quien rechazó no recibe email; el organizador/creador siguen avisados aunque su fila espejo esté en rechazado.
        $headerNicks = array_filter([
            trim((string)($event->asignado ?: $event->nick)),
            trim((string)($event->nick ?? '')),
        ], static fn(string $nick): bool => $nick !== '');

        foreach (AuralisCalendarEventParticipant::allByEvent((int)($event->id ?? 0)) as $p) {
            $esCabecera = (string)$p->tipo === AuralisCalendarEventParticipant::TIPO_USER
                && in_array((string)$p->codigo, $headerNicks, true);
            if (!$esCabecera && (string)($p->estado_asistencia ?? '') === AuralisCalendarEventParticipant::ESTADO_RECHAZADO) {
                continue;
            }
            $email = trim((string)($p->email ?? ''));
            $name = trim((string)($p->nombre_snapshot ?? ''));
            if ($email === '') {
                $resolved = self::resolverEntidad((string)$p->tipo, (string)$p->codigo);
                if ($resolved === null) {
                    continue;
                }
                $email = (string)($resolved['email'] ?? '');
                if ($name === '') {
                    $name = (string)($resolved['nombre'] ?? '');
                }
            }
            $add((string)$p->tipo, (string)$p->codigo, $email, $name);
        }

        // Fallback retrocompat: si no hay pivote, usar organizador.
        if ($out === []) {
            $nick = trim((string)($event->asignado ?: $event->nick));
            $resolved = self::resolverEntidad(AuralisCalendarEventParticipant::TIPO_USER, $nick);
            if ($resolved !== null && $resolved['email'] !== null) {
                $add(
                    AuralisCalendarEventParticipant::TIPO_USER,
                    $nick,
                    (string)$resolved['email'],
                    (string)$resolved['nombre']
                );
            }
        }

        return $out;
    }

    /**
     * @return list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}>
     */
    public static function headerMirrorRows(AuralisCalendarEvent $event): array
    {
        $rows = [];
        $org = trim((string)($event->asignado ?: $event->nick));
        if ($org !== '') {
            $resolved = self::resolverEntidad(AuralisCalendarEventParticipant::TIPO_USER, $org);
            if ($resolved !== null) {
                $rows[] = [
                    'tipo' => AuralisCalendarEventParticipant::TIPO_USER,
                    'codigo' => $org,
                    'email' => $resolved['email'],
                    'estado_asistencia' => AuralisCalendarEventParticipant::ESTADO_ACEPTADO,
                    'nombre_snapshot' => $resolved['nombre'],
                ];
            }
        }

        $codcliente = trim((string)($event->codcliente ?? ''));
        if ($codcliente !== '') {
            $resolved = self::resolverEntidad(AuralisCalendarEventParticipant::TIPO_CLIENTE, $codcliente);
            if ($resolved !== null) {
                $rows[] = [
                    'tipo' => AuralisCalendarEventParticipant::TIPO_CLIENTE,
                    'codigo' => $codcliente,
                    'email' => $resolved['email'],
                    'estado_asistencia' => AuralisCalendarEventParticipant::ESTADO_PENDIENTE,
                    'nombre_snapshot' => $resolved['nombre'],
                ];
            }
        }

        $idcontacto = (int)($event->idcontacto ?? 0);
        if ($idcontacto > 0) {
            $codigo = (string)$idcontacto;
            $resolved = self::resolverEntidad(AuralisCalendarEventParticipant::TIPO_CONTACTO, $codigo);
            if ($resolved !== null) {
                $rows[] = [
                    'tipo' => AuralisCalendarEventParticipant::TIPO_CONTACTO,
                    'codigo' => $codigo,
                    'email' => $resolved['email'],
                    'estado_asistencia' => AuralisCalendarEventParticipant::ESTADO_PENDIENTE,
                    'nombre_snapshot' => $resolved['nombre'],
                ];
            }
        }

        $codagente = trim((string)($event->codagente ?? ''));
        if ($codagente !== '') {
            $resolved = self::resolverEntidad(AuralisCalendarEventParticipant::TIPO_AGENTE, $codagente);
            if ($resolved !== null) {
                $rows[] = [
                    'tipo' => AuralisCalendarEventParticipant::TIPO_AGENTE,
                    'codigo' => $codagente,
                    'email' => $resolved['email'],
                    'estado_asistencia' => AuralisCalendarEventParticipant::ESTADO_PENDIENTE,
                    'nombre_snapshot' => $resolved['nombre'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array{email: ?string, nombre: string}|null
     */
    public static function resolverEntidad(string $tipo, string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '') {
            return null;
        }

        switch ($tipo) {
            case AuralisCalendarEventParticipant::TIPO_USER:
                $user = new User();
                if (false === $user->load($codigo)) {
                    return null;
                }
                $email = trim((string)($user->email ?? ''));
                return [
                    'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                    'nombre' => (string)$user->nick,
                ];

            case AuralisCalendarEventParticipant::TIPO_CLIENTE:
                $cliente = new Cliente();
                if (false === $cliente->load($codigo)) {
                    return null;
                }
                $email = trim((string)($cliente->email ?? ''));
                return [
                    'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                    'nombre' => (string)($cliente->nombre ?: $cliente->razonsocial ?: $codigo),
                ];

            case AuralisCalendarEventParticipant::TIPO_CONTACTO:
                $contacto = new Contacto();
                if (false === $contacto->load((int)$codigo)) {
                    return null;
                }
                $email = trim((string)($contacto->email ?? ''));
                $nombre = trim((string)($contacto->nombre ?? '') . ' ' . (string)($contacto->apellidos ?? ''));
                return [
                    'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                    'nombre' => $nombre !== '' ? $nombre : $codigo,
                ];

            case AuralisCalendarEventParticipant::TIPO_AGENTE:
                $agente = new Agente();
                if (false === $agente->load($codigo)) {
                    return null;
                }
                $email = trim((string)($agente->email ?? ''));
                return [
                    'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                    'nombre' => (string)($agente->nombre ?: $codigo),
                ];

            default:
                return null;
        }
    }
}

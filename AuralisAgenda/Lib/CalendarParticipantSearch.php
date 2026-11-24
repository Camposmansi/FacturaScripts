<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Búsqueda acotada de candidatos a asistentes del calendario (usuarios/clientes/contactos) y de grupos registrables.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;
use Throwable;

final class CalendarParticipantSearch
{
    public const LIMIT = 20;

    /** Tipo de los ítems grupo: solo UI/AJAX, nunca se persiste (no está en tiposPermitidos()). */
    public const TIPO_GROUP = 'group';

    /**
     * Personas y, en el hueco que quede del LIMIT, grupos de los proveedores registrados.
     * Con $tipoFiltro = 'group' solo devuelve grupos.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(?object $user, string $query, ?string $tipoFiltro = null): array
    {
        $tipoFiltro = $tipoFiltro !== null ? trim($tipoFiltro) : null;
        if ($tipoFiltro === self::TIPO_GROUP) {
            return self::searchGroups($user, (string)Tools::noHtml(trim($query)), self::LIMIT);
        }

        $results = self::searchPersonas($user, $query, $tipoFiltro);
        if (($tipoFiltro === null || $tipoFiltro === '') && count($results) < self::LIMIT) {
            $results = array_merge(
                $results,
                self::searchGroups($user, (string)Tools::noHtml(trim($query)), self::LIMIT - count($results))
            );
        }

        return array_slice($results, 0, self::LIMIT);
    }

    /**
     * Solo personas (usuarios → clientes → contactos), sin grupos.
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    public static function searchPersonas(?object $user, string $query, ?string $tipoFiltro = null): array
    {
        $query = Tools::noHtml(trim($query));
        $tipoFiltro = $tipoFiltro !== null ? trim($tipoFiltro) : null;
        if ($tipoFiltro !== null && $tipoFiltro !== '' && !in_array($tipoFiltro, AuralisCalendarEventParticipant::tiposPermitidos(), true)) {
            return [];
        }

        $results = [];
        $limit = self::LIMIT;

        // Sin texto: devolver usuarios internos (para abrir el dropdown con opciones al instante).
        if ($query === '') {
            if ($tipoFiltro === null || $tipoFiltro === '' || $tipoFiltro === AuralisCalendarEventParticipant::TIPO_USER) {
                return self::searchUsers('', $limit);
            }
            return [];
        }

        if ($tipoFiltro === null || $tipoFiltro === '' || $tipoFiltro === AuralisCalendarEventParticipant::TIPO_USER) {
            $results = array_merge($results, self::searchUsers($query, $limit));
        }
        if (count($results) < $limit && ($tipoFiltro === null || $tipoFiltro === '' || $tipoFiltro === AuralisCalendarEventParticipant::TIPO_CLIENTE)) {
            $results = array_merge($results, self::searchClientes($user, $query, $limit - count($results)));
        }
        if (count($results) < $limit && ($tipoFiltro === null || $tipoFiltro === '' || $tipoFiltro === AuralisCalendarEventParticipant::TIPO_CONTACTO)) {
            $results = array_merge($results, self::searchContactos($user, $query, $limit - count($results)));
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Grupos de todos los proveedores registrados, en orden de clave, hasta $limit.
     * Un proveedor que falla se omite (se registra el aviso) para no tumbar la búsqueda.
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string, is_group: bool, provider: string, member_count: int}>
     */
    public static function searchGroups(?object $user, string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = trim($query);
        $out = [];
        foreach (CalendarParticipantGroupRegistry::all() as $clave => $provider) {
            $hueco = $limit - count($out);
            if ($hueco <= 0) {
                break;
            }

            try {
                $grupos = $provider->buscar($user, $query, $hueco);
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('calendar-participant-group-provider-error', ['%provider%' => $clave]);
                continue;
            }

            $group = Tools::trans($provider->etiquetaGrupoI18n());
            foreach ($grupos as $row) {
                // El código debe llevar el prefijo de su proveedor para poder expandirlo luego.
                $codigo = is_array($row) ? (string)($row['codigo'] ?? '') : '';
                if (!str_starts_with($codigo, $clave . ':')) {
                    continue;
                }
                $out[] = [
                    'tipo' => self::TIPO_GROUP,
                    'codigo' => $codigo,
                    'label' => (string)($row['label'] ?? $codigo),
                    'email' => null,
                    'group' => (string)($row['group'] ?? $group),
                    'is_group' => true,
                    'provider' => $clave,
                    'member_count' => (int)($row['member_count'] ?? 0),
                ];
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    private static function searchUsers(string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $where = [];
        if ($query !== '') {
            $where[] = Where::sub([
                Where::like('nick', $query),
                Where::orLike('email', $query),
            ]);
        }

        $users = User::all($where, ['nick' => 'ASC'], 0, $limit);

        $out = [];
        $group = Tools::trans('calendar-participants-group-users');
        foreach ($users as $u) {
            $email = trim((string)($u->email ?? ''));
            $label = (string)$u->nick;
            if ($email !== '') {
                $label .= ' (' . $email . ')';
            }
            $out[] = [
                'tipo' => AuralisCalendarEventParticipant::TIPO_USER,
                'codigo' => (string)$u->nick,
                'label' => $label,
                'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                'group' => $group,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    private static function searchClientes(?object $user, string $query, int $limit): array
    {
        if ($limit <= 0 || $query === '') {
            return [];
        }

        $where = [
            Where::sub([
                Where::like('codcliente', $query),
                Where::orLike('nombre', $query),
                Where::orLike('razonsocial', $query),
                Where::orLike('email', $query),
                Where::orLike('cifnif', $query),
                Where::orLike('telefono1', $query),
                Where::orLike('telefono2', $query),
            ]),
        ];

        $clientes = Cliente::all($where, ['nombre' => 'ASC'], 0, $limit);
        $out = [];
        $group = Tools::trans('calendar-participants-group-clients');
        foreach ($clientes as $c) {
            if ((int)($c->idempresa ?? 0) > 0 && false === AuralisSecurity::canAccessEmpresa($user, $c->idempresa)) {
                continue;
            }
            $email = trim((string)($c->email ?? ''));
            $out[] = [
                'tipo' => AuralisCalendarEventParticipant::TIPO_CLIENTE,
                'codigo' => (string)$c->codcliente,
                'label' => (string)($c->nombre ?: $c->razonsocial ?: $c->codcliente),
                'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                'group' => $group,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    private static function searchContactos(?object $user, string $query, int $limit): array
    {
        if ($limit <= 0 || $query === '') {
            return [];
        }

        $contactos = Contacto::all(
            [
                Where::sub([
                    Where::like('nombre', $query),
                    Where::orLike('apellidos', $query),
                    Where::orLike('email', $query),
                    Where::orLike('codcliente', $query),
                    Where::orLike('cifnif', $query),
                    Where::orLike('telefono1', $query),
                    Where::orLike('telefono2', $query),
                ]),
            ],
            ['nombre' => 'ASC'],
            0,
            $limit
        );

        $out = [];
        $group = Tools::trans('calendar-participants-group-contacts');
        foreach ($contactos as $c) {
            $email = trim((string)($c->email ?? ''));
            $nombre = trim((string)($c->nombre ?? '') . ' ' . (string)($c->apellidos ?? ''));
            $cliente = trim((string)($c->codcliente ?? ''));
            $label = $nombre !== '' ? $nombre : (string)$c->idcontacto;
            if ($cliente !== '') {
                $label .= ' — ' . $cliente;
            }
            $out[] = [
                'tipo' => AuralisCalendarEventParticipant::TIPO_CONTACTO,
                'codigo' => (string)(int)$c->idcontacto,
                'label' => $label,
                'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                'group' => $group,
            ];
        }

        return $out;
    }
}

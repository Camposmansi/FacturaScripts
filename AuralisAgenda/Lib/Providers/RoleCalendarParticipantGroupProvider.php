<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Proveedor de grupos de asistentes por roles del Core: busca roles con usuarios habilitados y los expande a usuarios.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\Providers;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\RoleUser;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarParticipantGroupProviderInterface;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventParticipant;

final class RoleCalendarParticipantGroupProvider implements CalendarParticipantGroupProviderInterface
{
    public const CLAVE = 'role';

    public function clave(): string
    {
        return self::CLAVE;
    }

    public function etiquetaGrupoI18n(): string
    {
        return 'calendar-participants-group-roles';
    }

    /**
     * Una sola consulta: roles JOIN roles_users JOIN users habilitados, agrupada por rol.
     * Los roles sin miembros habilitados no salen (el INNER JOIN los descarta). Sin N+1.
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string, is_group: bool, provider: string, member_count: int}>
     */
    public function buscar(?object $user, string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $query = trim($query);
        $sql = DbQuery::table('roles')
            ->selectRaw('roles.codrole, roles.descripcion, COUNT(DISTINCT users.nick) AS member_count')
            ->join('roles_users', 'roles_users.codrole', 'roles.codrole')
            ->join('users', 'users.nick', 'roles_users.nick')
            ->whereEq('users.enabled', true);
        if ($query !== '') {
            $sql->where([
                Where::sub([
                    Where::like('roles.codrole', $query),
                    Where::orLike('roles.descripcion', $query),
                ]),
            ]);
        }

        $rows = $sql->groupBy('roles.codrole, roles.descripcion')
            ->orderBy('roles.descripcion')
            ->orderBy('roles.codrole')
            ->limit($limit)
            ->get();

        $out = [];
        $group = Tools::trans($this->etiquetaGrupoI18n());
        foreach ($rows as $row) {
            $codrole = (string)($row['codrole'] ?? '');
            $miembros = (int)($row['member_count'] ?? 0);
            if ($codrole === '' || $miembros <= 0) {
                continue;
            }
            $descripcion = trim((string)($row['descripcion'] ?? ''));
            $out[] = [
                'tipo' => 'group',
                'codigo' => self::CLAVE . ':' . $codrole,
                'label' => ($descripcion !== '' ? $descripcion : $codrole) . ' (' . $miembros . ')',
                'email' => null,
                'group' => $group,
                'is_group' => true,
                'provider' => self::CLAVE,
                'member_count' => $miembros,
            ];
        }

        return $out;
    }

    /**
     * Dos consultas como máximo: nicks del rol y usuarios habilitados de esos nicks. Deduplicado por nick.
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    public function expandir(?object $user, string $codigoGrupo): array
    {
        $prefijo = self::CLAVE . ':';
        if (!str_starts_with($codigoGrupo, $prefijo)) {
            return [];
        }
        $codrole = trim(substr($codigoGrupo, strlen($prefijo)));
        if ($codrole === '') {
            return [];
        }

        $nicks = [];
        foreach (RoleUser::all([Where::eq('codrole', $codrole)]) as $roleUser) {
            $nick = (string)($roleUser->nick ?? '');
            if ($nick !== '') {
                $nicks[$nick] = true;
            }
        }
        if (empty($nicks)) {
            return [];
        }

        $users = User::all(
            [Where::in('nick', array_keys($nicks)), Where::eq('enabled', true)],
            ['nick' => 'ASC']
        );

        $out = [];
        $vistos = [];
        $group = Tools::trans('calendar-participants-group-users');
        foreach ($users as $u) {
            $nick = (string)$u->nick;
            if ($nick === '' || isset($vistos[$nick])) {
                continue;
            }
            $vistos[$nick] = true;
            $email = trim((string)($u->email ?? ''));
            $label = $nick;
            if ($email !== '') {
                $label .= ' (' . $email . ')';
            }
            $out[] = [
                'tipo' => AuralisCalendarEventParticipant::TIPO_USER,
                'codigo' => $nick,
                'label' => $label,
                'email' => ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null,
                'group' => $group,
            ];
        }

        return $out;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @description Contrato enganchable de los proveedores de grupos de asistentes del calendario (roles, equipos…).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

interface CalendarParticipantGroupProviderInterface
{
    /**
     * Clave única del proveedor (minúsculas, dígitos, guion o guion bajo), p. ej. 'role'.
     * Es el prefijo del código de grupo: 'role:ADMIN'.
     */
    public function clave(): string;

    /**
     * Clave de traducción de la etiqueta de grupo del desplegable, p. ej. 'calendar-participants-group-roles'.
     */
    public function etiquetaGrupoI18n(): string;

    /**
     * Grupos candidatos para la búsqueda de asistentes (solo grupos con al menos un miembro utilizable).
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string, is_group: bool, provider: string, member_count: int}>
     */
    public function buscar(?object $user, string $query, int $limit): array;

    /**
     * Miembros del grupo como candidatos a asistente tipo user (mismo shape que CalendarParticipantSearch).
     * Grupo inexistente o sin miembros: [].
     *
     * @param string $codigoGrupo código completo con prefijo, p. ej. 'role:ADMIN'
     *
     * @return list<array{tipo: string, codigo: string, label: string, email: ?string, group: string}>
     */
    public function expandir(?object $user, string $codigoGrupo): array;
}

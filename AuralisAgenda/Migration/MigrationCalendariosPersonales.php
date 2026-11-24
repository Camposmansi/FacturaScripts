<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Asigna cada evento general sin calendario al personal principal de su organizador; los que no tienen organizador van a un común «Empresa» de su empresa.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Core\Tools;
use RuntimeException;

class MigrationCalendariosPersonales extends MigrationClass
{
    public const MIGRATION_NAME = 'calendarios_personales_v1_75';

    private const TABLA_EVENTOS = 'auralis_calendar_events';
    private const TABLA_CALENDARIOS = 'auralis_calendarios';
    private const TABLA_ACCESOS = 'auralis_calendario_accesos';

    /** Organizador del evento: asignado o, si no hay, el creador. */
    private const SQL_ORGANIZADOR = "COALESCE(NULLIF(asignado, ''), NULLIF(nick, ''))";

    public function run(): void
    {
        // SQL directo: instanciar AuralisCalendarEvent aquí dispara DbUpdater (ver README, FK de tipos).
        foreach ([self::TABLA_EVENTOS, self::TABLA_CALENDARIOS, self::TABLA_ACCESOS] as $tabla) {
            if (false === $this->db()->tableExists($tabla)) {
                throw new RuntimeException('auralis-calendarios: falta la tabla ' . $tabla);
            }
        }
        if (false === isset($this->db()->getColumns(self::TABLA_EVENTOS)['idcalendario'])) {
            throw new RuntimeException('auralis-calendarios: falta la columna idcalendario');
        }

        $this->asignarAPersonales();
        $this->asignarAComunesEmpresa();
    }

    /**
     * Eventos general con organizador que existe en users → su personal principal (lo crea si falta).
     */
    private function asignarAPersonales(): void
    {
        $filas = $this->db()->select(
            'SELECT DISTINCT ' . self::SQL_ORGANIZADOR . ' AS organizador FROM ' . self::TABLA_EVENTOS
            . " WHERE origen_tipo = 'general' AND idcalendario IS NULL"
        );

        foreach ($filas as $fila) {
            $nick = trim((string)($fila['organizador'] ?? ''));
            if ($nick === '') {
                continue;
            }

            $usuario = $this->db()->select('SELECT nick, idempresa FROM users WHERE nick = ' . $this->db()->var2str($nick));
            if ($usuario === []) {
                // Usuario borrado: sin personal posible (FK); lo recoge el común de su empresa.
                continue;
            }

            $idcalendario = $this->principalDe($nick, (int)($usuario[0]['idempresa'] ?? 0));
            $this->exec(
                'UPDATE ' . self::TABLA_EVENTOS . ' SET idcalendario = ' . $idcalendario
                . " WHERE origen_tipo = 'general' AND idcalendario IS NULL"
                . ' AND ' . self::SQL_ORGANIZADOR . ' = ' . $this->db()->var2str($nick)
            );
        }
    }

    /**
     * Lo que queda (sin organizador o con un usuario que ya no existe) → común «Empresa» de su empresa,
     * creado solo en este caso, con acceso de toda la empresa en «ver».
     */
    private function asignarAComunesEmpresa(): void
    {
        $filas = $this->db()->select(
            'SELECT DISTINCT idempresa FROM ' . self::TABLA_EVENTOS
            . " WHERE origen_tipo = 'general' AND idcalendario IS NULL"
        );

        foreach ($filas as $fila) {
            $idempresaEvento = (int)($fila['idempresa'] ?? 0);
            $idcalendario = $this->comunEmpresa($this->empresaValida($idempresaEvento));

            $filtroEmpresa = $idempresaEvento > 0
                ? 'idempresa = ' . $idempresaEvento
                : '(idempresa IS NULL OR idempresa <= 0)';
            $this->exec(
                'UPDATE ' . self::TABLA_EVENTOS . ' SET idcalendario = ' . $idcalendario
                . " WHERE origen_tipo = 'general' AND idcalendario IS NULL AND " . $filtroEmpresa
            );
        }
    }

    private function principalDe(string $nick, int $idempresaUsuario): int
    {
        $buscar = 'SELECT idcalendario FROM ' . self::TABLA_CALENDARIOS
            . " WHERE tipo = 'personal' AND principal = " . $this->db()->var2str(true)
            . ' AND propietario = ' . $this->db()->var2str($nick);

        $existente = $this->db()->select($buscar);
        if ($existente !== []) {
            return (int)$existente[0]['idcalendario'];
        }

        $idempresa = $this->empresaValida($idempresaUsuario);
        $this->exec(
            'INSERT INTO ' . self::TABLA_CALENDARIOS
            . ' (nombre, color, tipo, propietario, principal, idempresa, activo, creado) VALUES ('
            . $this->db()->var2str(mb_substr($nick, 0, 100)) . ", '#4285f4', 'personal', "
            . $this->db()->var2str($nick) . ', ' . $this->db()->var2str(true) . ', '
            . ($idempresa > 0 ? (string)$idempresa : 'NULL') . ', ' . $this->db()->var2str(true) . ', '
            . $this->db()->var2str(date('Y-m-d H:i:s')) . ')'
        );

        return $this->idDe($buscar);
    }

    private function comunEmpresa(int $idempresa): int
    {
        $codigo = 'empresa_' . max(0, $idempresa);
        $buscar = 'SELECT idcalendario FROM ' . self::TABLA_CALENDARIOS . ' WHERE codigo = ' . $this->db()->var2str($codigo);

        $existente = $this->db()->select($buscar);
        if ($existente !== []) {
            return (int)$existente[0]['idcalendario'];
        }

        $this->exec(
            'INSERT INTO ' . self::TABLA_CALENDARIOS
            . ' (codigo, nombre, color, tipo, principal, idempresa, activo, descripcion, creado) VALUES ('
            . $this->db()->var2str($codigo) . ', ' . $this->db()->var2str(Tools::trans('auralis-calendario-comun-empresa'))
            . ", '#0b8043', 'comun', " . $this->db()->var2str(false) . ', '
            . ($idempresa > 0 ? (string)$idempresa : 'NULL') . ', ' . $this->db()->var2str(true) . ', '
            . $this->db()->var2str(Tools::trans('auralis-calendario-comun-empresa-descripcion')) . ', '
            . $this->db()->var2str(date('Y-m-d H:i:s')) . ')'
        );
        $idcalendario = $this->idDe($buscar);

        // Toda la empresa (nick y codrole vacíos) en «ver».
        $this->exec(
            'INSERT INTO ' . self::TABLA_ACCESOS . " (idcalendario, nick, codrole, permiso) VALUES ("
            . $idcalendario . ", NULL, NULL, 'ver')"
        );

        return $idcalendario;
    }

    /**
     * La empresa del evento si existe; si no, la empresa por defecto (0 = ninguna).
     */
    private function empresaValida(int $idempresa): int
    {
        foreach ([$idempresa, (int)Tools::settings('default', 'idempresa', 0)] as $candidata) {
            if ($candidata > 0 && $this->db()->select('SELECT idempresa FROM empresas WHERE idempresa = ' . $candidata) !== []) {
                return $candidata;
            }
        }

        return 0;
    }

    private function idDe(string $sql): int
    {
        $fila = $this->db()->select($sql);
        if ($fila === []) {
            throw new RuntimeException('auralis-calendarios: no se pudo crear el calendario');
        }

        return (int)$fila[0]['idcalendario'];
    }

    /**
     * MigrationClass::exec() no existe en Core < 2026.7 (error "Call to undefined method" en
     * clientes con ese núcleo). Si está disponible se usa tal cual; si no, se replica su
     * comportamiento (excepción si el SQL falla) para que la migración se reintente igualmente.
     */
    protected function exec(string $sql): void
    {
        if (method_exists(MigrationClass::class, 'exec')) {
            parent::exec($sql);
            return;
        }

        if (false === $this->db()->exec($sql)) {
            throw new RuntimeException('auralis-calendarios: fallo al ejecutar SQL: ' . $sql);
        }
    }
}

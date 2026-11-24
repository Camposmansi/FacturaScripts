<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Participante/asistente N:M de un evento de calendario Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class AuralisCalendarEventParticipant extends ModelClass
{
    use ModelTrait;

    public const TIPO_USER = 'user';
    public const TIPO_CLIENTE = 'cliente';
    public const TIPO_CONTACTO = 'contacto';
    public const TIPO_AGENTE = 'agente';

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_ACEPTADO = 'aceptado';
    public const ESTADO_RECHAZADO = 'rechazado';

    /** @var int|null */
    public $id;

    /** @var int */
    public $idevent;

    /** @var string */
    public $tipo;

    /** @var string */
    public $codigo;

    /** @var string|null */
    public $email;

    /** @var string */
    public $estado_asistencia;

    /** @var string|null */
    public $nombre_snapshot;

    public function clear(): void
    {
        parent::clear();
        $this->idevent = 0;
        $this->tipo = self::TIPO_USER;
        $this->codigo = '';
        $this->email = null;
        $this->estado_asistencia = self::ESTADO_PENDIENTE;
        $this->nombre_snapshot = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_event_participants';
    }

    /**
     * @return list<string>
     */
    public static function tiposPermitidos(): array
    {
        return [
            self::TIPO_USER,
            self::TIPO_CLIENTE,
            self::TIPO_CONTACTO,
            self::TIPO_AGENTE,
        ];
    }

    public function test(): bool
    {
        $this->idevent = (int)($this->idevent ?? 0);
        $this->tipo = trim((string)($this->tipo ?? ''));
        $this->codigo = Tools::noHtml(trim((string)($this->codigo ?? '')));
        $this->email = trim((string)($this->email ?? ''));
        if ($this->email === '') {
            $this->email = null;
        } elseif (false === filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->email = null;
        }

        $this->estado_asistencia = trim((string)($this->estado_asistencia ?? self::ESTADO_PENDIENTE));
        if (!in_array($this->estado_asistencia, [self::ESTADO_PENDIENTE, self::ESTADO_ACEPTADO, self::ESTADO_RECHAZADO], true)) {
            $this->estado_asistencia = self::ESTADO_PENDIENTE;
        }

        $nombre = Tools::noHtml(trim((string)($this->nombre_snapshot ?? '')));
        $this->nombre_snapshot = $nombre === '' ? null : mb_substr($nombre, 0, 200);

        if ($this->idevent <= 0 || $this->codigo === '' || !in_array($this->tipo, self::tiposPermitidos(), true)) {
            return false;
        }

        if (mb_strlen($this->codigo) > 50) {
            $this->codigo = mb_substr($this->codigo, 0, 50);
        }

        return parent::test();
    }

    /**
     * @return self[]
     */
    public static function allByEvent(int $idevent): array
    {
        if ($idevent <= 0) {
            return [];
        }

        return static::all(
            [Where::eq('idevent', $idevent)],
            ['tipo' => 'ASC', 'id' => 'ASC']
        );
    }

    /**
     * @return list<int>
     */
    public static function eventIdsForUser(string $nick, int $limit = 5000): array
    {
        return self::eventIdsForTipoCodigo(self::TIPO_USER, $nick, $limit);
    }

    /**
     * IDs de evento con un participante concreto (tipo + código).
     *
     * @return list<int>
     */
    public static function eventIdsForTipoCodigo(string $tipo, string $codigo, int $limit = 5000): array
    {
        $tipo = trim($tipo);
        $codigo = trim($codigo);
        if ($tipo === '' || $codigo === '' || !in_array($tipo, self::tiposPermitidos(), true)) {
            return [];
        }

        $rows = static::all(
            [
                Where::eq('tipo', $tipo),
                Where::eq('codigo', $codigo),
            ],
            ['idevent' => 'ASC'],
            0,
            max(1, min(10000, $limit))
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row->idevent ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{tipo: string, codigo: string, email: ?string, estado_asistencia: string, nombre_snapshot: ?string}>
     */
    public static function toArrayList(int $idevent): array
    {
        $list = [];
        foreach (self::allByEvent($idevent) as $row) {
            $list[] = [
                'tipo' => (string)$row->tipo,
                'codigo' => (string)$row->codigo,
                'email' => $row->email !== null ? (string)$row->email : null,
                'estado_asistencia' => (string)$row->estado_asistencia,
                'nombre_snapshot' => $row->nombre_snapshot !== null ? (string)$row->nombre_snapshot : null,
            ];
        }

        return $list;
    }
}

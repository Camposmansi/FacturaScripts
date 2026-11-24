<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Entrega idempotente de un recordatorio de calendario a un destinatario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class AuralisCalendarEventNotifDelivery extends ModelClass
{
    use ModelTrait;

    public const CANAL_EMAIL = 'email';

    /** @var int|null */
    public $id;

    /** @var int */
    public $idnotif;

    /** @var string */
    public $canal;

    /** @var string */
    public $tipo;

    /** @var string */
    public $codigo;

    /** @var string|null */
    public $email;

    /** @var int */
    public $sent;

    /** @var string|null */
    public $sent_at;

    public function clear(): void
    {
        parent::clear();
        $this->idnotif = 0;
        $this->canal = self::CANAL_EMAIL;
        $this->tipo = AuralisCalendarEventParticipant::TIPO_USER;
        $this->codigo = '';
        $this->email = null;
        $this->sent = 0;
        $this->sent_at = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendar_event_notif_deliveries';
    }

    public function test(): bool
    {
        $this->idnotif = (int)($this->idnotif ?? 0);
        $this->canal = trim((string)($this->canal ?? self::CANAL_EMAIL));
        if ($this->canal === '') {
            $this->canal = self::CANAL_EMAIL;
        }
        $this->tipo = trim((string)($this->tipo ?? ''));
        $this->codigo = Tools::noHtml(trim((string)($this->codigo ?? '')));
        $this->email = trim((string)($this->email ?? ''));
        if ($this->email === '') {
            $this->email = null;
        }
        $this->sent = !empty($this->sent) ? 1 : 0;

        if ($this->idnotif <= 0 || $this->codigo === '' || $this->tipo === '') {
            return false;
        }

        return parent::test();
    }

    /**
     * Idempotencia de entrega por destinatario (no confundir con ModelClass::findOrCreate).
     */
    public static function asegurarEntrega(
        int $idnotif,
        string $canal,
        string $tipo,
        string $codigo,
        ?string $email = null
    ): ?self {
        if ($idnotif <= 0 || $tipo === '' || $codigo === '') {
            return null;
        }

        $existing = new self();
        if ($existing->loadWhere([
            Where::eq('idnotif', $idnotif),
            Where::eq('canal', $canal),
            Where::eq('tipo', $tipo),
            Where::eq('codigo', $codigo),
        ])) {
            if ($email !== null && $email !== '' && (string)$existing->email !== $email) {
                $existing->email = $email;
                $existing->save();
            }
            return $existing;
        }

        $row = new self();
        $row->idnotif = $idnotif;
        $row->canal = $canal;
        $row->tipo = $tipo;
        $row->codigo = $codigo;
        $row->email = $email;
        $row->sent = 0;
        if (false === $row->save()) {
            return null;
        }

        return $row;
    }

    public function markSent(): bool
    {
        $this->sent = 1;
        $this->sent_at = Tools::dateTime();
        return $this->save();
    }
}

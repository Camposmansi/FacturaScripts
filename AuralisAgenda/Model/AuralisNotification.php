<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Notificación in-app nativa de Auralis (calendario / novedades).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\NotificationTypeRegistry;

class AuralisNotification extends ModelClass
{
    use ModelTrait;

    public const TYPE_CALENDAR = 'calendar';

    public const TYPE_CHANGELOG = 'changelog';

    /** Push: recién creada, aún sin intentar. */
    public const PUSH_PENDIENTE = 'pendiente';

    /** Push: el destinatario tenía el ERP abierto; se reintenta. */
    public const PUSH_PENDIENTE_ONLINE = 'pendiente_online';

    /** Push: sin suscripciones ni destinos; se reintenta. */
    public const PUSH_SIN_SUSCRIPCION = 'sin_suscripcion';

    /** Push: el servicio respondió 429 o 5xx; se reintenta. */
    public const PUSH_REINTENTO = 'reintento';

    /** Push: al menos una entrega con HTTP 2xx. Solo este estado significa enviado. */
    public const PUSH_ENVIADO = 'enviado';

    /** Push cerrado: el destinatario no puede recibir este tipo. */
    public const PUSH_SIN_PERMISO = 'sin_permiso';

    /** Push cerrado: el tipo no admite push. */
    public const PUSH_TIPO_SIN_PUSH = 'tipo_sin_push';

    /** Push cerrado: aviso missed, solo va a Actividad. */
    public const PUSH_MISSED = 'missed';

    /** Push cerrado: se alcanzó el techo de intentos. */
    public const PUSH_AGOTADO = 'agotado';

    /** Push cerrado: el aviso superó las horas máximas de reintento. */
    public const PUSH_CADUCADO = 'caducado';

    /**
     * Valores admitidos en push_estado. Null es una fila anterior a la columna.
     *
     * @var list<string>
     */
    public const PUSH_ESTADOS = [
        self::PUSH_PENDIENTE,
        self::PUSH_PENDIENTE_ONLINE,
        self::PUSH_SIN_SUSCRIPCION,
        self::PUSH_REINTENTO,
        self::PUSH_ENVIADO,
        self::PUSH_SIN_PERMISO,
        self::PUSH_TIPO_SIN_PUSH,
        self::PUSH_MISSED,
        self::PUSH_AGOTADO,
        self::PUSH_CADUCADO,
    ];

    /** @var int|null */
    public $id;

    /** @var string */
    public $nick;

    /** @var string */
    public $tipo;

    /** @var string */
    public $titulo;

    /** @var string|null */
    public $cuerpo;

    /** @var string|null */
    public $url;

    /** @var int|null */
    public $idevent;

    /** @var int|null */
    public $idcalendarnotif;

    /** @var bool|int */
    public $leida;

    /** @var bool|int Aviso materializado tras el inicio del evento (sin auto-popup/push). */
    public $missed;

    /** @var string|null */
    public $snoozed_until;

    /** @var string */
    public $dedup_key;

    /** @var string|null Referencia en claro para resolve(). La dedup_key sigue siendo el hash. */
    public $referencia;

    /** @var string|null */
    public $created_at;

    /** @var string|null */
    public $push_sent_at;

    /** @var int|null Intentos de push ante 429 o 5xx. Null equivale a cero. */
    public $push_attempts;

    /** @var string|null No reintentar el push antes de esta hora. */
    public $push_retry_at;

    /** @var string|null Estado del canal push (ver PUSH_ESTADOS). Null en filas antiguas. */
    public $push_estado;

    public function clear(): void
    {
        parent::clear();
        $this->tipo = self::TYPE_CALENDAR;
        $this->leida = false;
        $this->missed = false;
        $this->snoozed_until = null;
        $this->idevent = null;
        $this->idcalendarnotif = null;
        $this->created_at = Tools::dateTime();
        $this->push_sent_at = null;
        $this->push_attempts = null;
        $this->push_retry_at = null;
        $this->push_estado = null;
        $this->referencia = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_notificaciones';
    }

    public function test(): bool
    {
        $this->nick = Tools::noHtml(trim((string)($this->nick ?? '')));
        $this->tipo = Tools::noHtml(trim((string)($this->tipo ?? self::TYPE_CALENDAR)));
        $this->titulo = Tools::noHtml(trim((string)($this->titulo ?? '')));
        $this->cuerpo = Tools::noHtml(trim((string)($this->cuerpo ?? '')));
        $this->url = Tools::noHtml(trim((string)($this->url ?? '')));
        $this->dedup_key = Tools::noHtml(trim((string)($this->dedup_key ?? '')));
        $referencia = $this->referencia ?? null;
        if ($referencia === null || $referencia === '') {
            $this->referencia = null;
        } else {
            $this->referencia = Tools::noHtml(trim((string)$referencia));
            if (mb_strlen($this->referencia) > 120) {
                $this->referencia = mb_substr($this->referencia, 0, 120);
            }
            if ($this->referencia === '') {
                $this->referencia = null;
            }
        }
        $this->leida = !empty($this->leida);
        $this->missed = !empty($this->missed);
        $intentos = $this->push_attempts ?? null;
        if ($intentos === null || $intentos === '') {
            $this->push_attempts = null;
        } else {
            $this->push_attempts = max(0, (int)$intentos);
        }
        $reintento = $this->push_retry_at ?? null;
        $this->push_retry_at = $reintento !== null && $reintento !== ''
            ? (string)$reintento
            : null;
        $estado = trim((string)($this->push_estado ?? ''));
        if ($estado === '') {
            $this->push_estado = null;
        } elseif (in_array($estado, self::PUSH_ESTADOS, true)) {
            $this->push_estado = $estado;
        } else {
            return false;
        }

        if ($this->nick === '' || $this->titulo === '' || $this->dedup_key === '') {
            return false;
        }

        // Solo el alta exige un tipo registrado. Una fila ya guardada conserva su tipo
        // aunque el plugin que lo registró ya no esté: si no, no se podría marcar leída.
        $id = (int)($this->id ?? 0);
        if ($id <= 0 && NotificationTypeRegistry::get($this->tipo) === null) {
            return false;
        }

        if (mb_strlen($this->titulo) > 200) {
            $this->titulo = mb_substr($this->titulo, 0, 200);
        }

        if ($this->url !== '' && mb_strlen($this->url) > 250) {
            $this->url = mb_substr($this->url, 0, 250);
        }

        if (mb_strlen($this->dedup_key) > 40) {
            $this->dedup_key = mb_substr($this->dedup_key, 0, 40);
        }

        if (empty($this->created_at)) {
            $this->created_at = Tools::dateTime();
        }

        return parent::test();
    }

    public function isSnoozed(?string $now = null): bool
    {
        if (empty($this->snoozed_until)) {
            return false;
        }

        $now = $now ?? Tools::dateTime();
        return (string)$this->snoozed_until > $now;
    }

    public function isMissed(): bool
    {
        return !empty($this->missed);
    }
}

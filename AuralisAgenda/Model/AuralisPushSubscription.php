<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Suscripción Web Push de un usuario (endpoint FCM/Mozilla/etc.).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisPushSubscription extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $id;

    /** @var string */
    public $nick;

    /** @var string */
    public $endpoint;

    /** @var string|null */
    public $p256dh;

    /** @var string|null */
    public $auth;

    /** @var string|null */
    public $user_agent;

    /** @var string|null */
    public $created_at;

    /** @var string|null Último envío con respuesta 2xx del servicio push (null si nunca). */
    public $last_success_at;

    /** @var string|null Último ping de la campana desde este navegador (presencia por dispositivo). */
    public $last_ping_at;

    public function clear(): void
    {
        parent::clear();
        $this->created_at = Tools::dateTime();
        $this->user_agent = null;
        $this->last_success_at = null;
        $this->last_ping_at = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_push_subscriptions';
    }

    public function test(): bool
    {
        $this->nick = Tools::noHtml(trim((string)($this->nick ?? '')));
        $this->endpoint = trim((string)($this->endpoint ?? ''));
        $this->p256dh = trim((string)($this->p256dh ?? ''));
        $this->auth = trim((string)($this->auth ?? ''));
        $this->user_agent = Tools::noHtml(mb_substr(trim((string)($this->user_agent ?? '')), 0, 250));

        if ($this->nick === '' || $this->endpoint === '' || $this->p256dh === '' || $this->auth === '') {
            return false;
        }

        if (false === filter_var($this->endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (empty($this->created_at)) {
            $this->created_at = Tools::dateTime();
        }

        return parent::test();
    }
}

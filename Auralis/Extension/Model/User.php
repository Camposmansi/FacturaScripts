<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Extensión del modelo User para el plugin Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Extension\Model;

use Closure;
use FacturaScripts\Core\Model\LogMessage;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;

class User
{
    /** @var string|null */
    public $auralis_modo_menu;

    /** @var string|null */
    public $auralis_tema_color;

    public function save(): Closure
    {
        return function () {
            $sessionUser = Session::user();
            if ($sessionUser && $this->nick === $sessionUser->nick) {
                $sessionUser->auralis_modo_menu = $this->auralis_modo_menu;
                $sessionUser->auralis_tema_color = $this->auralis_tema_color;
                Session::set('user', $sessionUser);
            }

            if (method_exists($this, 'isDirty') && $this->isDirty('logkey')) {
                $log = new LogMessage();
                $log->channel = 'audit';
                $log->level = 'notice';
                $log->message = 'Inicio de sesión exitoso del usuario ' . $this->nick;
                $log->nick = $this->nick;
                $log->ip = Session::getClientIp();
                $log->time = Tools::dateTime();
                $log->save();
            }
        };
    }
}

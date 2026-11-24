<?php
/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Controlador Login para el plugin Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Controller\Login as CoreLogin;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;

class Login extends CoreLogin
{
    /**
     * @param User $user
     * @param string $ip
     * @param Request $request
     */
    protected function updateUserAndRedirect(User $user, string $ip, Request $request): void
    {
        $nick = AuralisSecurity::sanitizeLogLabel((string) $user->nick);
        Tools::log('Login')->notice('Acceso con éxito. Usuario: ' . $nick . ' desde IP: ' . $ip);
        Tools::log('Login')->save();

        parent::updateUserAndRedirect($user, $ip, $request);
    }

    /**
     * @param string $ip
     * @param string $user
     * @param int|null $time
     */
    public function saveIncident(string $ip, string $user = '', ?int $time = null): void
    {
        parent::saveIncident($ip, $user, $time);

        if ($this->userHasManyIncidents($ip, $user)) {
            $msg = 'Bloqueo temporal por intentos fallidos de login. IP: ' . $ip;
            if (!empty($user)) {
                $msg .= ' Usuario: ' . AuralisSecurity::sanitizeLogLabel($user);
            }
            Tools::log('Login')->warning($msg);
            Tools::log('Login')->save();
        }
    }
}

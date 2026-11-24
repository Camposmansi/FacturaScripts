<?php
/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Controlador público para solicitar y procesar la recuperación de contraseña.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\Html;
use FacturaScripts\Dinamic\Lib\Email\NewMail;
use FacturaScripts\Core\Model\LogMessage;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\PasswordResetTokenStore;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use FacturaScripts\Plugins\Auralis\Model\AuralisPasswordReset;

class AuralisResetPassword implements ControllerInterface
{
    private const IP_RATE_LIMIT = 10;
    private const IP_RATE_WINDOW = 900;

    /** @var string */
    public $title = 'restablecer-contrasena';

    /** @var string */
    public $template = 'Login/AuralisResetPassword.html.twig';

    /** @var string|null */
    public $token = null;

    /** @var string */
    public $tokenRef = '';

    /** @var bool */
    public $validToken = false;

    /** @var string */
    public $errorMessage = '';

    /** @var string */
    public $successMessage = '';

    public function __construct(string $className, string $url = '')
    {
    }

    public function getPageData(): array
    {
        return [
            'name' => 'AuralisResetPassword',
            'title' => Tools::lang()->trans('restablecer-contrasena'),
            'icon' => 'fa-solid fa-key',
            'menu' => '',
            'submenu' => '',
            'showonmenu' => false,
            'public' => true
        ];
    }

    public function run(): void
    {
        header('Referrer-Policy: no-referrer');

        $request = Request::createFromGlobals();
        $action = $request->inputOrQuery('action', '');

        if ($action === 'request-password-reset') {
            $this->handleRequestReset($request);
            return;
        }

        if ($action === 'reset-password') {
            $this->handleResetPassword($request);
        } else {
            $this->resolveTokenForDisplay($request);
        }

        echo Html::render($this->template, [
            'controllerName' => 'AuralisResetPassword',
            'debugBarRender' => false,
            'fsc' => $this,
            'template' => $this->template,
        ]);
    }

    private function resolveTokenForDisplay(Request $request): void
    {
        $queryToken = trim((string)$request->query->get('token', ''));
        if ($queryToken !== '') {
            $this->validateToken($queryToken);
            if ($this->validToken) {
                $ref = PasswordResetTokenStore::store($queryToken);
                if ($ref !== '') {
                    $this->redirectToRef($ref);
                }
                $this->token = $queryToken;
            }

            return;
        }

        $ref = trim((string)$request->query->get(PasswordResetTokenStore::QUERY_PARAM, ''));
        $cachedToken = PasswordResetTokenStore::retrieve($ref);
        if ($cachedToken !== null) {
            $this->token = $cachedToken;
            $this->tokenRef = $ref;
            $this->validateToken($cachedToken);
            return;
        }

        if ($ref !== '') {
            $this->validToken = false;
            $this->errorMessage = Tools::lang()->trans('token-invalido-o-usado');
        }
    }

    private function redirectToRef(string $ref): void
    {
        $route = rtrim(Tools::config('route', ''), '/');
        header('Location: ' . $route . '/AuralisResetPassword?' . PasswordResetTokenStore::QUERY_PARAM . '=' . $ref);
        exit;
    }

    private function handleRequestReset(Request $request): void
    {
        header('Content-Type: application/json');

        $response = [
            'status' => 'ok',
            'message' => Tools::lang()->trans('mensaje-recuperacion-enviado'),
            'newToken' => (new MultiRequestProtection())->newToken(),
        ];

        if (false === $this->validateFormToken($request)) {
            echo json_encode($response);
            exit;
        }

        $clientIp = Session::getClientIp();
        if (PasswordResetTokenStore::tooManyAttempts($clientIp, self::IP_RATE_LIMIT, self::IP_RATE_WINDOW)
            || AuralisPasswordReset::countRecentByIp($clientIp, self::IP_RATE_WINDOW) >= self::IP_RATE_LIMIT
        ) {
            Tools::log('Login')->warning(
                'Exceso de solicitudes de recuperación de contraseña desde la IP: ' . $clientIp
            );
            Tools::log('Login')->save();
            echo json_encode($response);
            exit;
        }

        $email = trim($request->input('recovery_email', ''));
        if (empty($email)) {
            echo json_encode($response);
            exit;
        }

        $emailClean = trim($email);

        $recentCutoff = date('Y-m-d H:i:s', time() - 60);
        $recentResets = AuralisPasswordReset::all([
            Where::eq('email', $emailClean),
            Where::gte('created_at', $recentCutoff)
        ]);
        if (!empty($recentResets)) {
            echo json_encode($response);
            exit;
        }

        $emailLower = strtolower($emailClean);
        $userFound = null;

        $usersByEmail = User::all([Where::eq('email', $emailLower)], [], 0, 1);
        if (!empty($usersByEmail) && $usersByEmail[0]->enabled) {
            $userFound = $usersByEmail[0];
        }

        if (null === $userFound) {
            $usersByEmailExact = User::all([Where::eq('email', $emailClean)], [], 0, 1);
            if (!empty($usersByEmailExact) && $usersByEmailExact[0]->enabled) {
                $userFound = $usersByEmailExact[0];
            }
        }

        if (null === $userFound) {
            $u = new User();
            if ($u->load($emailClean) && $u->enabled) {
                $userFound = $u;
            }
        }

        if (null === $userFound) {
            Tools::log('Login')->warning(
                'Solicitud de recuperación de contraseña para un correo no registrado: '
                . AuralisSecurity::maskEmail($emailClean)
            );
            Tools::log('Login')->save();
            // Mitigación de timing attack: igualar latencia con peticiones que envían email real
            usleep(random_int(250000, 380000));
            echo json_encode($response);
            exit;
        }

        $targetEmail = !empty($userFound->email) ? $userFound->email : $emailClean;

        Tools::log('Login')->notice(
            'Solicitud de recuperación de contraseña iniciada para el usuario encontrado: '
            . AuralisSecurity::sanitizeLogLabel((string) $userFound->nick)
            . ' (' . AuralisSecurity::maskEmail($targetEmail) . '). IP: ' . $clientIp
        );
        Tools::log('Login')->save();

        AuralisPasswordReset::clearExpired();

        $tokenRaw = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenRaw);

        // Sin site_url válida no se genera enlace (nunca se usa la cabecera Host): no se guarda token ni se envía correo.
        $resetUrl = AuralisSecurity::buildPublicPageUrl('AuralisResetPassword', ['token' => $tokenRaw]);
        if ($resetUrl === '') {
            Tools::log('Login')->error('auralis-reset-sin-site-url');
            Tools::log('Login')->save();
            echo json_encode($response);
            exit;
        }

        $reset = new AuralisPasswordReset();
        $reset->email = $targetEmail;
        $reset->token = $tokenHash;
        $reset->created_at = Tools::dateTime();
        $reset->expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $reset->used = false;
        if ($reset->hasColumn('ip')) {
            $reset->ip = $clientIp;
        }

        if ($reset->save()) {
            $mail = new NewMail();
            $mail->to($targetEmail);
            $mail->title = Tools::lang()->trans('auralis-reset-mail-subject', [], 'Auralis');
            // URL en texto plano (sin blockButton): evita que Core añada ?verificode= al enlace.
            // El token ya autentica el reset; verificode no se valida ni aporta seguridad aquí.
            $buttonLabel = Tools::lang()->trans('restablecer-contrasena', [], 'Auralis');
            $mail->text = Tools::lang()->trans('auralis-reset-mail-body', [], 'Auralis')
                . "\n\n" . $buttonLabel . ":\n" . $resetUrl;

            if (false === $mail->canSendMail()) {
                Tools::log('Login')->error(
                    'No se pudo enviar el correo de recuperación porque el servicio de Email del ERP no está configurado.'
                );
            } elseif (false === $mail->send()) {
                Tools::log('Login')->error(
                    'Error al enviar el correo electrónico de recuperación de contraseña a '
                    . AuralisSecurity::maskEmail($targetEmail)
                );
            } else {
                Tools::log('Login')->notice(
                    'Enviado correo de recuperación de contraseña a '
                    . AuralisSecurity::maskEmail($targetEmail) . '. IP: ' . $clientIp
                );
            }

            Tools::log('Login')->save();
        }

        echo json_encode($response);
        exit;
    }

    private function validateToken(string $tokenStr): void
    {
        $reset = $this->findActiveReset($tokenStr);
        if (null === $reset) {
            $this->validToken = false;
            $this->errorMessage = Tools::trans('token-invalido-o-usado');
            return;
        }

        if (strtotime($reset->expires_at) < time()) {
            $this->validToken = false;
            $this->errorMessage = Tools::trans('token-caducado');
            return;
        }

        $this->validToken = true;
    }

    private function handleResetPassword(Request $request): void
    {
        $tokenStr = (string)$request->input('token', '');
        $ref = trim((string)$request->input(PasswordResetTokenStore::QUERY_PARAM, ''));
        $this->tokenRef = $ref;
        if ($tokenStr !== '') {
            $this->token = $tokenStr;
            $this->validateToken($tokenStr);
        }

        $clientIp = Session::getClientIp();
        if (PasswordResetTokenStore::tooManyAttempts($clientIp, self::IP_RATE_LIMIT, self::IP_RATE_WINDOW)) {
            $this->errorMessage = Tools::trans('demasiados-intentos-reset');
            return;
        }

        if (false === $this->validateFormToken($request)) {
            $this->errorMessage = Tools::trans('error-csrf-reset-password');
            return;
        }

        $password = (string)$request->input('new_password', '');
        $passwordConfirm = (string)$request->input('new_password_confirm', '');

        if (empty($password) || mb_strlen($password) < 6) {
            $this->errorMessage = Tools::trans('minimo-6-caracteres');
            return;
        }

        if ($password !== $passwordConfirm) {
            $this->errorMessage = Tools::trans('contrasenas-no-coinciden');
            return;
        }

        $reset = $this->findActiveReset($tokenStr);
        if (null === $reset) {
            $this->errorMessage = Tools::trans('token-invalido-o-usado');
            return;
        }

        if (strtotime($reset->expires_at) < time()) {
            $this->errorMessage = Tools::trans('token-caducado');
            return;
        }

        $user = null;
        $users = User::all([Where::eq('email', $reset->email)]);
        if (!empty($users)) {
            $user = $users[0];
        } else {
            $u = new User();
            if ($u->load($reset->email)) {
                $user = $u;
            }
        }

        if (null === $user || false === $user->enabled) {
            $this->errorMessage = Tools::trans('usuario-no-encontrado');
            return;
        }

        if ($user->setPassword($password) && $user->save()) {
            $reset->used = true;
            $reset->save();

            $log = new LogMessage();
            $log->channel = 'audit';
            $log->level = 'notice';
            $log->message = 'Contraseña restablecida vía AuralisResetPassword';
            $log->nick = $user->nick;
            $log->ip = $clientIp;
            $log->time = Tools::dateTime();
            $log->context = json_encode(['ip' => $clientIp, 'email' => AuralisSecurity::maskEmail((string) $reset->email)]);
            $log->save();

            Tools::log('Login')->notice(
                'Contraseña restablecida correctamente para el usuario ' . $user->nick . '. IP: ' . $clientIp
            );
            Tools::log('Login')->save();

            $this->successMessage = Tools::trans('contrasena-restablecida-exito');
            $this->validToken = false;
            $this->token = null;
            PasswordResetTokenStore::forget($ref);
        } else {
            $this->errorMessage = Tools::trans('error-actualizar-contrasena');
        }
    }

    private function findActiveReset(string $tokenStr): ?AuralisPasswordReset
    {
        if ($tokenStr === '') {
            return null;
        }

        $tokenHash = hash('sha256', $tokenStr);
        $resets = AuralisPasswordReset::all([
            Where::eq('token', $tokenHash),
            Where::eq('used', false),
        ]);

        return empty($resets) ? null : $resets[0];
    }

    private function validateFormToken(Request $request): bool
    {
        $multiRequestProtection = new MultiRequestProtection();

        // Página pública: no añadir fsNick (formToken() en Twig no lo incluye).
        $token = $request->inputOrQuery('multireqtoken', '');
        if (empty($token) || false === $multiRequestProtection->validate($token)) {
            Tools::log('Login')->warning('invalid-request-auralis-reset-password');
            Tools::log('Login')->save();
            return false;
        }

        if ($multiRequestProtection->tokenExist($token)) {
            Tools::log('Login')->warning('duplicated-request-auralis-reset-password');
            Tools::log('Login')->save();
            return false;
        }

        return true;
    }
}

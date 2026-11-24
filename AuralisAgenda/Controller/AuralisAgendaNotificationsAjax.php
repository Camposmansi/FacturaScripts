<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Endpoint AJAX ultra-ligero de la campana in-app Auralis (ping/load/snooze/JIT).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\Traits\AuralisAjaxCsrfSeedTrait;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisNotificationManager;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPwaPaths;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisTwigFunctions;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushCryptoCapability;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushEndpointPolicy;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushSender;

class AuralisAgendaNotificationsAjax extends Controller
{
    use AuralisAjaxCsrfSeedTrait;

    public function getPageData(): array
    {
        // initControllers() tras enable/deploy: registrar Twig antes del render de AdminPlugins.
        AuralisTwigFunctions::register();

        $data = parent::getPageData();
        $data['menu'] = '';
        $data['submenu'] = '';
        $data['title'] = 'auralis-inapp-notifications';
        $data['icon'] = 'fa-solid fa-bell';
        $data['showonmenu'] = false;
        return $data;
    }

    public function run(): void
    {
        parent::run();

        $action = (string)$this->request()->query('action', '')
            ?: (string)$this->request()->input('action', '');

        // Liberar el bloqueo de sesión lo antes posible tras autenticar.
        if (function_exists('session_write_close')) {
            session_write_close();
        }

        $nick = (string)($this->user->nick ?? '');
        if ($nick === '') {
            $this->json(['ok' => false, 'message' => Tools::trans('login-password-fail')], 401);
            return;
        }

        // Misma semilla CSRF que las páginas (nick): valida el token del panel y emite newToken compatible.
        $this->ensureAjaxCsrfSeed();

        switch ($action) {
            case 'ping':
                $this->actionPing($nick);
                return;
            case 'load':
                $this->actionLoad($nick);
                return;
            case 'mark-read':
                $this->actionMarkRead($nick);
                return;
            case 'mark-all-read':
                $this->actionMarkAllRead($nick);
                return;
            case 'snooze':
                $this->actionSnooze($nick);
                return;
            case 'dismiss':
                $this->actionDismiss($nick);
                return;
            case 'push-subscribe':
                $this->actionPushSubscribe($nick);
                return;
            case 'push-unsubscribe':
                $this->actionPushUnsubscribe($nick);
                return;
            case 'push-config':
                $this->actionPushConfig($nick);
                return;
            case 'push-test':
                $this->actionPushTest($nick);
                return;
            default:
                $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
        }
    }

    private function actionPing(string $nick): void
    {
        // Presencia por nick (throttle en settings): no es “alguien en la empresa”. Queda de respaldo.
        AuralisPushSettings::touchPresence($nick);
        $this->touchPresenciaDispositivo($nick);

        $canCalendar = $this->canCalendarAgenda();
        if ($canCalendar) {
            AuralisNotificationManager::materializeForUser($nick);
        }

        $this->json([
            'ok' => true,
            'can_calendar' => $canCalendar,
            'count' => AuralisNotificationManager::countUnread($nick),
            'has_unread_news' => false,
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
            'pollSeconds' => AuralisNotificationManager::pollSeconds(),
            'enabled' => AuralisNotificationManager::isInAppEnabled(),
            'push' => $this->pushClientConfig($nick),
        ]);
    }

    private function actionLoad(string $nick): void
    {
        AuralisPushSettings::touchPresence($nick);
        $this->touchPresenciaDispositivo($nick);

        $canCalendar = $this->canCalendarAgenda();
        if ($canCalendar) {
            AuralisNotificationManager::materializeForUser($nick);
        }

        $this->json([
            'ok' => true,
            'can_calendar' => $canCalendar,
            'count' => AuralisNotificationManager::countUnread($nick),
            'has_unread_news' => false,
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
            'items' => AuralisNotificationManager::listForDropdown($nick),
            'popups' => AuralisNotificationManager::listDuePopups($nick),
            'changelog' => [],
            'pollSeconds' => AuralisNotificationManager::pollSeconds(),
            'snoozeMinutes' => AuralisNotificationManager::SNOOZE_MINUTES,
            'push' => $this->pushClientConfig($nick),
        ]);
    }

    /**
     * Presencia por dispositivo: el JS manda push_endpoint (solo en el cuerpo POST, nunca en la URL)
     * si este navegador tiene suscripción. Solo se toca la fila de ese endpoint y de este nick.
     * Ni se loguea ni se devuelve el endpoint.
     */
    private function touchPresenciaDispositivo(string $nick): void
    {
        $endpoint = trim((string)$this->request()->input('push_endpoint', ''));
        if ($endpoint === '' || mb_strlen($endpoint) > PushEndpointPolicy::MAX_ENDPOINT_LENGTH) {
            return;
        }

        if (false === AuralisPushSettings::isPushEnabled()) {
            return;
        }

        AuralisPushSettings::touchPresenceForSubscription($nick, $endpoint);
    }

    private function actionMarkRead(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        $id = (int)$this->request()->input('id', 0);
        $ok = AuralisNotificationManager::markRead($id, $nick);
        $this->json([
            'ok' => $ok,
            'count' => AuralisNotificationManager::countUnread($nick),
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
        ], $ok ? 200 : 404);
    }

    private function actionMarkAllRead(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        $updated = AuralisNotificationManager::markAllRead($nick);
        $this->json([
            'ok' => true,
            'updated' => $updated,
            'count' => AuralisNotificationManager::countUnread($nick),
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
        ]);
    }

    private function actionSnooze(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        $id = (int)$this->request()->input('id', 0);
        $minutes = (int)$this->request()->input('minutes', 5);
        $ok = AuralisNotificationManager::snooze($id, $nick, $minutes);
        $this->json([
            'ok' => $ok,
            'count' => AuralisNotificationManager::countUnread($nick),
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
        ], $ok ? 200 : 400);
    }

    private function actionDismiss(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        $id = (int)$this->request()->input('id', 0);
        $ok = AuralisNotificationManager::dismiss($id, $nick);
        $this->json([
            'ok' => $ok,
            'count' => AuralisNotificationManager::countUnread($nick),
            'next_alarm_seconds' => AuralisNotificationManager::nextAlarmInSeconds($nick),
        ], $ok ? 200 : 404);
    }

    private function actionPushConfig(string $nick): void
    {
        $this->json([
            'ok' => true,
            'push' => $this->pushClientConfig($nick),
        ]);
    }

    private function actionPushSubscribe(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        if (false === AuralisPushSettings::isPushEnabled()) {
            $this->json(['ok' => false, 'message' => Tools::trans('auralis-push-disabled')], 400);
            return;
        }

        $endpoint = (string)$this->request()->input('endpoint', '');
        $p256dh = (string)$this->request()->input('p256dh', '');
        $auth = (string)$this->request()->input('auth', '');
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

        $ok = AuralisPushSettings::saveSubscription($nick, $endpoint, $p256dh, $auth, $ua);
        $this->json([
            'ok' => $ok,
            'push' => $this->pushClientConfig($nick),
        ], $ok ? 200 : 400);
    }

    /**
     * Destinatario del push de prueba: SIEMPRE el nick de la sesión.
     * Cualquier `nick` que venga en la petición se ignora a propósito (nadie prueba push a terceros).
     *
     * @param array<string, mixed> $entrada Datos de la petición (se ignoran).
     */
    public static function destinatarioPushPrueba(string $nickSesion, array $entrada = []): string
    {
        return trim($nickSesion);
    }

    private function actionPushTest(string $nickSesion): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        if (false === AuralisPushSettings::isPushEnabled()) {
            $this->json(['ok' => false, 'message' => Tools::trans('auralis-push-disabled')], 400);
            return;
        }

        $nick = self::destinatarioPushPrueba($nickSesion, $this->request()->request->all());
        $sender = new PushSender();
        if ($nick === '' || false === $sender->isReady()) {
            $this->json([
                'ok' => false,
                'entregas' => 0,
                'destinos' => 0,
                'message' => Tools::trans('auralis-push-test-not-ready'),
            ], 400);
            return;
        }

        $resultado = $sender->enviarAUsuario(
            $nick,
            Tools::trans('auralis-push-test-title'),
            Tools::trans('auralis-push-test-body')
        );
        $entregas = (int)$resultado['entregas'];
        $destinos = (int)$resultado['destinos'];

        if ($entregas > 0) {
            $mensaje = Tools::trans('auralis-push-test-ok');
        } elseif ($destinos === 0) {
            $mensaje = Tools::trans('auralis-push-test-no-devices');
        } else {
            $mensaje = Tools::trans('auralis-push-test-failed');
        }

        // Solo nick y recuentos: nunca endpoint ni claves.
        Tools::log('Auralis')->info('auralis-push-test-' . $nick . '-entregas-' . $entregas . '-destinos-' . $destinos);

        $this->json([
            'ok' => $entregas > 0,
            'entregas' => $entregas,
            'destinos' => $destinos,
            'message' => $mensaje,
        ]);
    }

    private function actionPushUnsubscribe(string $nick): void
    {
        if (false === $this->validateFormToken()) {
            $this->json(['ok' => false, 'message' => Tools::trans('invalid-request')], 400);
            return;
        }

        $endpoint = (string)$this->request()->input('endpoint', '');
        $ok = AuralisPushSettings::deleteSubscription($nick, $endpoint);
        $this->json([
            'ok' => $ok,
            'push' => $this->pushClientConfig($nick),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pushClientConfig(string $nick): array
    {
        $enabled = AuralisPushSettings::isPushEnabled()
            && PushCryptoCapability::isReady()
            && AuralisPushSettings::vapidPublic() !== '';

        return [
            'enabled' => $enabled,
            'https' => AuralisPwaPaths::isHttps(),
            'vapidPublic' => $enabled ? AuralisPushSettings::vapidPublic() : '',
            'manifestUrl' => 'AuralisPwaManifest',
            'swUrl' => 'AuralisPwaSw',
            'scope' => AuralisPwaPaths::scope(),
            'subs' => $enabled ? AuralisPushSettings::countSubscriptions($nick) : 0,
        ];
    }

    /**
     * Garantiza la semilla nick justo antes de validar, aunque algo la hubiera limpiado tras run().
     */
    protected function validateFormToken(): bool
    {
        $this->ensureAjaxCsrfSeed();
        return parent::validateFormToken();
    }

    private function canCalendarAgenda(): bool
    {
        return isset($this->user) && $this->user->can('CalendarioUsuario');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        // Instancia sembrada con el nick en run(): el token renovado sigue valiendo en la página y aquí.
        $payload['newToken'] = $this->ajaxCsrfNewToken();
        $response = $this->response();
        if ($status !== 200) {
            $response->setHttpCode($status);
        }
        $response->json($payload);
    }
}

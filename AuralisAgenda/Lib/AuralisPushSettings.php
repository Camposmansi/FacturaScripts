<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Settings y presencia push/PWA (canales separados, caps de rendimiento).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\AuralisPushSubscription;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushCryptoCapability;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushEndpointPolicy;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\VapidCredentials;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\VapidSecretBox;
use Throwable;

final class AuralisPushSettings
{
    public const SETTING_PUSH_ENABLED = 'calendar_push_notifications';

    public const SETTING_SKIP_ONLINE = 'calendar_push_skip_if_online_seconds';

    public const SETTING_BATCH_LIMIT = 'calendar_push_batch_limit';

    public const SETTING_MAX_SUBS = 'calendar_push_max_subs_per_user';

    /** Horas tras crear el aviso en las que aún se intenta el push. Después, caducado. */
    public const SETTING_MAX_RETRY_HOURS = 'calendar_push_max_retry_hours';

    public const SETTING_VAPID_PUBLIC = 'calendar_push_vapid_public';

    public const SETTING_VAPID_PRIVATE = 'calendar_push_vapid_private';

    public const SETTING_LAST_PING = 'last_ping_at';

    /** URL permanente del icono PWA (AttachedFile download-permanent). */
    public const SETTING_PWA_ICON = 'calendar_pwa_icon';

    /** idfile del icono PWA en AttachedFile. */
    public const SETTING_PWA_ICON_ID = 'calendar_pwa_icon_id';

    public const FALLBACK_ICON_192 = 'Dinamic/Assets/Images/auralis_agenda_icono-192.png';

    public const FALLBACK_ICON_512 = 'Dinamic/Assets/Images/auralis_agenda_icono-512.png';

    /** Extensiones admitidas para el icono PWA. */
    public const PWA_ICON_ACCEPT = '.png,.jpg,.jpeg,.webp,.svg';

    /** @var list<string> */
    public const PWA_ICON_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

    public const DEFAULT_SKIP_ONLINE = 180;

    public const DEFAULT_BATCH_LIMIT = 50;

    public const DEFAULT_MAX_SUBS = 5;

    public const DEFAULT_MAX_RETRY_HOURS = 24;

    /** Throttle de escritura de presencia (segundos). */
    public const PING_WRITE_THROTTLE = 60;

    /** Días sin envío correcto (o sin alta, si nunca hubo uno) tras los que se olvida una suscripción. */
    public const SUBSCRIPTION_TTL_DAYS = 90;

    /** Máximo de caracteres del user agent reducido que se guarda. */
    public const USER_AGENT_MAX_LENGTH = 60;

    /** Etiqueta cuando el user agent no se reconoce. */
    public const USER_AGENT_UNKNOWN = 'Navegador';

    public static function group(): string
    {
        return CalendarTimegrid::SETTING_GROUP;
    }

    public static function isPushEnabled(): bool
    {
        return (int)Tools::settings(self::group(), self::SETTING_PUSH_ENABLED, '0') === 1;
    }

    public static function skipIfOnlineSeconds(): int
    {
        $v = (int)Tools::settings(self::group(), self::SETTING_SKIP_ONLINE, (string)self::DEFAULT_SKIP_ONLINE);

        return max(60, min(900, $v > 0 ? $v : self::DEFAULT_SKIP_ONLINE));
    }

    public static function batchLimit(): int
    {
        $v = (int)Tools::settings(self::group(), self::SETTING_BATCH_LIMIT, (string)self::DEFAULT_BATCH_LIMIT);

        return max(1, min(200, $v > 0 ? $v : self::DEFAULT_BATCH_LIMIT));
    }

    public static function maxSubsPerUser(): int
    {
        $v = (int)Tools::settings(self::group(), self::SETTING_MAX_SUBS, (string)self::DEFAULT_MAX_SUBS);

        return max(1, min(20, $v > 0 ? $v : self::DEFAULT_MAX_SUBS));
    }

    /**
     * Ventana de reintento del push en horas (1 h a 7 días). Los intentos siguen fijos.
     */
    public static function maxRetryHours(): int
    {
        $v = (int)Tools::settings(self::group(), self::SETTING_MAX_RETRY_HOURS, (string)self::DEFAULT_MAX_RETRY_HOURS);

        return max(1, min(168, $v > 0 ? $v : self::DEFAULT_MAX_RETRY_HOURS));
    }

    public static function vapidPublic(): string
    {
        return trim((string)Tools::settings(self::group(), self::SETTING_VAPID_PUBLIC, ''));
    }

    public static function pwaIconId(): int
    {
        return (int)Tools::settings(self::group(), self::SETTING_PWA_ICON_ID, '0');
    }

    /**
     * URL pública del icono PWA (custom o placeholder).
     * Con icono custom la misma URL sirve para 192 y 512 (el SO escala).
     *
     * @param '192'|'512' $size Solo afecta al fallback embebido.
     */
    public static function pwaIconUrl(string $size = '192'): string
    {
        $custom = trim((string)Tools::settings(self::group(), self::SETTING_PWA_ICON, ''));
        if ($custom !== '' && self::isSafePwaIconStoredPath($custom)) {
            return self::toPublicIconUrl($custom);
        }

        $rel = $size === '512' ? self::FALLBACK_ICON_512 : self::FALLBACK_ICON_192;

        return AuralisPwaPaths::url($rel);
    }

    public static function isSafePwaIconExtension(string $extension): bool
    {
        $ext = mb_strtolower(ltrim(trim($extension), '.'));

        return in_array($ext, self::PWA_ICON_EXTENSIONS, true);
    }

    public static function isSafePwaIconFilename(string $filename): bool
    {
        $base = basename(str_replace('\\', '/', $filename));
        $dot = mb_strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            return false;
        }

        return self::isSafePwaIconExtension(mb_substr($base, $dot + 1));
    }

    /**
     * Comprueba que la ruta/URL almacenada apunte a un raster permitido (sin SVG).
     */
    public static function isSafePwaIconStoredPath(string $stored): bool
    {
        $path = parse_url($stored, PHP_URL_PATH);
        $candidate = is_string($path) && $path !== '' ? $path : $stored;
        $candidate = rawurldecode($candidate);

        return self::isSafePwaIconFilename($candidate);
    }

    private static function toPublicIconUrl(string $stored): string
    {
        if (preg_match('#^https?://#i', $stored) === 1) {
            return $stored;
        }

        if (str_starts_with($stored, '/')) {
            return $stored;
        }

        return AuralisPwaPaths::url($stored);
    }

    /**
     * Material privado almacenado (cifrado enc:v2:…, o enc:v1:… antiguo). Nunca exponer al cliente ni loguear.
     */
    public static function vapidPrivateStored(): string
    {
        return trim((string)Tools::settings(self::group(), self::SETTING_VAPID_PRIVATE, ''));
    }

    /**
     * PEM privado VAPID descifrado en memoria. Vacío si legacy incompatible o corrupto.
     */
    public static function vapidPrivatePem(): string
    {
        $stored = self::vapidPrivateStored();
        if ($stored === '') {
            return '';
        }

        $opened = VapidSecretBox::open($stored);
        if ($opened !== '' && str_contains($opened, 'PRIVATE KEY')) {
            return $opened;
        }

        // Formato legacy (escalar d en base64url): incompatible con el flujo PEM → regenerar.
        return '';
    }

    /** @deprecated usar vapidPrivatePem(); no loguear el valor */
    public static function vapidPrivate(): string
    {
        return self::vapidPrivatePem();
    }

    public static function userSettingsGroup(string $nick): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $nick) ?? 'user';

        return 'auralis_user_' . $safe;
    }

    /**
     * Marca presencia del nick con throttle (~1 escritura/min).
     * Es por usuario: Ana online no afecta a Bob.
     */
    public static function touchPresence(string $nick): void
    {
        $nick = trim($nick);
        if ($nick === '') {
            return;
        }

        $group = self::userSettingsGroup($nick);
        $prev = (string)Tools::settings($group, self::SETTING_LAST_PING, '');
        $nowTs = time();
        if ($prev !== '') {
            $prevTs = strtotime($prev);
            if ($prevTs !== false && ($nowTs - $prevTs) < self::PING_WRITE_THROTTLE) {
                return;
            }
        }

        Tools::settingsSet($group, self::SETTING_LAST_PING, Tools::dateTime());
        Tools::settingsSave();
    }

    public static function isUserPresent(string $nick, ?int $nowTs = null): bool
    {
        $nick = trim($nick);
        if ($nick === '') {
            return false;
        }

        $prev = (string)Tools::settings(self::userSettingsGroup($nick), self::SETTING_LAST_PING, '');
        if ($prev === '') {
            return false;
        }

        $prevTs = strtotime($prev);
        if ($prevTs === false) {
            return false;
        }

        $nowTs = $nowTs ?? time();

        return ($nowTs - $prevTs) <= self::skipIfOnlineSeconds();
    }

    /**
     * Marca presencia del navegador que hace ping (columna last_ping_at de su suscripción).
     * Solo toca la fila con ese endpoint Y ese nick de sesión: si la suscripción no es suya, no hace nada.
     * Throttle PING_WRITE_THROTTLE: como mucho una escritura por minuto y dispositivo.
     * Nunca loguea el endpoint. Devuelve true solo si ha escrito.
     */
    public static function touchPresenceForSubscription(string $nick, string $endpoint, ?int $nowTs = null): bool
    {
        $nick = trim($nick);
        $endpoint = trim($endpoint);
        if ($nick === '' || $endpoint === '' || false === PushEndpointPolicy::assertSendable($endpoint)) {
            return false;
        }

        try {
            // Asegura la columna last_ping_at (DbUpdater, cacheado tras la primera vez).
            new AuralisPushSubscription();

            $fila = DbQuery::table(AuralisPushSubscription::tableName())
                ->select(AuralisPushSubscription::primaryColumn() . ', last_ping_at')
                ->whereEq('endpoint', $endpoint)
                ->whereEq('nick', $nick)
                ->first();
            $id = (int)($fila[AuralisPushSubscription::primaryColumn()] ?? 0);
            if ($id <= 0) {
                return false;
            }

            $nowTs = $nowTs ?? time();
            $prev = (string)($fila['last_ping_at'] ?? '');
            if ($prev !== '') {
                $prevTs = strtotime($prev);
                if ($prevTs !== false && ($nowTs - $prevTs) < self::PING_WRITE_THROTTLE) {
                    return false;
                }
            }

            return DbQuery::table(AuralisPushSubscription::tableName())
                ->whereEq(AuralisPushSubscription::primaryColumn(), $id)
                ->whereEq('nick', $nick)
                ->update(['last_ping_at' => date('Y-m-d H:i:s', $nowTs)]);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('auralis-push-ping-sub-fail');

            return false;
        }
    }

    /**
     * ¿Ese navegador tiene la campana viva (último ping dentro de skipIfOnlineSeconds)?
     */
    public static function isSubscriptionPresent(AuralisPushSubscription $sub, ?int $nowTs = null): bool
    {
        $prev = trim((string)($sub->last_ping_at ?? ''));
        if ($prev === '') {
            return false;
        }

        $prevTs = strtotime($prev);
        if ($prevTs === false) {
            return false;
        }

        $nowTs = $nowTs ?? time();

        return ($nowTs - $prevTs) <= self::skipIfOnlineSeconds();
    }

    /**
     * True si el nick tiene al menos una suscripción y TODAS están presentes.
     * Mismo conjunto que recorre PushSender (las maxSubsPerUser más recientes).
     * Sin suscripciones devuelve false: la presencia por nick ya no decide el push.
     */
    public static function allSubscriptionsPresent(string $nick, ?int $nowTs = null): bool
    {
        $nick = trim($nick);
        if ($nick === '') {
            return false;
        }

        $subs = AuralisPushSubscription::all(
            [Where::eq('nick', $nick)],
            ['created_at' => 'DESC'],
            0,
            self::maxSubsPerUser()
        );
        if ($subs === []) {
            return false;
        }

        $nowTs = $nowTs ?? time();
        foreach ($subs as $sub) {
            if (false === self::isSubscriptionPresent($sub, $nowTs)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda o actualiza suscripción; respeta techo de dispositivos por nick y política SSRF.
     */
    public static function saveSubscription(
        string $nick,
        string $endpoint,
        string $p256dh,
        string $auth,
        string $userAgent = ''
    ): bool {
        $nick = Tools::noHtml(trim($nick));
        $endpoint = trim($endpoint);
        $p256dh = trim($p256dh);
        $auth = trim($auth);
        if ($nick === '' || $endpoint === '' || $p256dh === '' || $auth === '') {
            return false;
        }

        if (false === PushEndpointPolicy::assertSendable($endpoint)) {
            Tools::log('Auralis')->warning('auralis-push-endpoint-rejected');

            return false;
        }

        if (false === PushEndpointPolicy::isValidSubscriptionKey($p256dh, $auth)) {
            return false;
        }

        // Solo navegador + SO (minimiza datos personales). Regex baratas: se llama en cada carga del ERP.
        $userAgent = self::reduceUserAgent($userAgent);

        $row = new AuralisPushSubscription();
        if ($row->loadWhere([Where::eq('endpoint', $endpoint)])) {
            // El JS reenvía la suscripción en cada carga: si nada cambió, sin escritura.
            if ((string)$row->nick === $nick
                && (string)$row->p256dh === $p256dh
                && (string)$row->auth === $auth
                && (string)$row->user_agent === $userAgent) {
                return true;
            }

            $row->nick = $nick;
            $row->p256dh = $p256dh;
            $row->auth = $auth;
            $row->user_agent = $userAgent;
            // Ya se escribe: de paso marca presencia (el alta llega desde un ERP abierto). Sin escritura extra.
            $row->last_ping_at = Tools::dateTime();

            return $row->save();
        }

        // Techo: purgar la más antigua si se supera el máximo.
        $existentes = AuralisPushSubscription::all(
            [Where::eq('nick', $nick)],
            ['created_at' => 'ASC'],
            0,
            100
        );
        $max = self::maxSubsPerUser();
        while (count($existentes) >= $max) {
            $vieja = array_shift($existentes);
            if ($vieja instanceof AuralisPushSubscription) {
                $vieja->delete();
            }
        }

        $nueva = new AuralisPushSubscription();
        $nueva->nick = $nick;
        $nueva->endpoint = $endpoint;
        $nueva->p256dh = $p256dh;
        $nueva->auth = $auth;
        $nueva->user_agent = $userAgent;
        $nueva->created_at = Tools::dateTime();
        $nueva->last_ping_at = $nueva->created_at;

        return $nueva->save();
    }

    public static function deleteSubscription(string $nick, string $endpoint): bool
    {
        $row = new AuralisPushSubscription();
        if (false === $row->loadWhere([
            Where::eq('nick', trim($nick)),
            Where::eq('endpoint', trim($endpoint)),
        ])) {
            return false;
        }

        return $row->delete();
    }

    /**
     * Reduce un user agent a «Navegador versión · Sistema» (p. ej. «Chrome 130 · Android»).
     * Solo regex sobre una cadena corta; sin consultas. No reconocido: «Navegador».
     */
    public static function reduceUserAgent(string $ua): string
    {
        $ua = mb_substr(trim($ua), 0, 250);
        if ($ua === '') {
            return self::USER_AGENT_UNKNOWN;
        }

        // Orden importa: Edge, Opera, Samsung, Firefox/iOS y Chrome/iOS llevan también «Chrome» o «Safari».
        $navegador = '';
        if (preg_match('#\b(?:Edg|EdgA|EdgiOS|Edge)/(\d+)#', $ua, $m) === 1) {
            $navegador = 'Edge ' . $m[1];
        } elseif (preg_match('#\bOPR/(\d+)#', $ua, $m) === 1) {
            $navegador = 'Opera ' . $m[1];
        } elseif (preg_match('#\bSamsungBrowser/(\d+)#', $ua, $m) === 1) {
            $navegador = 'Samsung Internet ' . $m[1];
        } elseif (preg_match('#\b(?:Firefox|FxiOS)/(\d+)#', $ua, $m) === 1) {
            $navegador = 'Firefox ' . $m[1];
        } elseif (preg_match('#\b(?:Chrome|CriOS)/(\d+)#', $ua, $m) === 1) {
            $navegador = 'Chrome ' . $m[1];
        } elseif (str_contains($ua, 'Safari/')) {
            $navegador = 'Safari';
        }

        if (preg_match('#Android#i', $ua) === 1) {
            $so = 'Android';
        } elseif (preg_match('#iPhone|iPad|iPod#', $ua) === 1) {
            $so = 'iOS';
        } elseif (str_contains($ua, 'Windows')) {
            $so = 'Windows';
        } elseif (str_contains($ua, 'CrOS')) {
            $so = 'ChromeOS';
        } elseif (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) {
            $so = 'macOS';
        } elseif (str_contains($ua, 'Linux')) {
            $so = 'Linux';
        } else {
            $so = '';
        }

        if ($navegador === '' && $so === '') {
            return self::USER_AGENT_UNKNOWN;
        }

        $reducido = trim(($navegador !== '' ? $navegador : self::USER_AGENT_UNKNOWN) . ($so !== '' ? ' · ' . $so : ''));

        return mb_substr($reducido, 0, self::USER_AGENT_MAX_LENGTH);
    }

    /**
     * Borra las suscripciones sin envío correcto en SUBSCRIPTION_TTL_DAYS días, contando desde
     * COALESCE(last_success_at, created_at). Devuelve cuántas se han borrado. Solo recuento en el log.
     */
    public static function purgeExpiredSubscriptions(?int $nowTs = null): int
    {
        $nowTs = $nowTs ?? time();
        $corte = date('Y-m-d H:i:s', $nowTs - (self::SUBSCRIPTION_TTL_DAYS * 86400));

        // (last_success_at no nulo Y < corte) OR (last_success_at nulo Y created_at < corte)
        $where = [
            Where::sub([
                Where::sub([
                    Where::isNotNull('last_success_at'),
                    Where::lt('last_success_at', $corte),
                ]),
                Where::sub([
                    Where::isNull('last_success_at'),
                    Where::lt('created_at', $corte),
                ], 'OR'),
            ]),
        ];

        $total = AuralisPushSubscription::count($where);
        if ($total <= 0) {
            return 0;
        }

        DbQuery::table(AuralisPushSubscription::tableName())->where($where)->delete();
        Tools::log('Auralis')->info('auralis-push-subscriptions-purged-' . $total);

        return $total;
    }

    public static function countSubscriptions(?string $nick = null): int
    {
        $where = [];
        if ($nick !== null && trim($nick) !== '') {
            $where[] = Where::eq('nick', trim($nick));
        }

        return AuralisPushSubscription::count($where);
    }

    public static function ensureDefaults(): void
    {
        $g = self::group();
        $defaults = [
            self::SETTING_PUSH_ENABLED => '0',
            self::SETTING_SKIP_ONLINE => (string)self::DEFAULT_SKIP_ONLINE,
            self::SETTING_BATCH_LIMIT => (string)self::DEFAULT_BATCH_LIMIT,
            self::SETTING_MAX_SUBS => (string)self::DEFAULT_MAX_SUBS,
            self::SETTING_MAX_RETRY_HOURS => (string)self::DEFAULT_MAX_RETRY_HOURS,
        ];
        foreach ($defaults as $key => $val) {
            if (Tools::settings($g, $key) === null) {
                Tools::settingsSet($g, $key, $val);
            }
        }
    }

    /**
     * Genera VAPID, cifra el PEM privado en reposo y registra auditoría (sin el secreto).
     */
    public static function generateAndStoreVapid(?string $adminNick = null): bool
    {
        if (false === PushCryptoCapability::isReady()) {
            return false;
        }

        $keys = VapidCredentials::generate();
        if ($keys === null) {
            return false;
        }

        $sealed = VapidSecretBox::seal($keys['privatePem']);
        if ($sealed === '' || false === VapidSecretBox::isSealed($sealed)) {
            return false;
        }

        Tools::settingsSet(self::group(), self::SETTING_VAPID_PUBLIC, $keys['publicKey']);
        Tools::settingsSet(self::group(), self::SETTING_VAPID_PRIVATE, $sealed);
        Tools::settingsSave();

        $who = $adminNick !== null && $adminNick !== ''
            ? $adminNick
            : 'system';
        Tools::log('Auralis')->notice('auralis-vapid-regenerated-by-' . Tools::noHtml($who));

        return true;
    }
}

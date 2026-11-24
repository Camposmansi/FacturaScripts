<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Helpers de saneo (fechas, empresa, logs, imágenes públicas y URL canónica).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\AppKey;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Controller\Login as CoreLogin;
use FacturaScripts\Core\Model\LogMessage;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

final class AuralisSecurity
{
    /** Tipografías empaquetadas en el plugin (+ sistema). */
    public const TIPOGRAFIAS_LOCALES = [
        'inter',
        'lato',
        'merriweather',
        'montserrat',
        'nunito',
        'open_sans',
        'outfit',
        'plus_jakarta_sans',
        'poppins',
        'roboto',
        'sistema',
    ];

    /** Extensiones permitidas en logos, fondos e imágenes visibles públicamente. */
    public const PUBLIC_IMAGE_ACCEPT = '.png,.jpg,.jpeg,.gif,.webp,.ico,.bmp,.avif,.svg';

    /** @var string[] */
    public const PUBLIC_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif', 'svg'];

    /** Tamaño máximo (bytes) de una imagen pública subida o elegida de la Biblioteca. */
    public const MAX_IMAGEN_PUBLICA_BYTES = 5 * 1024 * 1024;

    /**
     * MIME permitidos por extensión para las imágenes públicas.
     * El contenido SVG se somete además a una validación XML restrictiva.
     *
     * @var array<string, string[]>
     */
    private const PUBLIC_IMAGE_MIMES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'avif' => ['image/avif'],
        'svg' => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'],
    ];

    /**
     * URL absoluta de una página pública del ERP (reset de contraseña, etc.).
     * Devuelve '' si site_url no está configurada o no es válida: nunca usa la cabecera Host.
     *
     * @param array<string, scalar> $query
     */
    public static function buildPublicPageUrl(string $page, array $query = []): string
    {
        $page = trim($page, '/');
        $base = rtrim((string) Tools::settings('default', 'site_url', ''), '/');
        if ($base === '') {
            return '';
        }

        $parts = parse_url($base);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || preg_match('/[^a-z0-9.\-]/i', $host) === 1
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/[\\\\\s]/', $base) === 1
        ) {
            return '';
        }

        $url = $base . '/' . $page;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Usuario no admin con empresa asignada: no puede ver ni elegir otras empresas.
     */
    public static function isEmpresaLocked(?object $user): bool
    {
        if ($user === null) {
            return false;
        }

        $admin = property_exists($user, 'admin') ? (bool) $user->admin : false;
        $idempresa = property_exists($user, 'idempresa') ? $user->idempresa : null;

        return false === $admin && !empty($idempresa);
    }

    /**
     * La auditoría de login/2FA solo aplica en POST HTTP real.
     * En CLI/cron no existe REQUEST_METHOD y Request::method() del Core lanza TypeError.
     *
     * @param string|null $sapi Override de PHP_SAPI (tests); null = runtime real.
     * @param array<string, mixed>|null $server Override de $_SERVER (tests); null = $_SERVER real.
     */
    public static function isHttpContextForLoginAudit(?string $sapi = null, ?array $server = null): bool
    {
        $sapi ??= PHP_SAPI;
        $server ??= $_SERVER;

        if ($sapi === 'cli') {
            return false;
        }

        $method = $server['REQUEST_METHOD'] ?? '';

        return is_string($method) && $method !== '' && strtoupper($method) === 'POST';
    }

    /**
     * Detecta bloqueo de login del Core: MiniLog (si aún no se vació) o listas en caché.
     */
    public static function isLoginBanned(string $ip, string $nick = ''): bool
    {
        foreach (Tools::log()->read('master', ['warning']) as $msg) {
            if (($msg['message'] ?? '') === 'ip-banned') {
                return true;
            }
        }

        $now = time();
        $window = CoreLogin::INCIDENT_EXPIRATION_TIME;
        $max = CoreLogin::MAX_INCIDENT_COUNT;

        $ipCount = 0;
        $ipList = Cache::get(CoreLogin::IP_LIST);
        if (is_array($ipList)) {
            foreach ($ipList as $item) {
                if (($item['ip'] ?? '') === $ip
                    && ($now - (int) ($item['time'] ?? 0)) < $window
                ) {
                    ++$ipCount;
                }
            }
        }
        if ($ipCount >= $max) {
            return true;
        }

        if ($nick === '') {
            return false;
        }

        $userCount = 0;
        $userList = Cache::get(CoreLogin::USER_LIST);
        if (is_array($userList)) {
            foreach ($userList as $item) {
                if (($item['user'] ?? '') === $nick
                    && ($now - (int) ($item['time'] ?? 0)) < $window
                ) {
                    ++$userCount;
                }
            }
        }

        return $userCount >= $max;
    }

    /**
     * True si el Core dejó pendiente la validación 2FA tras contraseña correcta.
     * No incrementa incidentes de bloqueo (el Core tampoco lo hace en ese paso).
     */
    public static function isTwoFactorPending(string $ip, string $nick): bool
    {
        $nick = trim($nick);
        if ($nick === '') {
            return false;
        }

        $key = 'login-2fa-pending-' . sha1($ip . '|' . $nick);
        $pendingAt = Cache::get($key);

        return is_int($pendingAt) && (time() - $pendingAt) <= CoreLogin::TWO_FACTOR_PENDING_TTL;
    }

    /**
     * Omite la auditoría en el paso intermedio login→2FA (contraseña OK, sin sesión aún).
     */
    public static function shouldSkipLoginAudit(
        string $action,
        ?User $sessionUser,
        string $ip,
        string $nick
    ): bool {
        if ($action !== 'login') {
            return false;
        }

        if ($sessionUser && $sessionUser->nick) {
            return false;
        }

        return self::isTwoFactorPending($ip, $nick);
    }

    /**
     * Persiste la fila de auditoría de login/2FA (channel=audit). Requiere BD conectada.
     */
    public static function persistLoginAudit(
        string $action,
        string $nick,
        ?User $sessionUser,
        string $ip,
        string $agent,
        bool $isBlocked
    ): bool {
        $log = new LogMessage();
        $log->channel = 'audit';
        $log->ip = $ip;
        $log->time = Tools::dateTime();

        if ($sessionUser && $sessionUser->nick) {
            $log->level = 'notice';
            $log->message = $action === 'two-factor-validation'
                ? 'Inicio de sesión exitoso (2FA)'
                : 'Inicio de sesión exitoso';
            $log->nick = self::sanitizeLogLabel((string) $sessionUser->nick);
            $log->context = json_encode(['ip' => $ip, 'agent' => $agent]);
        } elseif ($isBlocked) {
            $log->level = 'critical';
            $log->message = 'Acceso al sistema bloqueado por intentos de login excesivos';
            $log->nick = self::sanitizeLogLabel($nick);
            $log->context = json_encode(['ip' => $ip, 'agent' => $agent]);
        } else {
            $log->level = 'warning';
            $log->message = $action === 'two-factor-validation'
                ? 'Código 2FA incorrecto o expirado'
                : 'Intento de inicio de sesión fallido';
            $log->nick = self::sanitizeLogLabel($nick);
            $log->context = json_encode(['ip' => $ip, 'agent' => $agent]);
        }

        return $log->save();
    }

    /**
     * Valor a marcar en el selector de tipografía: locales empaquetadas o «sistema».
     */
    public static function tipografiaSelectValue(string $saved): string
    {
        return in_array($saved, self::TIPOGRAFIAS_LOCALES, true) ? $saved : 'sistema';
    }

    public static function isSafePublicImageExtension(string $extension): bool
    {
        $ext = strtolower(ltrim(trim($extension), '.'));

        return in_array($ext, self::PUBLIC_IMAGE_EXTENSIONS, true);
    }

    public static function isSafePublicImageFilename(string $filename): bool
    {
        $base = basename(str_replace('\\', '/', $filename));
        $dot = mb_strrpos($base, '.');
        if ($dot === false) {
            return false;
        }

        return self::isSafePublicImageExtension(mb_substr($base, $dot + 1));
    }

    /**
     * Valida el contenido real de una imagen pública ya guardada en disco: extensión permitida,
     * tamaño razonable, MIME detectado coherente con la extensión y, salvo ico/avif, que sea
     * una imagen decodificable. No se fía del nombre ni del tipo que manda el navegador.
     */
    public static function esImagenPublicaSegura(string $ruta, string $extension): bool
    {
        $ext = mb_strtolower(ltrim(trim($extension), '.'));
        if (false === in_array($ext, self::PUBLIC_IMAGE_EXTENSIONS, true) || false === isset(self::PUBLIC_IMAGE_MIMES[$ext])) {
            return false;
        }

        if ($ruta === '' || false === is_file($ruta)) {
            return false;
        }

        $tamano = @filesize($ruta);
        if ($tamano === false || $tamano <= 0 || $tamano > self::MAX_IMAGEN_PUBLICA_BYTES) {
            return false;
        }

        $mime = @mime_content_type($ruta);
        if (false === is_string($mime) || false === in_array($mime, self::PUBLIC_IMAGE_MIMES[$ext], true)) {
            return false;
        }

        if ($ext === 'svg') {
            return self::esSvgPublicoSeguro($ruta);
        }

        // ico y avif no siempre los reconoce getimagesize(); el MIME detectado es la comprobación.
        if (in_array($ext, ['ico', 'avif'], true)) {
            return true;
        }

        return @getimagesize($ruta) !== false;
    }

    /**
     * Valida SVG para uso público sin ejecutar ni reescribir su contenido.
     * Solo admite SVG estático: sin scripts, eventos, estilos incrustados,
     * documentos embebidos, animación ni referencias de red.
     */
    private static function esSvgPublicoSeguro(string $ruta): bool
    {
        $contenido = @file_get_contents($ruta);
        if (false === is_string($contenido) || $contenido === '' || str_contains($contenido, "\0")) {
            return false;
        }

        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?xml-stylesheet/i', $contenido) === 1) {
            return false;
        }

        $estadoErrores = libxml_use_internal_errors(true);
        $documento = new \DOMDocument();
        $cargado = $documento->loadXML(
            $contenido,
            LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($estadoErrores);

        if (false === $cargado || null === $documento->documentElement) {
            return false;
        }

        $raiz = $documento->documentElement;
        if (mb_strtolower($raiz->localName) !== 'svg'
            || false === in_array((string) $raiz->namespaceURI, ['', 'http://www.w3.org/2000/svg'], true)
        ) {
            return false;
        }

        $elementosProhibidos = [
            'animate', 'animatemotion', 'animatetransform', 'audio', 'canvas', 'embed',
            'foreignobject', 'iframe', 'link', 'mpath', 'object', 'script', 'set', 'style', 'video',
        ];

        foreach ($documento->getElementsByTagName('*') as $elemento) {
            if (in_array(mb_strtolower($elemento->localName), $elementosProhibidos, true)) {
                return false;
            }

            foreach ($elemento->attributes ?? [] as $atributo) {
                $nombre = mb_strtolower($atributo->localName);
                $valor = trim($atributo->value);
                $valorMinusculas = mb_strtolower($valor);

                if (str_starts_with($nombre, 'on') || $nombre === 'style') {
                    return false;
                }

                if (in_array($nombre, ['href', 'src'], true)) {
                    $esFragmento = str_starts_with($valor, '#');
                    $esRasterEmbebido = preg_match('/^data:image\/(?:png|jpe?g|gif|webp);base64,[a-z0-9+\/=\s]+$/i', $valor) === 1;
                    if (false === $esFragmento && false === $esRasterEmbebido) {
                        return false;
                    }
                }

                if (preg_match('/(?:javascript|vbscript|data:text\/html|@import|expression\s*\()/i', $valorMinusculas) === 1
                    || preg_match('/url\s*\(\s*[\'\"]?(?!#)/i', $valor) === 1
                ) {
                    return false;
                }
            }
        }

        foreach ($documento->childNodes as $nodo) {
            if ($nodo->nodeType === XML_PI_NODE || $nodo->nodeType === XML_DOCUMENT_TYPE_NODE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Usuario no admin con empresa: solo su empresa.
     * Eventos sin empresa (legado) siguen siendo accesibles.
     *
     * @param int|string|null $idempresa
     */
    public static function canAccessEmpresa(?object $user, $idempresa): bool
    {
        if (false === self::isEmpresaLocked($user)) {
            return true;
        }

        $eventEmp = (int) $idempresa;

        return $eventEmp <= 0 || $eventEmp === (int) $user->idempresa;
    }

    /**
     * Empresa efectiva para consultas del dashboard/calendario.
     * Cadena vacía = todas (solo admin o usuario sin empresa asignada).
     */
    public static function resolveCodEmpresa(?object $user, string $requested): string
    {
        if (self::isEmpresaLocked($user)) {
            return (string) (int) $user->idempresa;
        }

        $requested = trim($requested);
        if ($requested !== '' && is_numeric($requested)) {
            return (string) (int) $requested;
        }

        return '';
    }

    /**
     * @return array{0: string, 1: string} [desde, hasta] en Y-m-d
     */
    public static function sanitizeDateRange(string $desde, string $hasta, int $maxDays = 62): array
    {
        $desdeIso = self::sanitizeIsoDate($desde) ?? date('Y-m-d');
        $hastaIso = self::sanitizeIsoDate($hasta) ?? date('Y-m-d');

        $start = new \DateTime($desdeIso);
        $end = new \DateTime($hastaIso);
        if ($end < $start) {
            $end = clone $start;
        }

        $maxDays = max(1, $maxDays);
        if ((int) $start->diff($end)->days > $maxDays) {
            $end = (clone $start)->modify('+' . $maxDays . ' days');
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    public static function sanitizeIsoDate(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if ($year < 1990 || $year > 2100 || false === checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public static function sanitizeLogLabel(string $value, int $max = 50): string
    {
        $max = max(1, $max);

        return mb_substr(Tools::noHtml(trim($value)), 0, $max);
    }

    /**
     * Enmascara un correo electrónico para trazabilidad en logs minimizando PII (RGPD).
     * Ejemplo:  → n***e@d***.com
     */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '***';
        }

        $parts = explode('@', $email, 2);
        $user = $parts[0];
        $domain = $parts[1] ?? '';

        $userLen = mb_strlen($user);
        if ($userLen <= 1) {
            $maskedUser = '***';
        } elseif ($userLen === 2) {
            $maskedUser = mb_substr($user, 0, 1) . '***';
        } else {
            $maskedUser = mb_substr($user, 0, 1) . '***' . mb_substr($user, -1);
        }

        $dot = mb_strrpos($domain, '.');
        if ($dot === false || $dot === 0) {
            $maskedDomain = mb_substr($domain, 0, 1) . '***';
        } else {
            $name = mb_substr($domain, 0, $dot);
            $tld = mb_substr($domain, $dot);
            $maskedDomain = mb_substr($name, 0, 1) . '***' . $tld;
        }

        return $maskedUser . '@' . $maskedDomain;
    }

    /**
     * Clave simétrica de 32 bytes (binaria) propia de esta instalación para un propósito concreto.
     * El propósito separa las claves de cada uso (VAPID, reset de contraseña…).
     * Nunca loguear el resultado.
     */
    public static function claveInstalacion(string $proposito): string
    {
        return hash_hmac('sha256', $proposito, self::baseClaveActual(), true);
    }

    /**
     * Claves válidas para descifrar, en orden: primero la de la base actual y después la de la
     * base derivada. Así, si se añade FS_APP_KEY al config.php más tarde, lo cifrado antes
     * con la base derivada se sigue pudiendo abrir.
     *
     * @return list<string>
     */
    public static function clavesInstalacionCandidatas(string $proposito): array
    {
        $claves = [
            self::claveInstalacion($proposito),
            hash_hmac('sha256', $proposito, self::baseClaveDerivada(), true),
        ];

        return array_values(array_unique($claves));
    }

    /**
     * Base actual: FS_APP_KEY a través del Core (2026.7+, que ya cae a la derivada si falta)
     * o, en Core anteriores, la derivada.
     */
    private static function baseClaveActual(): string
    {
        if (class_exists(AppKey::class)) {
            $base = (string)AppKey::get();
            if ($base !== '') {
                return $base;
            }
        }

        return self::baseClaveDerivada();
    }

    /**
     * Réplica exacta de AppKey::get() en modo derivado (Core 2026.7) para Core < 2026.7,
     * donde AppKey no existe. Si cambia la fórmula del Core, revisar aquí.
     */
    private static function baseClaveDerivada(): string
    {
        return hash('sha256', implode('|', [
            'facturascripts',
            FS_FOLDER,
            Tools::config('db_host', ''),
            Tools::config('db_name', ''),
            Tools::config('db_user', ''),
            Tools::config('db_pass', ''),
        ]));
    }
}

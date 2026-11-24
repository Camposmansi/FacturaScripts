<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Registro idempotente de funciones Twig de campana/novedades (cubre carrera enable/deploy).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Html;
use FacturaScripts\Core\Session;
use Twig\TwigFunction;

final class AuralisTwigFunctions
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Html::addFunction(new TwigFunction('auralisUnreadNotifications', static function (): int {
            $user = Session::user();
            if (empty($user->nick)) {
                return 0;
            }

            return 0;
        }));

        // Permisos del usuario en la página destino de un botón del modal: {ver, editar}.
        Html::addFunction(new TwigFunction('auralisPermisosPagina', static function (string $pagina): array {
            return AuralisPermisosPagina::de($pagina);
        }));

        Html::addFunction(new TwigFunction('auralisHasUnreadNews', static function (): bool {
            $user = Session::user();
            if (empty($user->nick)) {
                return false;
            }

            return AuralisChangelogReader::hasUnreadNews((string)$user->nick);
        }));

        Html::addFunction(new TwigFunction('auralisPluginVersion', static function (): string {
            return self::versionPlugin();
        }));

        // Versión estable (cache-bust) de un asset público: filemtime() o, si no es posible, versión del plugin.
        Html::addFunction(new TwigFunction('auralisAssetVersion', static function (string $ruta): string {
            return self::versionAsset($ruta);
        }));

        // Pie configurable: texto libre y enlaces HTTPS/HTTP, sin permitir HTML ejecutable.
        Html::addFunction(new TwigFunction('auralisFooterHtml', static function (string $texto): string {
            return self::footerHtml($texto);
        }, ['is_safe' => ['html']]));
    }

    /**
     * Convierte el pie configurable en HTML seguro. Tolera entidades HTML y las
     * barras invertidas que algunos editores añaden a las URL. Todo elemento
     * distinto de <a> se presenta como texto; los enlaces se reconstruyen.
     */
    public static function footerHtml(string $texto): string
    {
        $texto = str_replace('{year}', date('Y'), $texto);
        for ($i = 0; $i < 2; $i++) {
            $decodificado = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decodificado === $texto) {
                break;
            }
            $texto = $decodificado;
        }

        $texto = preg_replace('/\\\\([:\/.?#=&_%\-])/', '$1', $texto) ?? $texto;
        $partes = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === is_array($partes)) {
            return nl2br(htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $html = '';
        foreach ($partes as $parte) {
            if (preg_match('/^<a\b([^>]*)>(.*?)<\/a>$/is', $parte, $coincidencia) !== 1) {
                $html .= nl2br(htmlspecialchars($parte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                continue;
            }

            $atributos = $coincidencia[1];
            $etiqueta = trim(strip_tags($coincidencia[2]));
            if ($etiqueta === '' || preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $atributos, $hrefMatch) !== 1) {
                $html .= htmlspecialchars(strip_tags($parte), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            $href = html_entity_decode(trim($hrefMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $href = preg_replace('/\\\\([:\/.?#=&_%\-])/', '$1', $href) ?? $href;
            $url = parse_url($href);
            $esSeguro = is_array($url)
                && in_array(mb_strtolower((string)($url['scheme'] ?? '')), ['http', 'https'], true)
                && false === empty($url['host'])
                && false === isset($url['user'])
                && false === isset($url['pass']);

            if (false === $esSeguro) {
                $html .= htmlspecialchars($etiqueta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($etiqueta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }

        return $html;
    }

    /**
     * Versión estable de un asset bajo Dinamic/Assets/ o Plugins/Auralis/Assets/.
     * Devuelve siempre una cadena alfanumérica (mtime entero o versión saneada).
     */
    public static function versionAsset(string $ruta, ?string $raiz = null): string
    {
        static $cache = [];

        $raiz ??= FS_FOLDER;
        $clave = $raiz . '|' . $ruta;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $valida = $ruta !== ''
            && false === strpos($ruta, "\0")
            && false === strpos($ruta, '..')
            && false === strpos($ruta, '\\')
            && $ruta[0] !== '/'
            && 1 !== preg_match('#^[A-Za-z]:#', $ruta)
            && (0 === strpos($ruta, 'Dinamic/Assets/') || 0 === strpos($ruta, 'Plugins/Auralis/Assets/'));

        $mtime = false;
        if ($valida) {
            $archivo = $raiz . '/' . $ruta;
            $mtime = is_file($archivo) ? @filemtime($archivo) : false;
        }

        return $cache[$clave] = (false !== $mtime) ? (string)(int)$mtime : self::versionPlugin();
    }

    /**
     * Core Plugin::version usa floatval, "1.40" se vería como "1.4".
     * Se lee el .ini en RAW para preservar el string canónico.
     */
    private static function versionPlugin(): string
    {
        $ini = FS_FOLDER . '/Plugins/Auralis/facturascripts.ini';
        if (is_file($ini)) {
            $data = parse_ini_file($ini, false, INI_SCANNER_RAW);
            if (is_array($data) && isset($data['version'])) {
                $version = preg_replace('/[^A-Za-z0-9.]/', '', trim((string)$data['version'], " \t\"'"));
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            }
        }

        return '1.44';
    }

    /**
     * Solo para tests: permite re-registrar en el mismo proceso PHPUnit.
     */
    public static function resetForTests(): void
    {
        self::$registered = false;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Sanitiza HTML controlado para listados: escape total y reapertura
 *              solo de etiquetas básicas sin atributos (mitiga XSS almacenado).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

class HtmlSeguro
{
    /**
     * Permite strong|em|b|i|br sin atributos. Pensado para celdas de listado
     * (sin p/ul/li, que pueden romper el layout de la tabla).
     */
    public static function limpiar(string $html): string
    {
        $escaped = htmlspecialchars(
            html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ENT_QUOTES,
            'UTF-8'
        );

        return preg_replace(
            '/&lt;(\/?)(strong|em|b|i|br)\s*(\/?)&gt;/i',
            '<$1$2$3>',
            $escaped
        ) ?? $escaped;
    }
}

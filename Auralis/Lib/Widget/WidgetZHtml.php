<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Widget de listado que muestra HTML controlado (allowlist) en celdas.
 *              Tipo XML: zHtml. No usar para HTML libre de usuario sin sanitizar.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\WidgetText;
use FacturaScripts\Plugins\Auralis\Lib\HtmlSeguro;

class WidgetZHtml extends WidgetText
{
    /**
     * @return string
     */
    protected function show()
    {
        if (null === $this->value || '' === (string) $this->value) {
            return '-';
        }

        return HtmlSeguro::limpiar((string) $this->value);
    }
}

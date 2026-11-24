<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Respuestas JSON AJAX del calendario con token CSRF renovado (multireqtoken de un solo uso).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Response;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;

final class CalendarAjaxResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function json(Response $response, array $payload, int $status = 200): void
    {
        $payload['newToken'] = (new MultiRequestProtection())->newToken();
        $response->json($payload, $status);
    }
}

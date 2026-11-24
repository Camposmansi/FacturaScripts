<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Mod de cabecera de ventas que muestra avisos internos Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Mod;

use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Plugins\Auralis\Lib\AvisosDocumento;

class AuralisAvisosSalesMod implements SalesModInterface
{
    public function apply(SalesDocument &$model, array $formData): void
    {
    }

    public function applyBefore(SalesDocument &$model, array $formData): void
    {
    }

    public function assets(): void
    {
        $route = Tools::config('route');
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/auralis-avisos.css?v=1.17');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/auralis-avisos.js?v=1.17');
    }

    public function newBtnFields(): array
    {
        return [];
    }

    public function newFields(): array
    {
        return ['_auralis_avisos'];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function renderField(SalesDocument $model, string $field): ?string
    {
        if ($field !== '_auralis_avisos') {
            return null;
        }

        $payload = AvisosDocumento::recogerVentas($model);
        return AvisosDocumento::renderHtml($payload, 'customer');
    }
}

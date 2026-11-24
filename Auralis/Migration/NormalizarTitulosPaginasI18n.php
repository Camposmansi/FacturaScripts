<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Normaliza títulos de páginas Auralis a claves i18n en fs_pages.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Plugins\Auralis\Lib\MenuManager;

class NormalizarTitulosPaginasI18n extends MigrationClass
{
    public const MIGRATION_NAME = 'normalizar_titulos_paginas_i18n_v2026';

    /** @var array<string, list<string>> */
    private const PAGE_TITLE_MAP = [
        'AuralisResetPassword' => ['Restablecer Contraseña', 'restablecer-contrasena'],
        'DashBoardAuralis' => ['Panel de Control', 'dashboard-auralis', 'general-dashboard'],
    ];

    public function run(): void
    {
        foreach (self::PAGE_TITLE_MAP as $pageName => $fromTitles) {
            $page = new Page();
            if (false === $page->load($pageName)) {
                continue;
            }

            $targetKey = end($fromTitles);
            if (!is_string($targetKey)) {
                continue;
            }

            if (!in_array($page->title, $fromTitles, true) && $page->title !== $targetKey) {
                continue;
            }

            if ($page->title === $targetKey) {
                continue;
            }

            $page->title = $targetKey;
            $page->save();
        }

        MenuManager::clearCache();
    }
}

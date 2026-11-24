<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Agrupa páginas nativas del menú Administrador bajo la subpestaña virtual General.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Lib\MenuItem;
use FacturaScripts\Core\Tools;

class AdminGeneralMenuGrouper
{
    public const SUBMENU_KEY = 'general';

    public const SETTING_KEY = 'agrupar_admin_general';

    /** @var string[] */
    public const PAGINAS_CORE = [
        'About',
        'Updater',
        'ListAgente',
        'ListAttachedFile',
        'ConfigEmail',
        'ListEmpresa',
        'ListLogMessage',
        'EditSettings',
        'ListPais',
        'ListUser',
        'AdminPlugins',
    ];

    public static function isEnabled(): bool
    {
        return (bool) Tools::settings('auralis', self::SETTING_KEY, true);
    }

    public static function containsPage(string $pageName): bool
    {
        return in_array($pageName, self::PAGINAS_CORE, true);
    }

    /**
     * Reorganiza hijos directos del menú admin (MenuItem[]) bajo el nodo virtual general.
     *
     * @param MenuItem[] $adminChildren
     */
    public static function apply(array &$adminChildren): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (isset($adminChildren[self::SUBMENU_KEY]) && self::isVirtualGroup($adminChildren[self::SUBMENU_KEY])) {
            return;
        }

        $toMove = [];
        foreach (self::PAGINAS_CORE as $pageName) {
            if (!isset($adminChildren[$pageName])) {
                continue;
            }

            $toMove[$pageName] = $adminChildren[$pageName];
            unset($adminChildren[$pageName]);
        }

        if ($toMove === []) {
            return;
        }

        $adminChildren[self::SUBMENU_KEY] = new MenuItem(
            self::SUBMENU_KEY,
            Tools::trans(self::SUBMENU_KEY),
            '#',
            'fa-solid fa-folder-open'
        );

        foreach ($toMove as $pageName => $item) {
            $adminChildren[self::SUBMENU_KEY]->menu[$pageName] = $item;
        }
    }

    /**
     * Misma agrupación sobre el árbol en array asociativo (ConfigAuralis / ordenación).
     *
     * @param array<string, array<string, mixed>> $adminChildren
     */
    public static function applyToTree(array &$adminChildren): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (isset($adminChildren[self::SUBMENU_KEY]) && self::isVirtualTreeGroup($adminChildren[self::SUBMENU_KEY])) {
            return;
        }

        $toMove = [];
        foreach (self::PAGINAS_CORE as $pageName) {
            if (!isset($adminChildren[$pageName])) {
                continue;
            }

            $toMove[$pageName] = $adminChildren[$pageName];
            unset($adminChildren[$pageName]);
        }

        if ($toMove === []) {
            return;
        }

        $adminChildren[self::SUBMENU_KEY] = [
            'name' => self::SUBMENU_KEY,
            'title' => Tools::trans(self::SUBMENU_KEY),
            'icon' => 'fa-solid fa-folder-open',
            'menu' => [],
        ];

        foreach ($toMove as $pageName => $item) {
            $adminChildren[self::SUBMENU_KEY]['menu'][$pageName] = $item;
        }
    }

    private static function isVirtualGroup(MenuItem $item): bool
    {
        return $item->name === self::SUBMENU_KEY
            && $item->url === '#'
            && !empty($item->menu);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isVirtualTreeGroup(array $node): bool
    {
        return ($node['name'] ?? '') === self::SUBMENU_KEY
            && !empty($node['menu']);
    }
}

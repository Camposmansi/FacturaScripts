<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Agrupa las páginas del plugin Informes bajo un submenú virtual (compatible, no require).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Lib\MenuItem;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

class InformesMenuGrouper
{
    public const PLUGIN_NAME = 'Informes';

    public const MENU_KEY = 'reports';

    public const SUBMENU_KEY = 'informes';

    public const SETTING_KEY = 'agrupar_informes_menu';

    /** @internal Solo tests. */
    public static ?bool $pluginActiveOverride = null;

    /**
     * @internal Solo tests.
     * @var list<string>|null
     */
    public static ?array $pageNamesOverride = null;

    /** @var list<string>|null */
    private static ?array $pageNamesCache = null;

    public static function isPluginActive(): bool
    {
        if (self::$pluginActiveOverride !== null) {
            return self::$pluginActiveOverride;
        }

        if (!class_exists(Plugins::class)) {
            return false;
        }

        try {
            return Plugins::isEnabled(self::PLUGIN_NAME);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isEnabled(): bool
    {
        return self::isPluginActive()
            && (bool) Tools::settings('auralis', self::SETTING_KEY, true);
    }

    public static function containsPage(string $pageName): bool
    {
        return in_array($pageName, self::pluginPageNames(), true);
    }

    /**
     * Nombres de controladores del plugin Informes (descubrimiento por ficheros).
     *
     * @return list<string>
     */
    public static function pluginPageNames(): array
    {
        if (self::$pageNamesOverride !== null) {
            return self::$pageNamesOverride;
        }

        if (self::$pageNamesCache !== null) {
            return self::$pageNamesCache;
        }

        $dir = self::informesControllerDir();
        if ($dir === '' || !is_dir($dir)) {
            self::$pageNamesCache = [];
            return self::$pageNamesCache;
        }

        $names = [];
        $files = @scandir($dir);
        if (!is_array($files)) {
            self::$pageNamesCache = [];
            return self::$pageNamesCache;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.php')) {
                continue;
            }
            $name = basename($file, '.php');
            if ($name === '' || str_contains($name, '.')) {
                continue;
            }
            $names[] = $name;
        }

        sort($names);
        self::$pageNamesCache = $names;

        return self::$pageNamesCache;
    }

    /** @internal Solo tests. */
    public static function clearPageNamesCache(): void
    {
        self::$pageNamesCache = null;
        self::$pluginActiveOverride = null;
        self::$pageNamesOverride = null;
    }

    /**
     * Reorganiza hijos directos del menú reports bajo el nodo virtual informes.
     *
     * @param MenuItem[] $reportsChildren
     */
    public static function apply(array &$reportsChildren): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (isset($reportsChildren[self::SUBMENU_KEY]) && self::isVirtualGroup($reportsChildren[self::SUBMENU_KEY])) {
            return;
        }

        $toMove = [];
        foreach (self::pluginPageNames() as $pageName) {
            if (!isset($reportsChildren[$pageName])) {
                continue;
            }

            $toMove[$pageName] = $reportsChildren[$pageName];
            unset($reportsChildren[$pageName]);
        }

        if ($toMove === []) {
            return;
        }

        $reportsChildren[self::SUBMENU_KEY] = new MenuItem(
            self::SUBMENU_KEY,
            Tools::trans('auralis-informes-submenu'),
            '#',
            'fa-solid fa-folder-open'
        );

        foreach ($toMove as $pageName => $item) {
            $reportsChildren[self::SUBMENU_KEY]->menu[$pageName] = $item;
        }
    }

    /**
     * Misma agrupación sobre el árbol en array asociativo (ConfigAuralis).
     *
     * @param array<string, array<string, mixed>> $reportsChildren
     */
    public static function applyToTree(array &$reportsChildren): void
    {
        if (!self::isEnabled()) {
            return;
        }

        if (isset($reportsChildren[self::SUBMENU_KEY]) && self::isVirtualTreeGroup($reportsChildren[self::SUBMENU_KEY])) {
            return;
        }

        $toMove = [];
        foreach (self::pluginPageNames() as $pageName) {
            if (!isset($reportsChildren[$pageName])) {
                continue;
            }

            $toMove[$pageName] = $reportsChildren[$pageName];
            unset($reportsChildren[$pageName]);
        }

        if ($toMove === []) {
            return;
        }

        $reportsChildren[self::SUBMENU_KEY] = [
            'name' => self::SUBMENU_KEY,
            'title' => Tools::trans('auralis-informes-submenu'),
            'icon' => 'fa-solid fa-folder-open',
            'menu' => [],
        ];

        foreach ($toMove as $pageName => $item) {
            $reportsChildren[self::SUBMENU_KEY]['menu'][$pageName] = $item;
        }
    }

    private static function informesControllerDir(): string
    {
        if (defined('FS_FOLDER')) {
            $fromFs = (string) FS_FOLDER . '/Plugins/' . self::PLUGIN_NAME . '/Controller';
            if (is_dir($fromFs)) {
                return $fromFs;
            }
        }

        $sibling = dirname(__DIR__, 2) . '/' . self::PLUGIN_NAME . '/Controller';
        return is_dir($sibling) ? $sibling : '';
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

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Gestor de menú para Auralis que implementa la ordenación personalizable.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Lib\MenuManager as CoreMenuManager;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Model\AuralisMenuOrder;

class MenuManager extends CoreMenuManager
{
    /** @var array|null */
    private static $ordersCache = null;

    public static function init(): static
    {
        return new static();
    }

    protected function setActiveMenuItem(array &$menu, array $data): void
    {
        $pageName = (string) ($data['name'] ?? '');
        if (($data['menu'] ?? '') === 'admin'
            && AdminGeneralMenuGrouper::isEnabled()
            && AdminGeneralMenuGrouper::containsPage($pageName)) {
            $submenuKey = AdminGeneralMenuGrouper::SUBMENU_KEY;

            foreach ($menu as $key => $menuItem) {
                if ($menuItem->name !== $submenuKey || empty($menuItem->menu)) {
                    continue;
                }

                $menu[$key]->active = true;
                foreach ($menu[$key]->menu as $subKey => $subItem) {
                    if ($subItem->name === $pageName) {
                        $menu[$key]->menu[$subKey]->active = true;
                        return;
                    }
                }

                return;
            }
        }

        if (($data['menu'] ?? '') === InformesMenuGrouper::MENU_KEY
            && InformesMenuGrouper::isEnabled()
            && InformesMenuGrouper::containsPage($pageName)) {
            $submenuKey = InformesMenuGrouper::SUBMENU_KEY;

            foreach ($menu as $key => $menuItem) {
                if ($menuItem->name !== $submenuKey || empty($menuItem->menu)) {
                    continue;
                }

                $menu[$key]->active = true;
                foreach ($menu[$key]->menu as $subKey => $subItem) {
                    if ($subItem->name === $pageName) {
                        $menu[$key]->menu[$subKey]->active = true;
                        return;
                    }
                }

                return;
            }
        }

        parent::setActiveMenuItem($menu, $data);
    }

    protected function sortMenu(array &$result, int $depth = 0, ?string $parentName = null, ?string $grandParentName = null): void
    {
        if ($depth === 0 && isset($result['admin']) && is_object($result['admin']) && !empty($result['admin']->menu)) {
            $adminMenu = $result['admin']->menu;
            AdminGeneralMenuGrouper::apply($adminMenu);
            $result['admin']->menu = $adminMenu;

            $generalKey = AdminGeneralMenuGrouper::SUBMENU_KEY;
            if (isset($result['admin']->menu[$generalKey]) && !empty($result['admin']->menu[$generalKey]->menu)) {
                $generalMenu = $result['admin']->menu[$generalKey]->menu;
                $this->sortAlphabetically($generalMenu);
                $result['admin']->menu[$generalKey]->menu = $generalMenu;
            }
        }

        if ($depth === 0 && isset($result[InformesMenuGrouper::MENU_KEY])
            && is_object($result[InformesMenuGrouper::MENU_KEY])
            && !empty($result[InformesMenuGrouper::MENU_KEY]->menu)) {
            $reportsMenu = $result[InformesMenuGrouper::MENU_KEY]->menu;
            InformesMenuGrouper::apply($reportsMenu);
            $result[InformesMenuGrouper::MENU_KEY]->menu = $reportsMenu;

            $informesKey = InformesMenuGrouper::SUBMENU_KEY;
            if (isset($result[InformesMenuGrouper::MENU_KEY]->menu[$informesKey])
                && !empty($result[InformesMenuGrouper::MENU_KEY]->menu[$informesKey]->menu)) {
                $informesMenu = $result[InformesMenuGrouper::MENU_KEY]->menu[$informesKey]->menu;
                $this->sortAlphabetically($informesMenu);
                $result[InformesMenuGrouper::MENU_KEY]->menu[$informesKey]->menu = $informesMenu;
            }
        }

        if (false === (bool) Tools::settings('auralis', 'orden_menu_activo', false)) {
            $this->sortAlphabetically($result);
            return;
        }

        $orders = self::getMenuOrders();

        if ($depth === 0) {
            // Nivel 1 (Menús principales)
            $map = $orders['level1'] ?? [];
            uasort($result, function ($a, $b) use ($map) {
                $posA = $map[$a->name] ?? 9999;
                $posB = $map[$b->name] ?? 9999;

                if ($posA === $posB) {
                    return strcasecmp($a->title, $b->title);
                }

                return $posA <=> $posB;
            });

            // Reordenar submenús de Nivel 2
            foreach ($result as $key => $value) {
                if (!empty($value->menu)) {
                    $childMenu = $result[$key]->menu;
                    $this->sortMenu($childMenu, 1, $value->name);
                    $result[$key]->menu = $childMenu;
                }
            }
        } elseif ($depth === 1 && $parentName !== null) {
            // Nivel 2 (Submenús bajo un menú principal)
            $map = $orders['level2'][$parentName] ?? [];
            uasort($result, function ($a, $b) use ($map) {
                $posA = $map[$a->name] ?? 9999;
                $posB = $map[$b->name] ?? 9999;

                if ($posA === $posB) {
                    return strcasecmp($a->title, $b->title);
                }

                return $posA <=> $posB;
            });

            // Reordenar páginas de Nivel 3
            foreach ($result as $key => $value) {
                if (!empty($value->menu)) {
                    $childMenu = $result[$key]->menu;
                    $this->sortMenu($childMenu, 2, $value->name, $parentName);
                    $result[$key]->menu = $childMenu;
                }
            }
        } elseif ($depth === 2 && $parentName !== null) {
            // Nivel 3 (Páginas bajo un submenú)
            $compositeKey = $grandParentName ? ($grandParentName . '/' . $parentName) : $parentName;
            $map = $orders['level3'][$compositeKey] ?? $orders['level3'][$parentName] ?? [];
            uasort($result, function ($a, $b) use ($map) {
                $posA = $map[$a->name] ?? 9999;
                $posB = $map[$b->name] ?? 9999;

                if ($posA === $posB) {
                    return strcasecmp($a->title, $b->title);
                }

                return $posA <=> $posB;
            });
        }
    }

    public static function clearCache(): void
    {
        self::$ordersCache = null;
        Cache::delete('auralis-menu-orders');
        Cache::delete('model-Page-Show-Menu');
    }

    /**
     * Orden alfabético recursivo sin reentrar en sortMenu() del plugin
     * (parent::sortMenu() llama a $this->sortMenu y reiniciaría $depth a 0).
     *
     * @param array<string, mixed> $result
     */
    private function sortAlphabetically(array &$result): void
    {
        uasort($result, function ($menu1, $menu2) {
            return strcasecmp((string) $menu1->title, (string) $menu2->title);
        });

        foreach ($result as $key => $value) {
            if (!empty($value->menu)) {
                $childMenu = $result[$key]->menu;
                $this->sortAlphabetically($childMenu);
                $result[$key]->menu = $childMenu;
            }
        }
    }

    private static function getMenuOrders(): array
    {
        if (self::$ordersCache !== null) {
            return self::$ordersCache;
        }

        self::$ordersCache = Cache::remember('auralis-menu-orders', function () {
            $level1 = [];
            $level2 = [];
            $level3 = [];

            try {
                $rows = AuralisMenuOrder::all([], ['posicion' => 'ASC']);
                foreach ($rows as $row) {
                    $nivel = (int) $row->nivel;
                    if ($nivel === 1) {
                        $level1[$row->nombre] = (int) $row->posicion;
                    } elseif ($nivel === 2 && !empty($row->padre)) {
                        $level2[$row->padre][$row->nombre] = (int) $row->posicion;
                    } elseif ($nivel === 3 && !empty($row->padre)) {
                        $level3[$row->padre][$row->nombre] = (int) $row->posicion;
                    }
                }
            } catch (\Throwable $e) {
                // Fallback a arrays vacíos si aún no existe la tabla
            }

            return [
                'level1' => $level1,
                'level2' => $level2,
                'level3' => $level3,
            ];
        });

        return self::$ordersCache;
    }
}

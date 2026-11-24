<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Asigna DashBoardAuralis como homepage nativa a admins e instalación
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;

class HomepageAdmins
{
    public const HOMEPAGE = 'DashBoardAuralis';

    /** @var string[] Homepages genéricas del core que se sustituyen por DashBoardAuralis */
    public const REEMPLAZABLES = [
        '',
        'Dashboard',
        'EditSettings',
        'AdminPlugins',
        'Root',
        'About',
    ];

    /**
     * Asigna homepage vía ORM (Init::update / Init::init sin acceso a db()).
     */
    public static function asignar(): void
    {
        if (!self::paginaExiste()) {
            Tools::log()->warning('auralis-homepage-page-not-found', ['%page%' => self::HOMEPAGE]);
            return;
        }

        self::actualizarSettings();

        foreach (DbQuery::table('users')->whereEq('admin', true)->get() as $row) {
            $user = new User();
            if (!$user->load($row['nick'] ?? '')) {
                continue;
            }

            if (!self::esReemplazable($user->homepage ?? null)) {
                continue;
            }

            $user->homepage = self::HOMEPAGE;
            if (false === $user->save()) {
                Tools::log()->warning('auralis-homepage-save-failed', ['%nick%' => $user->nick]);
            }
        }
    }

    /**
     * Asigna homepage con UPDATE masivo SQL (migraciones).
     */
    public static function asignarMasivo(DataBase $db): void
    {
        if (!$db->tableExists('users') || !$db->tableExists('pages')) {
            return;
        }

        if (!self::paginaExiste()) {
            Tools::log()->warning('auralis-homepage-page-not-found', ['%page%' => self::HOMEPAGE]);
            return;
        }

        self::actualizarSettings();

        $inList = implode(',', array_map(
            static fn(string $pagina): string => $db->var2str($pagina),
            self::REEMPLAZABLES
        ));

        $homepage = $db->var2str(self::HOMEPAGE);
        $sql = 'UPDATE users SET homepage = ' . $homepage
            . ' WHERE admin = TRUE AND (homepage IS NULL OR homepage IN (' . $inList . '))';

        $db->exec($sql);
    }

    public static function paginaExiste(): bool
    {
        return [] !== DbQuery::table('pages')->whereEq('name', self::HOMEPAGE)->first();
    }

    public static function esReemplazable(?string $homepage): bool
    {
        $actual = (string) ($homepage ?? '');
        if ($actual === '') {
            return true;
        }

        return in_array($actual, self::REEMPLAZABLES, true);
    }

    public static function actualizarSettings(): void
    {
        $homepage = Tools::settings('default', 'homepage', 'Dashboard');
        if (self::esReemplazable($homepage === null ? null : (string) $homepage)) {
            Tools::settingsSet('default', 'homepage', self::HOMEPAGE);
            Tools::settingsSave();
        }
    }
}

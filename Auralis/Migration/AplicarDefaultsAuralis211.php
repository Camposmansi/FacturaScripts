<?php

/**
 * Ajusta una sola vez los valores que eran predeterminados en Auralis 2.10.
 * No modifica textos de pie personalizados por el usuario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Dinamic\Model\Settings;

class AplicarDefaultsAuralis211 extends MigrationClass
{
    public const MIGRATION_NAME = 'aplicar_defaults_auralis_2_11';

    public function run(): void
    {
        $settings = new Settings();
        if (false === $settings->load('auralis')) {
            return;
        }

        $settings->setProperty('agrupar_admin_general', '0');
        $settings->setProperty('agrupar_informes_menu', '0');

        // 2.10 usaba el tema oscuro como valor inicial; 2.11 recupera el aspecto original.
        if (in_array($settings->getProperty('tema_color'), ['auralis_dark', 'auralis_oscuro'], true)) {
            $settings->setProperty('tema_color', 'default');
        }

        $oldFooter = $settings->getProperty('login_footer_text');
        if (in_array($oldFooter, [
            '© {year} Diseñado por MansiHost.com • Software libre FacturaScripts',
            '© {year} Diseñado por Auralis • Software libre FacturaScripts',
        ], true)) {
            $settings->setProperty('login_footer_text', '© {year} Diseñado por MansiHost.com');
        }

        $settings->save();
    }
}

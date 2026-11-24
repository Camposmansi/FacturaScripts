<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Vuelve a sellar la clave privada VAPID guardada en formato enc:v1: con la clave de la instalación (enc:v2:). Si no abre, no toca nada.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Migration;

use FacturaScripts\Core\Template\MigrationClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\VapidSecretBox;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use RuntimeException;

class ResellarSecretoVapid extends MigrationClass
{
    public const MIGRATION_NAME = 'resellar_secreto_vapid_v1_78';

    public function run(): void
    {
        $almacenado = AuralisPushSettings::vapidPrivateStored();

        // Sin clave o ya en el formato actual: nada que hacer (idempotente).
        if ($almacenado === '' || false === VapidSecretBox::esLegado($almacenado)) {
            return;
        }

        // Si no abre, se deja como está: el administrador regenerará las claves VAPID.
        $pem = VapidSecretBox::open($almacenado);
        if ($pem === '' || false === str_contains($pem, 'PRIVATE KEY')) {
            return;
        }

        // Comprobar que lo nuevo abre al mismo PEM antes de sustituir lo guardado. Si no, se deja
        // el formato antiguo (sigue abriendo) para no bloquear el resto de migraciones.
        $nuevo = VapidSecretBox::seal($pem);
        if ($nuevo === '' || VapidSecretBox::esLegado($nuevo) || VapidSecretBox::open($nuevo) !== trim($pem)) {
            Tools::log('Auralis')->warning('auralis-vapid-resellar-fallido');
            return;
        }

        Tools::settingsSet(AuralisPushSettings::group(), AuralisPushSettings::SETTING_VAPID_PRIVATE, $nuevo);
        if (false === Tools::settingsSave()) {
            // Queda pendiente y se reintenta en la siguiente actualización.
            throw new RuntimeException('auralis-vapid: no se han podido guardar los ajustes');
        }
    }
}

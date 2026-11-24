<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Cifrado en reposo del PEM privado VAPID (AES-256-GCM + clave de la instalación).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisSecurity;

final class VapidSecretBox
{
    /** Formato actual: clave de la instalación (FS_APP_KEY o derivada de la conexión a BD). */
    private const PREFIJO_ACTUAL = 'enc:v2:';

    /** Formato antiguo (solo lectura): clave derivada de constantes + db_name. Lo re-sella la migración. */
    private const PREFIJO_LEGADO = 'enc:v1:';

    /** Propósito de la clave de la instalación para este cifrado. */
    private const PROPOSITO_CLAVE = 'auralis-vapid-v3';

    public static function seal(string $privatePem): string
    {
        $privatePem = trim($privatePem);
        if ($privatePem === '') {
            return '';
        }

        $key = AuralisSecurity::claveInstalacion(self::PROPOSITO_CLAVE);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($privatePem, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            return '';
        }

        return self::PREFIJO_ACTUAL . Base64Url::encode($iv . $tag . $cipher);
    }

    public static function open(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, self::PREFIJO_ACTUAL)) {
            return self::abrirConClaves(
                substr($stored, strlen(self::PREFIJO_ACTUAL)),
                AuralisSecurity::clavesInstalacionCandidatas(self::PROPOSITO_CLAVE)
            );
        }

        if (str_starts_with($stored, self::PREFIJO_LEGADO)) {
            return self::abrirConClaves(
                substr($stored, strlen(self::PREFIJO_LEGADO)),
                [self::deriveKey(), self::legacyKey()]
            );
        }

        // Rechazar secretos en texto plano o con un prefijo desconocido.
        return '';
    }

    public static function isSealed(string $stored): bool
    {
        $stored = trim($stored);

        return str_starts_with($stored, self::PREFIJO_ACTUAL) || str_starts_with($stored, self::PREFIJO_LEGADO);
    }

    /**
     * True si el valor está sellado con el formato antiguo y conviene volver a sellarlo.
     */
    public static function esLegado(string $stored): bool
    {
        return str_starts_with(trim($stored), self::PREFIJO_LEGADO);
    }

    /**
     * Descifra iv(12) . tag(16) . cipher probando cada clave en orden. Vacío si ninguna abre.
     *
     * @param list<string> $claves
     */
    private static function abrirConClaves(string $cuerpo, array $claves): string
    {
        $blob = Base64Url::decode($cuerpo);
        if (strlen($blob) < 28) {
            return '';
        }

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $cipher = substr($blob, 28);
        foreach ($claves as $clave) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $tag);
            if (is_string($plain) && $plain !== '') {
                return $plain;
            }
        }

        return '';
    }

    /** Solo para leer enc:v1: (formato antiguo). */
    private static function deriveKey(): string
    {
        $secret = (string) Tools::config('secret', 'facturascripts');
        $dbName = (string) Tools::config('db_name', 'fs');

        return hash('sha256', 'auralis-vapid-v2|' . $dbName . '|' . $secret, true);
    }

    /** Solo para leer enc:v1: (formato más antiguo aún). */
    private static function legacyKey(): string
    {
        $secret = (string) Tools::config('secret', 'facturascripts');

        return hash('sha256', 'auralis-vapid-v1|' . $secret, true);
    }
}

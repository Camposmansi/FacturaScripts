<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Ajustes y envío por email del paquete para la gestoría (destinatario, límites y NewMail con adjunto).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Validator;
use FacturaScripts\Dinamic\Lib\Email\NewMail;

final class GestoriaEnvio
{
    public const GRUPO_AJUSTES = 'auralis_gestoria';

    public const AJUSTE_EMAIL = 'email';
    public const AJUSTE_MAX_MB = 'max_mb';
    public const AJUSTE_MAX_DOCUMENTOS = 'max_documentos';

    public const DEFECTO_MAX_MB = 10;
    public const DEFECTO_MAX_DOCUMENTOS = 500;

    /** Topes de los ajustes (los mismos que el formulario de ConfigAjustesAuralis). */
    public const TOPE_MAX_MB = 50;
    public const TOPE_MAX_DOCUMENTOS = 5000;

    /** Segundos orientativos por documento (medido: 64 PDF en ~2 s con el motor del Core). */
    public const SEGUNDOS_POR_DOCUMENTO = 0.04;

    public static function emailPredeterminado(): string
    {
        return trim((string)Tools::settings(self::GRUPO_AJUSTES, self::AJUSTE_EMAIL, ''));
    }

    public static function maxMb(): int
    {
        $valor = (int)Tools::settings(self::GRUPO_AJUSTES, self::AJUSTE_MAX_MB, self::DEFECTO_MAX_MB);

        return $valor > 0 ? min($valor, self::TOPE_MAX_MB) : self::DEFECTO_MAX_MB;
    }

    public static function maxDocumentos(): int
    {
        $valor = (int)Tools::settings(self::GRUPO_AJUSTES, self::AJUSTE_MAX_DOCUMENTOS, self::DEFECTO_MAX_DOCUMENTOS);

        return $valor > 0 ? min($valor, self::TOPE_MAX_DOCUMENTOS) : self::DEFECTO_MAX_DOCUMENTOS;
    }

    /**
     * Segundos estimados para generar el paquete (redondeado hacia arriba, mínimo 1).
     */
    public static function segundosEstimados(int $documentos): int
    {
        return max(1, (int)ceil($documentos * self::SEGUNDOS_POR_DOCUMENTO));
    }

    /**
     * Email saneado y validado; cadena vacía si no es válido.
     */
    public static function normalizarEmail(string $email): string
    {
        $email = mb_substr(trim($email), 0, 100);

        return Validator::email($email) ? $email : '';
    }

    public static function guardarEmailPredeterminado(string $email): bool
    {
        $email = self::normalizarEmail($email);
        if ($email === '') {
            return false;
        }

        Tools::settingsSet(self::GRUPO_AJUSTES, self::AJUSTE_EMAIL, $email);

        return Tools::settingsSave();
    }

    public static function superaTamano(string $rutaZip, int $maxMb): bool
    {
        $tamano = is_file($rutaZip) ? (int)filesize($rutaZip) : 0;

        return $tamano > $maxMb * 1024 * 1024;
    }

    public static function correoConfigurado(): bool
    {
        return NewMail::create()->canSendMail();
    }

    /**
     * Envía el ZIP a la gestoría. Devuelve false si NewMail no pudo enviarlo.
     */
    public static function enviar(string $email, string $rutaZip, string $nombrePaquete, string $desde, string $hasta, int $documentos): bool
    {
        $periodo = [
            '%desde%' => Tools::date($desde),
            '%hasta%' => Tools::date($hasta),
            '%documentos%' => (string)$documentos,
        ];

        return NewMail::create()
            ->to($email)
            ->subject(Tools::trans('auralis-gestoria-email-asunto', $periodo))
            ->body(Tools::trans('auralis-gestoria-email-cuerpo', $periodo))
            ->addAttachment($rutaZip, $nombrePaquete)
            ->send();
    }
}

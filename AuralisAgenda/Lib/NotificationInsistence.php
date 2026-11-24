<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Decide si un aviso pendiente se cierra solo, sin tocar la base de datos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

final class NotificationInsistence
{
    public const REFERENCIA_MAX = 120;

    public static function normalizarReferencia(string $referencia): string
    {
        $referencia = trim($referencia);
        if ($referencia === '') {
            return '';
        }

        if (mb_strlen($referencia) > self::REFERENCIA_MAX) {
            return mb_substr($referencia, 0, self::REFERENCIA_MAX);
        }

        return $referencia;
    }

    /**
     * Cierra la fila solo si el tipo lo permite, sigue pendiente y la referencia es la pedida.
     */
    public static function debeCerrar(
        ?NotificationTypeInterface $tipo,
        string $tipoFila,
        string $referenciaFila,
        bool $leida,
        string $tipoBuscado,
        string $referenciaBuscada
    ): bool {
        if ($tipo === null || false === $tipo->cierreAutomatico() || $leida) {
            return false;
        }

        $tipoBuscado = trim($tipoBuscado);
        $referenciaBuscada = self::normalizarReferencia($referenciaBuscada);
        if ($tipoBuscado === '' || $referenciaBuscada === '' || $tipo->clave() !== $tipoBuscado) {
            return false;
        }

        return $tipoFila === $tipoBuscado
            && self::normalizarReferencia($referenciaFila) === $referenciaBuscada;
    }
}

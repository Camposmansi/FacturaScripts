<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Nombres saneados y únicos de los ficheros del paquete para la gestoría (fecha_codigo_nombre).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

final class GestoriaNombres
{
    /** Longitud máxima de la parte sin extensión. */
    public const MAX_BASE = 100;

    /** Longitud máxima del tramo del nombre del cliente o proveedor. */
    public const MAX_TITULAR = 40;

    /** @var array<string, true> nombres ya usados por carpeta (en minúsculas) */
    private array $usados = [];

    /**
     * Nombre «fecha_codigo_nombre.ext» saneado y sin colisiones dentro de su carpeta.
     */
    public function documento(string $carpeta, string $fecha, string $codigo, string $titular, string $extension): string
    {
        $partes = [];
        foreach ([$fecha, $codigo, mb_substr(self::sanear($titular), 0, self::MAX_TITULAR)] as $parte) {
            $limpia = self::sanear($parte);
            if ($limpia !== '') {
                $partes[] = $limpia;
            }
        }

        $base = $partes === [] ? 'documento' : implode('_', $partes);

        return $this->unico($carpeta, $base, self::extension($extension));
    }

    /**
     * Reserva un nombre en la carpeta; si choca añade «_2», «_3»… antes de la extensión.
     *
     * Con $maxBase (el nombre sale de un patrón con su propia longitud máxima) el sufijo cabe dentro de ese
     * límite: se recorta la base lo justo. Sin él, el comportamiento de siempre (MAX_BASE y sufijo detrás).
     */
    public function unico(string $carpeta, string $base, string $extension, ?int $maxBase = null): string
    {
        $limite = $maxBase === null ? self::MAX_BASE : max(1, $maxBase);
        $base = trim(mb_substr(self::sanear($base), 0, $limite), '_.-');
        if ($base === '') {
            $base = 'documento';
        }

        $sufijoExt = $extension === '' ? '' : '.' . $extension;
        $candidato = $base . $sufijoExt;
        $contador = 2;
        while (isset($this->usados[$this->clave($carpeta, $candidato)])) {
            $sufijo = '_' . $contador;
            $recortada = $maxBase === null ? $base : trim(mb_substr($base, 0, max(1, $limite - mb_strlen($sufijo))), '_.-');
            $candidato = ($recortada === '' ? 'documento' : $recortada) . $sufijo . $sufijoExt;
            ++$contador;
        }

        $this->usados[$this->clave($carpeta, $candidato)] = true;

        return $candidato;
    }

    /**
     * Nombre del ZIP del periodo.
     */
    public static function zip(string $desde, string $hasta): string
    {
        return 'documentos-gestoria_' . self::sanear($desde) . '_' . self::sanear($hasta) . '.zip';
    }

    /**
     * Deja letras, números, punto, guion y guion bajo; el resto pasa a «_» (sin dobles).
     */
    public static function sanear(string $texto): string
    {
        $texto = trim($texto);
        $texto = (string)preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $texto);
        $texto = (string)preg_replace('/_{2,}/', '_', $texto);
        $texto = (string)preg_replace('/\.{2,}/', '.', $texto);

        return trim($texto, '_.');
    }

    /**
     * Extensión en minúsculas, solo alfanumérica y corta.
     */
    public static function extension(string $extension): string
    {
        $extension = mb_strtolower(ltrim(trim($extension), '.'));
        $extension = (string)preg_replace('/[^a-z0-9]/', '', $extension);

        return mb_substr($extension, 0, 10);
    }

    private function clave(string $carpeta, string $nombre): string
    {
        return mb_strtolower($carpeta . '/' . $nombre);
    }
}

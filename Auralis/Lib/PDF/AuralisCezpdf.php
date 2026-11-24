<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Cezpdf con el estilo básico de Auralis: escala tamaños, aplica colores y tipografía a textos, tablas y líneas del PDF del Core.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\PDF;

use Cezpdf;
use FacturaScripts\Plugins\Auralis\Lib\PdfEstiloAuralis;

class AuralisCezpdf extends Cezpdf
{
    /**
     * ezTable parte del tamaño base del Core (9). Después se escala al valor elegido
     * por el usuario, igual que hace la vista previa con --zpdf-base.
     */
    private const TABLA_TAMANO_DEFECTO = PdfEstiloAuralis::TAMANO_CORE;

    /** 1,67 veces el cuerpo: la misma jerarquía de .zpdf-grande en la vista previa. */
    private const TABLA_TITULO_TAMANO_DEFECTO = PdfEstiloAuralis::TAMANO_CORE + 6;

    /**
     * Métricas verticales de Helvetica en milésimas de em (fonts/Helvetica.afm: FontBBox -166 -225 1000 931,
     * Descender -207), las del PDF del Core. Se usan con cualquier fuente para que no mueva filas ni bloques.
     */
    private const ALTO_LINEA_HELVETICA = 1156;

    private const DESCENDENTE_HELVETICA = -207;

    /** @var PdfEstiloAuralis */
    private $estilo;

    /**
     * Profundidad de llamadas propias: ezText/ezTable llaman a addText/ezText/line por dentro y
     * solo se transforma la llamada externa (si no, el tamaño se escalaría dos veces).
     *
     * @var int
     */
    private $profundidad = 0;

    /** @var float[]|null color del texto de la cabecera de la tabla en curso */
    private $textoCabeceraTabla = null;

    /** @var array<string, string[]> prefijos a quitar, en orden de fila, por columna de tabla */
    private $prefijosColumna = [];

    /** @var array<string, string> valor para las celdas vacías de una columna en las siguientes tablas */
    private $rellenoColumna = [];

    /**
     * @param string $papel
     * @param string $orientacion
     */
    public function __construct(PdfEstiloAuralis $estilo, $papel = 'a4', $orientacion = 'portrait')
    {
        parent::__construct($papel, $orientacion);
        $this->estilo = $estilo;
    }

    public function getEstilo(): PdfEstiloAuralis
    {
        return $this->estilo;
    }

    /**
     * Selecciona la fuente del estilo. Las del tema se incrustan como TTF en modo Unicode
     * (el modo WinAnsi de Cpdf deja sin anchura €, — y œ) y sin recortar (TTFsubset rompe glifos).
     * Requiere tempPath ya fijado: Cpdf escribe ahí la caché de la fuente.
     */
    public function aplicarFuente(): void
    {
        $fuente = $this->estilo->fuenteEfectiva();
        if (false === PdfEstiloAuralis::esFuenteIncrustada($fuente)) {
            $this->selectFont($fuente);
            return;
        }

        // Helvetica cargada antes de cambiar fontPath: es la reserva de Cpdf si la TTF no se pudiera leer.
        $this->selectFont(PdfEstiloAuralis::FUENTES[0]);

        $normal = PdfEstiloAuralis::nombreFuenteIncrustada($fuente);
        $negrita = PdfEstiloAuralis::nombreFuenteIncrustada($fuente, true);
        $this->fontPath = PdfEstiloAuralis::carpetaFuente($fuente);
        $this->isUnicode = true;
        $this->setFontFamily($normal, ['b' => $negrita, 'i' => $normal, 'bi' => $negrita, 'ib' => $negrita]);
        $this->selectFont($normal);
    }

    /**
     * Prepara una cola de prefijos (uno por fila, en orden) que se quitarán del texto de $columna
     * en las siguientes tablas. Una cadena vacía deja la fila sin tocar.
     *
     * @param string[] $prefijos
     */
    public function quitarPrefijosColumna(string $columna, array $prefijos): void
    {
        $this->prefijosColumna[$columna] = array_values($prefijos);
    }

    public function limpiarPrefijosColumna(string $columna): void
    {
        unset($this->prefijosColumna[$columna]);
    }

    /**
     * Rellena con $valor las celdas vacías de $columna en las siguientes tablas que la tengan.
     */
    public function rellenarColumnaVacia(string $columna, string $valor): void
    {
        $this->rellenoColumna[$columna] = $valor;
    }

    public function limpiarRellenoColumna(string $columna): void
    {
        unset($this->rellenoColumna[$columna]);
    }

    public function ezText($text, $size = 0, $options = [], $test = 0)
    {
        if ($this->profundidad > 0) {
            return parent::ezText($text, $size, $options, $test);
        }

        $this->profundidad++;
        try {
            $tamano = (float)$size;
            $color = $this->estilo->colorTexto;
            if ($tamano > 0) {
                // Título del documento, nombre de empresa y cabeceras de bloque (≥ FONT_SIZE + 6).
                if ($tamano >= PdfEstiloAuralis::TAMANO_CORE + 6) {
                    $color = $this->estilo->colorAcento;
                }
                $tamano = $this->escalar($tamano);
            }

            if (empty($test)) {
                $this->setColor($color[0], $color[1], $color[2], 1);
            }

            return parent::ezText($text, $tamano, $options, $test);
        } finally {
            $this->profundidad--;
        }
    }

    public function addText($x, $y, $size, $text, $width = 0, $justification = 'left', $angle = 0, $wordSpaceAdjust = 0, $test = 0)
    {
        if ($this->profundidad > 0) {
            return parent::addText($x, $y, $size, $text, $width, $justification, $angle, $wordSpaceAdjust, $test);
        }

        $this->profundidad++;
        try {
            // Sin tocar el color: el Core lo fija antes (p. ej. aviso de borrador en rojo).
            return parent::addText($x, $y, $this->escalar((float)$size), $text, $width, $justification, $angle, $wordSpaceAdjust, $test);
        } finally {
            $this->profundidad--;
        }
    }

    public function ezTable(&$data, $cols = '', $title = '', $options = '')
    {
        if ($this->profundidad > 0) {
            return parent::ezTable($data, $cols, $title, $options);
        }

        $this->profundidad++;
        try {
            $opciones = $this->opcionesTabla(is_array($options) ? $options : [], $cols);
            $this->textoCabeceraTabla = PdfEstiloAuralis::colorTextoSobreFondo($opciones['shadeHeadingCol']);
            $filas = is_array($data) && is_array($cols)
                ? $this->aplicarRelleno($this->aplicarPrefijos($data, $cols), $cols)
                : $data;

            return parent::ezTable($filas, $cols, $title, $opciones);
        } finally {
            $this->textoCabeceraTabla = null;
            $this->profundidad--;
        }
    }

    public function line($x1, $y1, $x2, $y2)
    {
        if ($this->profundidad > 0) {
            return parent::line($x1, $y1, $x2, $y2);
        }

        $previo = $this->currentStrokeColour;
        $acento = $this->estilo->colorAcento;
        $this->setStrokeColor($acento[0], $acento[1], $acento[2], 1);
        $resultado = parent::line($x1, $y1, $x2, $y2);
        if (isset($previo['r'], $previo['g'], $previo['b']) && $previo['r'] >= 0) {
            $this->setStrokeColor($previo['r'], $previo['g'], $previo['b'], 1);
        }

        return $resultado;
    }

    /**
     * Alto de línea con las métricas de Helvetica: Cpdf lo saca del FontBBox de la fuente activa, que en las TTF
     * cubre el glifo más extremo (Poppins 1637 frente a 1156) y movería filas, títulos y bloques según la fuente.
     */
    public function getFontHeight($size)
    {
        return $size * self::ALTO_LINEA_HELVETICA / 1000;
    }

    /**
     * Descendente de Helvetica: Cpdf no normaliza el `hhea.descender` de las TTF a 1000 unidades por em,
     * y en las de 2000/2048 (Lato, Roboto, Inter…) lo duplica.
     */
    public function getFontDescender($size)
    {
        return $size * self::DESCENDENTE_HELVETICA / 1000;
    }

    protected function ezTableColumnHeadings($cols, $pos, $maxWidth, $height, $descender, $gap, $size, &$y, $optionsAll = [])
    {
        if (null !== $this->textoCabeceraTabla && is_array($optionsAll)) {
            $optionsAll['textCol'] = $this->textoCabeceraTabla;
        }

        return parent::ezTableColumnHeadings($cols, $pos, $maxWidth, $height, $descender, $gap, $size, $y, $optionsAll);
    }

    /**
     * Mezcla el estilo con las opciones del Core. Respeta `textCol`, `lineCol` y `shaded = 0` explícitos
     * (tablas clave-valor sin bordes de la cabecera del documento).
     *
     * @param array<string, mixed> $opciones
     *
     * @return array<string, mixed>
     */
    private function opcionesTabla(array $opciones, $columnas = []): array
    {
        $opciones['fontSize'] = $this->escalar((float)($opciones['fontSize'] ?? self::TABLA_TAMANO_DEFECTO));
        $opciones['titleFontSize'] = $this->escalar((float)($opciones['titleFontSize'] ?? self::TABLA_TITULO_TAMANO_DEFECTO));

        if (false === isset($opciones['textCol']) || false === is_array($opciones['textCol'])) {
            $opciones['textCol'] = $this->estilo->colorTexto;
        }

        $cabecera = $this->estilo->colorCabecera;
        $opciones['shadeHeadingCol'] = $cabecera;

        $sinSombreado = isset($opciones['shaded']) && 0 === (int)$opciones['shaded'];
        if (false === $sinSombreado) {
            if ($this->estilo->filasAlternas) {
                $opciones['shaded'] = 1;
                // Cabecera clara: se usa tal cual en las filas alternas; oscura: se aclara al 15 %.
                $opciones['shadeCol'] = PdfEstiloAuralis::colorTextoSobreFondo($cabecera) === [0.0, 0.0, 0.0]
                    ? $cabecera
                    : PdfEstiloAuralis::mezclarConBlanco($cabecera, 0.85);
            } else {
                $opciones['shaded'] = 0;
            }
        }

        if (false === isset($opciones['lineCol']) || false === is_array($opciones['lineCol'])) {
            $opciones['lineCol'] = PdfEstiloAuralis::mezclarConBlanco($this->estilo->colorAcento, 0.5);
        }

        // El resumen de importes del Core es una fila horizontal a todo lo ancho. La vista de
        // Auralis lo presenta como una tarjeta compacta a la derecha, más fácil de localizar.
        if ($this->esTablaTotales($columnas)) {
            $anchoDisponible = $this->ez['pageWidth'] - $this->ez['leftMargin'] - $this->ez['rightMargin'];
            $ancho = round($anchoDisponible * 0.48, 2);
            $opciones['width'] = $ancho;
            $opciones['xPos'] = $this->ez['pageWidth'] - $this->ez['rightMargin'] - ($ancho / 2);
        }

        return $opciones;
    }

    /**
     * Reconoce exclusivamente la tabla de resumen monetario del documento, sin afectar a
     * impuestos, recibos ni listados generales que también puedan contener una columna total.
     *
     * @param mixed $columnas
     */
    private function esTablaTotales($columnas): bool
    {
        if (false === is_array($columnas)) {
            return false;
        }

        $claves = array_keys($columnas);
        return in_array('currency', $claves, true)
            && in_array('net', $claves, true)
            && in_array('total', $claves, true);
    }

    /**
     * Quita de cada fila el prefijo pendiente de su columna (cola consumida en orden de fila).
     *
     * @param array<int|string, mixed> $filas
     * @param array<string, mixed> $columnas
     *
     * @return array<int|string, mixed>
     */
    private function aplicarPrefijos(array $filas, array $columnas): array
    {
        foreach (array_keys($this->prefijosColumna) as $columna) {
            if (false === array_key_exists($columna, $columnas)) {
                continue;
            }

            foreach ($filas as $indice => $fila) {
                $prefijo = array_shift($this->prefijosColumna[$columna]);
                if (null === $prefijo) {
                    break;
                }

                $texto = is_array($fila) ? ($fila[$columna] ?? null) : null;
                if ('' === $prefijo || false === is_string($texto) || false === str_starts_with($texto, $prefijo)) {
                    continue;
                }

                $filas[$indice][$columna] = mb_substr($texto, mb_strlen($prefijo));
            }
        }

        return $filas;
    }

    /**
     * @param array<int|string, mixed> $filas
     * @param array<string, mixed> $columnas
     *
     * @return array<int|string, mixed>
     */
    private function aplicarRelleno(array $filas, array $columnas): array
    {
        foreach ($this->rellenoColumna as $columna => $valor) {
            if (false === array_key_exists($columna, $columnas)) {
                continue;
            }

            foreach ($filas as $indice => $fila) {
                if (is_array($fila) && '' === (string)($fila[$columna] ?? '')) {
                    $filas[$indice][$columna] = $valor;
                }
            }
        }

        return $filas;
    }

    private function escalar(float $tamano): float
    {
        return round($tamano * $this->estilo->factorEscala(), 2);
    }
}

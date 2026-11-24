<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Estilo básico del PDF del Core (motor ezPDF): lee y sanea el grupo de ajustes auralis_pdf.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

final class PdfEstiloAuralis
{
    public const GRUPO = 'auralis_pdf';

    /** Plugin de plantillas que sustituye al motor ezPDF del Core: si está activo, este estilo no se aplica. */
    public const PLUGIN_PLANTILLAS = 'PlantillasPDF';

    /** Fuentes estándar PDF incluidas en rospdf/pdf-php (sin incrustar). */
    public const FUENTES = ['Helvetica', 'Times-Roman', 'Courier'];

    /** Valor de `fuente` que sigue la tipografía elegida en ConfigAuralis (`auralis.tipografia`). */
    public const FUENTE_TEMA = 'tema';

    /**
     * Tipografías de ConfigAuralis con TTF para PDF (clave del ajuste => carpeta en Assets/Fonts).
     * Cada carpeta lleva `<carpeta>-pdf-400.ttf` y `<carpeta>-pdf-700.ttf` (subset latin, se incrustan enteras).
     */
    public const FUENTES_INCRUSTADAS = [
        'inter' => 'inter',
        'lato' => 'lato',
        'merriweather' => 'merriweather',
        'montserrat' => 'montserrat',
        'nunito' => 'nunito',
        'open_sans' => 'open-sans',
        'outfit' => 'outfit',
        'plus_jakarta_sans' => 'plus-jakarta-sans',
        'poppins' => 'poppins',
        'roboto' => 'roboto',
    ];

    /**
     * Familias cuya OFL reserva el nombre («Reserved Font Name»): el subset para PDF es una versión
     * modificada y va con otro nombre, en el fichero y dentro de la fuente. Ver LEEME-PDF.txt en su carpeta.
     */
    private const TTF_RENOMBRADOS = [
        'lato' => 'auralis-sans',
    ];

    public const PAPELES = ['a4', 'letter'];

    /** Tamaño de letra base del Core (PDFCore::FONT_SIZE): referencia para escalar. */
    public const TAMANO_CORE = 9;

    public const TAMANO_MIN = 7;

    public const TAMANO_MAX = 12;

    /** Acento si el tema de ConfigAuralis no tiene color propio. */
    public const COLOR_ACENTO_FALLBACK = '#1f4e79';

    /** Claves booleanas del grupo (checkboxes del XMLView). */
    public const CLAVES_BOOLEANAS = [
        'activo',
        'filas_alternas',
        'mostrar_contacto_empresa',
        'ocultar_tipo_documento',
        'ocultar_logo',
        'ocultar_forma_pago',
        'ocultar_iban',
        'ocultar_recibos',
        'ocultar_envio',
        'ocultar_referencia',
        'ocultar_descuentos',
        'ocultar_numeracion',
    ];

    /** @var bool */
    public $activo = false;

    /** @var float[] RGB 0..1 */
    public $colorAcento = [0.0, 0.0, 0.0];

    /** @var float[] RGB 0..1 */
    public $colorTexto = [0.0, 0.0, 0.0];

    /** @var float[] RGB 0..1 */
    public $colorCabecera = [0.95, 0.95, 0.95];

    /** @var bool */
    public $filasAlternas = true;

    /** @var string elección guardada: `tema`, una estándar o una clave de FUENTES_INCRUSTADAS */
    public $fuente = self::FUENTE_TEMA;

    /** @var int */
    public $tamanoBase = self::TAMANO_CORE;

    /** @var string */
    public $papel = 'a4';

    /** @var string izquierda|derecha */
    public $cabeceraPosicion = 'izquierda';

    /** @var int altura máxima del logotipo en puntos */
    public $logoTamano = 50;

    /** @var int tamaño del nombre de empresa en puntos */
    public $empresaNombreTamano = 11;

    /** @var int tamaño de dirección, CIF y contacto en puntos */
    public $empresaDatosTamano = 8;

    /** @var int separación tras la cabecera de empresa, en puntos */
    public $cabeceraEspaciado = 8;

    /** @var string despues_articulos|pie_pagina */
    public $totalesPosicion = 'despues_articulos';

    /** @var bool */
    public $mostrarContactoEmpresa = false;

    /** @var bool */
    public $ocultarTipoDocumento = false;

    /** @var bool */
    public $ocultarLogo = false;

    /** @var bool */
    public $ocultarFormaPago = false;

    /** @var bool */
    public $ocultarIban = false;

    /** @var bool */
    public $ocultarRecibos = false;

    /** @var bool */
    public $ocultarEnvio = false;

    /** @var bool */
    public $ocultarReferencia = false;

    /** @var bool */
    public $ocultarDescuentos = false;

    /** @var bool */
    public $ocultarNumeracion = false;

    /**
     * Valores por defecto del grupo auralis_pdf (lo que ve el formulario antes de guardar).
     *
     * @return array<string, mixed>
     */
    public static function valoresPorDefecto(): array
    {
        return [
            'activo' => false,
            'color_acento' => self::colorAcentoPorDefecto(),
            'color_texto' => '#000000',
            'color_cabecera' => '#f2f2f2',
            'filas_alternas' => true,
            'fuente' => self::FUENTE_TEMA,
            'tamano_base' => (string)self::TAMANO_CORE,
            'papel' => 'a4',
            'cabecera_posicion' => 'izquierda',
            'logo_tamano' => '50',
            'empresa_nombre_tamano' => '11',
            'empresa_datos_tamano' => '8',
            'cabecera_espaciado' => '8',
            'totales_posicion' => 'despues_articulos',
            'mostrar_contacto_empresa' => false,
            'ocultar_tipo_documento' => false,
            'ocultar_logo' => false,
            'ocultar_forma_pago' => false,
            'ocultar_iban' => false,
            'ocultar_recibos' => false,
            'ocultar_envio' => false,
            'ocultar_referencia' => false,
            'ocultar_descuentos' => false,
            'ocultar_numeracion' => false,
        ];
    }

    /**
     * Registra en memoria los valores por defecto que falten (Tools::settingsSet no persiste: sin consultas).
     */
    public static function asegurarValoresPorDefecto(): void
    {
        foreach (self::valoresPorDefecto() as $clave => $valor) {
            if (null === Tools::settings(self::GRUPO, $clave)) {
                Tools::settingsSet(self::GRUPO, $clave, $valor);
            }
        }
    }

    /**
     * Estilo con los ajustes guardados (o sus valores por defecto).
     */
    public static function cargar(): self
    {
        $valores = [];
        foreach (self::valoresPorDefecto() as $clave => $porDefecto) {
            $valores[$clave] = Tools::settings(self::GRUPO, $clave, $porDefecto);
        }

        return self::desdeArray($valores);
    }

    /**
     * Construye el estilo saneando cada valor; lo que no sea válido vuelve al valor por defecto.
     *
     * @param array<string, mixed> $valores
     */
    public static function desdeArray(array $valores): self
    {
        $porDefecto = self::valoresPorDefecto();
        $estilo = new self();

        $estilo->activo = self::aBooleano($valores['activo'] ?? null, false);
        $estilo->colorAcento = self::hexARgb(
            (string)($valores['color_acento'] ?? ''),
            self::hexARgb((string)$porDefecto['color_acento'], [0.0, 0.0, 0.0])
        );
        $estilo->colorTexto = self::hexARgb((string)($valores['color_texto'] ?? ''), [0.0, 0.0, 0.0]);
        $estilo->colorCabecera = self::hexARgb((string)($valores['color_cabecera'] ?? ''), [0.95, 0.95, 0.95]);
        $estilo->filasAlternas = self::aBooleano($valores['filas_alternas'] ?? null, true);
        $estilo->fuente = self::sanearFuente((string)($valores['fuente'] ?? ''));
        $estilo->tamanoBase = self::sanearTamano($valores['tamano_base'] ?? null);
        $estilo->papel = self::sanearPapel((string)($valores['papel'] ?? ''));
        $estilo->cabeceraPosicion = self::sanearPosicionCabecera((string)($valores['cabecera_posicion'] ?? ''));
        $estilo->logoTamano = self::sanearEntero($valores['logo_tamano'] ?? null, 20, 90, 50);
        $estilo->empresaNombreTamano = self::sanearEntero($valores['empresa_nombre_tamano'] ?? null, 8, 18, 11);
        $estilo->empresaDatosTamano = self::sanearEntero($valores['empresa_datos_tamano'] ?? null, 6, 12, 8);
        $estilo->cabeceraEspaciado = self::sanearEntero($valores['cabecera_espaciado'] ?? null, 0, 60, 8);
        $estilo->totalesPosicion = self::sanearPosicionTotales((string)($valores['totales_posicion'] ?? ''));
        $estilo->mostrarContactoEmpresa = self::aBooleano($valores['mostrar_contacto_empresa'] ?? null, false);
        $estilo->ocultarTipoDocumento = self::aBooleano($valores['ocultar_tipo_documento'] ?? null, false);
        $estilo->ocultarLogo = self::aBooleano($valores['ocultar_logo'] ?? null, false);
        $estilo->ocultarFormaPago = self::aBooleano($valores['ocultar_forma_pago'] ?? null, false);
        $estilo->ocultarIban = self::aBooleano($valores['ocultar_iban'] ?? null, false);
        $estilo->ocultarRecibos = self::aBooleano($valores['ocultar_recibos'] ?? null, false);
        $estilo->ocultarEnvio = self::aBooleano($valores['ocultar_envio'] ?? null, false);
        $estilo->ocultarReferencia = self::aBooleano($valores['ocultar_referencia'] ?? null, false);
        $estilo->ocultarDescuentos = self::aBooleano($valores['ocultar_descuentos'] ?? null, false);
        $estilo->ocultarNumeracion = self::aBooleano($valores['ocultar_numeracion'] ?? null, false);

        return $estilo;
    }

    /**
     * Casilla activa y motor ezPDF en uso (PlantillasPDF desactivado).
     */
    public static function estaActivo(): bool
    {
        return self::cargar()->aplicable();
    }

    /**
     * Este estilo concreto se aplica: activo y sin PlantillasPDF.
     */
    public function aplicable(): bool
    {
        return $this->activo && false === self::plantillasPdfActivo();
    }

    public static function plantillasPdfActivo(): bool
    {
        return Plugins::isEnabled(self::PLUGIN_PLANTILLAS);
    }

    /**
     * Primario del tema de ConfigAuralis (`tema_color_hex`) o, si no es un hex válido, el acento de reserva.
     */
    public static function colorAcentoPorDefecto(): string
    {
        $hexTema = self::normalizarHex((string)Tools::settings('auralis', 'tema_color_hex', ''));

        return $hexTema ?? self::COLOR_ACENTO_FALLBACK;
    }

    /**
     * Factor de escala de los tamaños del Core (pensados para 9 pt).
     */
    public function factorEscala(): float
    {
        return $this->tamanoBase / self::TAMANO_CORE;
    }

    /**
     * Texto blanco o negro según la luminancia del fondo (fórmula sRGB relativa).
     *
     * @param float[] $fondo RGB 0..1
     *
     * @return float[]
     */
    public static function colorTextoSobreFondo(array $fondo): array
    {
        $canales = [];
        foreach ([0, 1, 2] as $i) {
            $c = (float)($fondo[$i] ?? 0.0);
            $canales[$i] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        $luminancia = 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];

        return $luminancia < 0.4 ? [1.0, 1.0, 1.0] : [0.0, 0.0, 0.0];
    }

    /**
     * Mezcla un color con blanco: $proporcionBlanco = 1 devuelve blanco.
     *
     * @param float[] $rgb
     *
     * @return float[]
     */
    public static function mezclarConBlanco(array $rgb, float $proporcionBlanco): array
    {
        $p = max(0.0, min(1.0, $proporcionBlanco));
        $resultado = [];
        foreach ([0, 1, 2] as $i) {
            $resultado[$i] = round((float)($rgb[$i] ?? 0.0) * (1 - $p) + $p, 4);
        }

        return $resultado;
    }

    /**
     * Hex (#rgb o #rrggbb, con o sin #) a RGB 0..1; si no es válido devuelve $porDefecto.
     *
     * @param float[] $porDefecto
     *
     * @return float[]
     */
    public static function hexARgb(string $hex, array $porDefecto): array
    {
        $normalizado = self::normalizarHex($hex);
        if (null === $normalizado) {
            return $porDefecto;
        }

        return [
            round(hexdec(mb_substr($normalizado, 1, 2)) / 255, 4),
            round(hexdec(mb_substr($normalizado, 3, 2)) / 255, 4),
            round(hexdec(mb_substr($normalizado, 5, 2)) / 255, 4),
        ];
    }

    /**
     * Devuelve `#rrggbb` en minúsculas o null si el texto no es un color hex.
     */
    public static function normalizarHex(string $hex): ?string
    {
        $limpio = mb_strtolower(trim($hex));
        if (str_starts_with($limpio, '#')) {
            $limpio = mb_substr($limpio, 1);
        }

        if (1 === preg_match('/^[0-9a-f]{3}$/', $limpio)) {
            $limpio = $limpio[0] . $limpio[0] . $limpio[1] . $limpio[1] . $limpio[2] . $limpio[2];
        }

        return 1 === preg_match('/^[0-9a-f]{6}$/', $limpio) ? '#' . $limpio : null;
    }

    /**
     * Fuente que se usará al pintar: resuelve `tema` y cae a Helvetica si la TTF no está disponible
     * (tipografía `sistema` del tema, carpeta borrada…).
     */
    public function fuenteEfectiva(): string
    {
        $fuente = $this->fuente;
        if (self::FUENTE_TEMA === $fuente) {
            $fuente = (string)Tools::settings('auralis', 'tipografia', '');
        }

        if (in_array($fuente, self::FUENTES, true)) {
            return $fuente;
        }

        return self::esFuenteIncrustada($fuente) && self::ficherosFuenteDisponibles($fuente)
            ? $fuente
            : self::FUENTES[0];
    }

    public static function esFuenteIncrustada(string $fuente): bool
    {
        return isset(self::FUENTES_INCRUSTADAS[$fuente]);
    }

    /**
     * Carpeta con los TTF de una fuente incrustada (la misma que sus WOFF2 y su OFL.txt).
     */
    public static function carpetaFuente(string $fuente): string
    {
        return Tools::folder('Plugins', 'Auralis', 'Assets', 'Fonts', self::FUENTES_INCRUSTADAS[$fuente] ?? '');
    }

    /**
     * Nombre del TTF sin extensión, como lo espera Cpdf::selectFont() con fontPath en su carpeta.
     * Cpdf lo usa también como /BaseFont del PDF.
     */
    public static function nombreFuenteIncrustada(string $fuente, bool $negrita = false): string
    {
        $base = self::TTF_RENOMBRADOS[$fuente] ?? (self::FUENTES_INCRUSTADAS[$fuente] ?? '');

        return $base . ($negrita ? '-pdf-700' : '-pdf-400');
    }

    public static function ficherosFuenteDisponibles(string $fuente): bool
    {
        if (false === self::esFuenteIncrustada($fuente)) {
            return false;
        }

        $carpeta = self::carpetaFuente($fuente);
        foreach ([false, true] as $negrita) {
            if (false === is_file($carpeta . DIRECTORY_SEPARATOR . self::nombreFuenteIncrustada($fuente, $negrita) . '.ttf')) {
                return false;
            }
        }

        return true;
    }

    public static function sanearFuente(string $fuente): string
    {
        $fuente = mb_strtolower(trim($fuente));
        if (self::FUENTE_TEMA === $fuente || self::esFuenteIncrustada($fuente)) {
            return $fuente;
        }

        foreach (self::FUENTES as $permitida) {
            if ($fuente === mb_strtolower($permitida)) {
                return $permitida;
            }
        }

        return self::FUENTE_TEMA;
    }

    /**
     * @param mixed $tamano
     */
    public static function sanearTamano($tamano): int
    {
        if (false === is_numeric($tamano)) {
            return self::TAMANO_CORE;
        }

        return max(self::TAMANO_MIN, min(self::TAMANO_MAX, (int)round((float)$tamano)));
    }

    public static function sanearPapel(string $papel): string
    {
        $papel = mb_strtolower(trim($papel));

        return in_array($papel, self::PAPELES, true) ? $papel : self::PAPELES[0];
    }

    public static function sanearPosicionCabecera(string $posicion): string
    {
        return 'derecha' === mb_strtolower(trim($posicion)) ? 'derecha' : 'izquierda';
    }

    public static function sanearPosicionTotales(string $posicion): string
    {
        return 'pie_pagina' === mb_strtolower(trim($posicion)) ? 'pie_pagina' : 'despues_articulos';
    }

    /**
     * @param mixed $valor
     */
    private static function sanearEntero($valor, int $minimo, int $maximo, int $porDefecto): int
    {
        if (false === is_numeric($valor)) {
            return $porDefecto;
        }

        return max($minimo, min($maximo, (int)round((float)$valor)));
    }

    /**
     * @param mixed $valor
     */
    private static function aBooleano($valor, bool $porDefecto): bool
    {
        if (null === $valor || '' === $valor) {
            return $porDefecto;
        }

        $resultado = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $resultado ?? $porDefecto;
    }
}

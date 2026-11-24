<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Badge de listado para un % virtual (fieldname) con tono de Bootstrap.
 *              Atributos (todos opcionales):
 *              - tonefield (default {fieldname}_tone): campo del modelo con el tono. Si trae valor, manda.
 *              - titlekey: clave i18n del title del badge.
 *              - decimal (entero 0-6, default 1): decimales del %, con los separadores configurados.
 *              - tonesuccess, tonewarning, tonedanger, toneinfo, toneprimary, tonesecondary (número):
 *                umbrales. Cada uno vale desde ese % (incluido) hacia arriba y gana el más alto que el %
 *                alcanza. Solo se usan si el modelo no trae tono.
 *              Tonos: success, warning, danger, info, primary y secondary; «ok» es alias de success.
 *              Sin tono ni umbral alcanzado, warning.
 */

namespace FacturaScripts\Plugins\Auralis\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\WidgetText;
use FacturaScripts\Core\Tools;

class WidgetZPctBadge extends WidgetText
{
    /** Tonos admitidos ⇒ clases del badge. */
    private const CLASES_TONO = [
        'success' => 'badge bg-success-subtle text-success border border-success-subtle',
        'warning' => 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'danger' => 'badge bg-danger-subtle text-danger border border-danger-subtle',
        'info' => 'badge bg-info-subtle text-info-emphasis border border-info-subtle',
        'primary' => 'badge bg-primary-subtle text-primary-emphasis border border-primary-subtle',
        'secondary' => 'badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    ];

    /** Alias heredados (los consumidores anteriores a 1.74 devuelven ok|warning|danger). */
    private const ALIAS_TONO = ['ok' => 'success'];

    private const TONO_POR_DEFECTO = 'warning';

    private const MAX_DECIMALES = 6;

    /** Campo del modelo con el tone. Por defecto: {fieldname}_tone */
    protected string $tonefield = '';

    /** Clave i18n del title del badge (opcional). */
    protected string $titlekey = '';

    /** Decimales del % mostrado. */
    protected int $decimales = 1;

    /** @var array<string, float> tono => % desde el que se aplica, de mayor a menor */
    protected array $umbrales = [];

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->tonefield = (string) ($data['tonefield'] ?? '');
        $this->titlekey = (string) ($data['titlekey'] ?? '');

        $decimal = trim((string) ($data['decimal'] ?? ''));
        if (ctype_digit($decimal)) {
            $this->decimales = min((int) $decimal, self::MAX_DECIMALES);
        }

        foreach (array_keys(self::CLASES_TONO) as $tono) {
            $umbral = trim((string) ($data['tone' . $tono] ?? ''));
            if ($umbral !== '' && is_numeric($umbral)) {
                $this->umbrales[$tono] = (float) $umbral;
            }
        }
        arsort($this->umbrales);
    }

    /**
     * @param object $model
     * @param string $display
     */
    public function tableCell($model, $display = 'left')
    {
        $class = $this->combineClasses($this->tableCellClass('text-' . $display), $this->class);
        $pctRaw = $model->{$this->fieldname} ?? null;

        if ($pctRaw === null || $pctRaw === '') {
            return '<td class="' . $class . '"><span class="text-muted">-</span></td>';
        }

        $pct = (float) $pctRaw;
        $badgeClass = self::CLASES_TONO[$this->tono($model, $pct)];

        $label = Tools::number($pct, $this->decimales) . ' %';
        $title = $this->titlekey !== '' ? Tools::trans($this->titlekey) : '';

        $titleAttr = $title !== ''
            ? ' title="' . $this->escapeHtml($title) . '"'
            : '';

        return '<td class="' . $class . '">'
            . '<span class="' . $badgeClass . ' cancelClickable"' . $titleAttr . '>'
            . $this->escapeHtml($label)
            . '</span></td>';
    }

    /**
     * Tono del badge: el del modelo si trae uno válido; si no, el del umbral más alto alcanzado; si no, warning.
     *
     * @param object $model
     */
    private function tono($model, float $pct): string
    {
        $toneField = $this->tonefield !== '' ? $this->tonefield : $this->fieldname . '_tone';
        $tonoModelo = self::normalizarTono((string) ($model->{$toneField} ?? ''));
        if ($tonoModelo !== '') {
            return $tonoModelo;
        }

        foreach ($this->umbrales as $tono => $desde) {
            if ($pct >= $desde) {
                return $tono;
            }
        }

        return self::TONO_POR_DEFECTO;
    }

    /**
     * Tono admitido (alias resuelto) o '' si no viene o no se reconoce.
     */
    private static function normalizarTono(string $tono): string
    {
        $tono = strtolower(trim($tono));
        $tono = self::ALIAS_TONO[$tono] ?? $tono;

        if ($tono === '') {
            return '';
        }

        return isset(self::CLASES_TONO[$tono]) ? $tono : self::TONO_POR_DEFECTO;
    }
}

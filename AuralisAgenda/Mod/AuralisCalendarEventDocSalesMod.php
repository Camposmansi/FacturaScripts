<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Hidden idevent en cabecera de ventas para vincular el documento al evento de calendario.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Mod;

use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarEventDocLink;

class AuralisCalendarEventDocSalesMod implements SalesModInterface
{
    public function apply(SalesDocument &$model, array $formData): void
    {
        CalendarEventDocLink::stashIdeventFromForm($formData);
    }

    public function applyBefore(SalesDocument &$model, array $formData): void
    {
        CalendarEventDocLink::stashIdeventFromForm($formData);
    }

    public function assets(): void
    {
    }

    public function newBtnFields(): array
    {
        return [];
    }

    public function newFields(): array
    {
        return ['_auralis_idevent'];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function renderField(SalesDocument $model, string $field): ?string
    {
        if ($field !== '_auralis_idevent') {
            return null;
        }

        // Solo en alta: el vínculo se escribe en saveInsert.
        if (false === empty($model->id())) {
            return '';
        }

        $idevent = CalendarEventDocLink::peekStashedIdevent();
        if ($idevent <= 0) {
            return '';
        }

        return '<input type="hidden" name="' . CalendarEventDocLink::FORM_FIELD . '" value="' . $idevent . '"/>';
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Miniatura de la primera imagen del producto en listados. Tipo XML: zImageProd.
 *              Atributos opcionales: thumbwidth, thumbheight (default 75), variants (true|false).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\BaseWidget;

class WidgetZImageProd extends BaseWidget
{
    protected int $thumbWidth = 75;

    protected int $thumbHeight = 75;

    /** Si true, getImages() incluye imágenes de variantes. */
    protected bool $includeVariants = true;

    protected string $thumbnailUrl = '';

    protected string $productTitle = '';

    protected string $productRef = '';

    public function __construct(array $data)
    {
        parent::__construct($data);

        $w = (int) ($data['thumbwidth'] ?? 75);
        $h = (int) ($data['thumbheight'] ?? 75);
        $this->thumbWidth = $w > 0 ? $w : 75;
        $this->thumbHeight = $h > 0 ? $h : 75;

        $variants = strtolower((string) ($data['variants'] ?? 'true'));
        $this->includeVariants = !in_array($variants, ['0', 'false', 'no'], true);
    }

    /**
     * @param object $model
     */
    protected function setValue($model)
    {
        parent::setValue($model);

        $this->thumbnailUrl = '';
        $this->productTitle = '';
        $this->productRef = '';

        if (false === is_object($model) || false === method_exists($model, 'getImages')) {
            return;
        }

        $images = $model->getImages($this->includeVariants);
        if (empty($images) || false === is_array($images)) {
            return;
        }

        $first = $images[0];
        if (false === is_object($first) || false === method_exists($first, 'getThumbnail')) {
            return;
        }

        $url = (string) $first->getThumbnail($this->thumbWidth, $this->thumbHeight, true);
        if ($url !== '' && str_starts_with($url, '/')) {
            $url = FS_ROUTE . $url;
        }

        $this->thumbnailUrl = $url;
        $this->productTitle = (string) ($model->descripcion ?? '');
        $this->productRef = (string) ($model->referencia ?? '');
    }

    /**
     * @return string
     */
    protected function show()
    {
        if ($this->thumbnailUrl === '') {
            return '';
        }

        return '<img src="' . $this->escapeHtml($this->thumbnailUrl) . '"'
            . ' class="photo img-fluid"'
            . ' loading="lazy"'
            . ' title="' . $this->escapeHtml($this->productTitle) . '"'
            . ' alt="' . $this->escapeHtml($this->productRef) . '">';
    }
}

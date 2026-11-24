<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Eslabón de PDFDocument (motor ezPDF del Core) que aplica el estilo básico PDF de Auralis y oculta los datos elegidos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\PDF;

use FacturaScripts\Core\Lib\PDF\PDFDocument as PDFDocumentCore;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\AgenciaTransporte;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\FormaPago;
use FacturaScripts\Plugins\Auralis\Lib\PdfEstiloAuralis;

/**
 * Sustituye a Dinamic\Lib\PDF\PDFDocument (no a PDFExport) para no anular el PDFExport de otros plugins:
 * PDFExport (de quien sea) → Core PDFExport → este → Core PDFDocument. MAILExport hereda lo mismo.
 * Con el estilo desactivado (o PlantillasPDF activo) cada método delega en el Core sin cambios.
 */
abstract class PDFDocument extends PDFDocumentCore
{
    /** Columna del Core que concatena «referencia - descripción». */
    private const COLUMNA_REFERENCIA = 'referencia';

    /** Columna de vencimiento de la tabla de forma de pago del Core (insertInvoicePayMethod). */
    private const COLUMNA_VENCIMIENTO = 'expiration';

    /** @var PdfEstiloAuralis|null|false false = aún no resuelto en este documento */
    private $estiloAuralis = false;

    public function newPage(string $orientation = 'portrait', bool $forceNewPage = false)
    {
        $estilo = $this->estiloAuralis();
        if ($this->pdf !== null || null === $estilo) {
            parent::newPage($orientation, $forceNewPage);
            return;
        }

        // Réplica de PDFCore::newPage() con papel, tipografía y numeración configurables.
        $this->pdf = new AuralisCezpdf($estilo, $estilo->papel, $orientation);
        $this->pdf->addInfo('Creator', 'Auralis');
        $this->pdf->addInfo('Producer', 'Auralis');
        $this->pdf->addInfo('Title', $this->getFileName());
        $this->pdf->tempPath = FS_FOLDER . '/MyFiles/Cache';
        // selectFont escribe ahí la caché de la fuente; un deploy borra la carpeta.
        Tools::folderCheckOrCreate($this->pdf->tempPath);
        $this->pdf->aplicarFuente();

        $this->tableWidth = $this->pdf->ez['pageWidth'] - self::CONTENT_X * 2;

        if (false === $estilo->ocultarNumeracion) {
            // AuralisCezpdf escala FONT_SIZE al tamaño base al pintar los números.
            $this->pdf->ezStartPageNumbers(self::CONTENT_X, self::FOOTER_Y, self::FONT_SIZE, 'left', '{PAGENUM} / {TOTALPAGENUM}');
        }
    }

    /**
     * @param mixed $receipt
     */
    protected function getBankData($receipt): string
    {
        $estilo = $this->estiloAuralis();
        if (null === $estilo || false === $estilo->ocultarIban) {
            return parent::getBankData($receipt);
        }

        // Solo la forma de pago, sin cuenta bancaria.
        $formaPago = new FormaPago();
        return $formaPago->load($receipt->codpago) ? $formaPago->descripcion : '-';
    }

    protected function getLineHeaders(): array
    {
        $cabeceras = parent::getLineHeaders();
        $estilo = $this->estiloAuralis();
        if (null === $estilo) {
            return $cabeceras;
        }

        if ($estilo->ocultarReferencia && isset($cabeceras[self::COLUMNA_REFERENCIA])) {
            $cabeceras[self::COLUMNA_REFERENCIA]['title'] = Tools::trans('description');
        }

        if ($estilo->ocultarDescuentos) {
            unset($cabeceras['dtopor'], $cabeceras['dtopor2']);
        }

        return $cabeceras;
    }

    protected function insertBusinessDocBody($model)
    {
        $estilo = $this->estiloAuralis();
        if (null === $estilo || false === $estilo->ocultarReferencia || false === $this->pdf instanceof AuralisCezpdf) {
            parent::insertBusinessDocBody($model);
            return;
        }

        // El Core pinta «referencia - descripción» en una sola columna: se quita el prefijo fila a fila.
        $prefijos = [];
        foreach ($model->getLines() as $linea) {
            $prefijos[] = empty($linea->referencia) ? '' : Tools::fixHtml($linea->referencia . ' - ');
        }

        $this->pdf->quitarPrefijosColumna(self::COLUMNA_REFERENCIA, $prefijos);
        try {
            parent::insertBusinessDocBody($model);
        } finally {
            $this->pdf->limpiarPrefijosColumna(self::COLUMNA_REFERENCIA);
        }
    }

    /**
     * Cabecera del documento con la misma composición compacta de la vista previa de Auralis.
     */
    protected function insertBusinessDocHeader($model)
    {
        $estilo = $this->estiloAuralis();
        if (null === $estilo) {
            parent::insertBusinessDocHeader($model);
            return;
        }

        $header = [
            'title' => $this->i18n->trans($model->modelClassName() . '-min'),
            'subject' => $this->i18n->trans('customer'),
            'field' => 'nombrecliente',
        ];
        if (isset($model->codproveedor)) {
            $header['subject'] = $this->i18n->trans('supplier');
            $header['field'] = 'nombre';
        }
        if (!empty($this->format->titulo)) {
            $header['title'] = Tools::fixHtml($this->format->titulo);
        }

        $acento = $estilo->colorAcento;
        $tituloDocumento = $estilo->ocultarTipoDocumento ? '' : $header['title'] . ' ';
        // La vista previa usa 1,67 em para el título: 15 sobre el cuerpo Core de 9.
        $titleSize = self::FONT_SIZE + 6;
        $titleY = $this->pdf->y - $titleSize;
        $this->pdf->setColor($acento[0], $acento[1], $acento[2], 1);
        $this->pdf->addText(
            self::CONTENT_X,
            $titleY,
            $titleSize,
            '<b>' . $tituloDocumento . Tools::fixHtml((string)$model->codigo) . '</b>',
            $this->tableWidth,
            'left'
        );
        $this->pdf->setColor($estilo->colorTexto[0], $estilo->colorTexto[1], $estilo->colorTexto[2], 1);
        // ezPDF posiciona el texto por su línea base. Dejamos 7 puntos hasta la regla
        // para reproducir el margen visual de 0,2em de la vista previa sin rozar la negrita.
        $lineY = $titleY - 7;
        $this->pdf->line(self::CONTENT_X, $lineY, self::CONTENT_X + $this->tableWidth, $lineY);
        $this->pdf->y = $lineY - 4;

        $subject = $model->getSubject();
        $tipoId = empty($subject->tipoidfiscal) ? $this->i18n->trans('cifnif') : $subject->tipoidfiscal;
        $izquierda = '<b>' . $header['subject'] . ':</b> ' . Tools::fixHtml((string)$model->{$header['field']});
        $izquierda .= "\n" . Tools::fixHtml($tipoId) . ': ' . Tools::fixHtml((string)$model->cifnif);
        $direccion = $this->getDocAddress($subject, $model);
        if ('' !== trim($direccion)) {
            $izquierda .= "\n" . Tools::fixHtml($direccion);
        }

        $derecha = '<b>' . $this->i18n->trans('date') . ':</b> ' . Tools::fixHtml((string)$model->fecha);
        if (false === $estilo->ocultarFormaPago) {
            $formaPago = new FormaPago();
            if (property_exists($model, 'codpago') && !empty($model->codpago) && $formaPago->load($model->codpago)) {
                $derecha .= "\n<b>" . $this->i18n->trans('payment-method') . ':</b> ' . Tools::fixHtml($formaPago->descripcion);
            }
        }

        $rows = [['left' => $izquierda, 'right' => $derecha]];
        $cols = ['left' => '', 'right' => ''];
        $this->pdf->ezTable($rows, $cols, '', [
            'width' => $this->tableWidth,
            'showHeadings' => 0,
            'shaded' => 0,
            'lineCol' => [1, 1, 1],
            'cols' => [
                'left' => ['justification' => 'left', 'width' => $this->tableWidth * 0.64],
                'right' => ['justification' => 'right', 'width' => $this->tableWidth * 0.36],
            ],
        ]);
        $this->pdf->y -= 5;
    }

    /**
     * Pie funcional de Auralis: resumen vertical a la derecha, recibos y dirección de envío.
     */
    protected function insertBusinessDocFooter($model)
    {
        $estilo = $this->estiloAuralis();
        if (null === $estilo) {
            parent::insertBusinessDocFooter($model);
            return;
        }

        $exceptions = $this->getTaxExceptions($model);
        if (!empty($exceptions)) {
            $this->newPage();
            $this->pdf->ezText('<b>' . $this->i18n->trans('tax-exceptions') . '</b>', self::FONT_SIZE);
            foreach ($exceptions as $exception) {
                $this->pdf->ezText('- ' . Tools::fixHtml($exception), self::FONT_SIZE);
            }
        }

        if (!empty($model->observaciones)) {
            $this->newPage();
            $this->pdf->ezText('<b>' . $this->i18n->trans('observations') . '</b>', self::FONT_SIZE);
            $this->pdf->ezText(Tools::fixHtml($model->observaciones), self::FONT_SIZE);
        }

        $this->newPage();
        $totalesAlPie = 'pie_pagina' === $estilo->totalesPosicion;
        if ($totalesAlPie) {
            $this->prepararBloqueFinalAlPie($model, $estilo);
        }
        $this->insertAuralisTotals($model);

        if ($model->modelClassName() === 'FacturaCliente') {
            $recibos = method_exists($model, 'getReceipts') ? $model->getReceipts() : [];
            if (!empty($recibos) && false === $estilo->ocultarRecibos) {
                $this->pdf->ezText("\n<b>" . $this->i18n->trans('receipts') . '</b>', self::FONT_SIZE);
            }
            $this->insertInvoiceReceipts($model);
        } elseif (isset($model->codcliente)) {
            $this->insertInvoicePayMethod($model);
        }

        if (!empty($model->idcontactoenv) && ($model->idcontactoenv != $model->idcontactofact || !empty($model->codtrans))) {
            $this->insertBusinessDocShipping($model);
        }

        if (!empty($this->format->texto)) {
            $this->pdf->ezText("\n" . Tools::fixHtml($this->format->texto), self::FONT_SIZE);
        }

    }

    /**
     * Resumen vertical de importes, tras las líneas o como último bloque al pie de la página.
     */
    private function insertAuralisTotals($model): void
    {
        $anchoTotales = round($this->tableWidth * 0.48, 2);
        $xTotales = $this->pdf->ez['pageWidth'] - $this->pdf->ez['rightMargin'] - ($anchoTotales / 2);
        $rows = [
            ['label' => $this->i18n->trans('net'), 'value' => Tools::number($model->neto)],
            ['label' => $this->i18n->trans('taxes'), 'value' => Tools::number($model->totaliva)],
        ];
        if ((float)$model->totalrecargo !== 0.0) {
            $rows[] = ['label' => $this->i18n->trans('re'), 'value' => Tools::number($model->totalrecargo)];
        }
        if ((float)$model->totalirpf !== 0.0) {
            $rows[] = ['label' => $this->i18n->trans('retention'), 'value' => Tools::number(0 - $model->totalirpf)];
        }
        if ((float)$model->totalsuplidos !== 0.0) {
            $rows[] = ['label' => $this->i18n->trans('supplied-amount'), 'value' => Tools::number($model->totalsuplidos)];
        }
        $rows[] = ['label' => '<b>' . $this->i18n->trans('total') . '</b>', 'value' => '<b>' . Tools::number($model->total) . '</b>'];
        $this->pdf->ezTable($rows, ['label' => '', 'value' => ''], '', [
            'width' => $anchoTotales,
            'xPos' => $xTotales,
            'showHeadings' => 0,
            'shaded' => 0,
            'cols' => [
                'label' => ['justification' => 'left', 'width' => $anchoTotales * 0.58],
                'value' => ['justification' => 'right', 'width' => $anchoTotales * 0.42],
            ],
        ]);
    }

    /**
     * Baja como una unidad el bloque formado por totales, recibos, envío y texto final.
     * Si no cabe completo, lo lleva a una página nueva y conserva la cabecera de empresa.
     */
    private function prepararBloqueFinalAlPie($model, PdfEstiloAuralis $estilo): void
    {
        $filasTotales = 3;
        foreach (['totalrecargo', 'totalirpf', 'totalsuplidos'] as $campo) {
            if ((float)($model->{$campo} ?? 0) !== 0.0) {
                ++$filasTotales;
            }
        }
        $alto = 8 + ($filasTotales * 15);

        if ($model->modelClassName() === 'FacturaCliente') {
            $recibos = method_exists($model, 'getReceipts') ? $model->getReceipts() : [];
            if ($estilo->ocultarRecibos) {
                $alto += 42;
            } elseif (!empty($recibos)) {
                $alto += 30 + (count($recibos) * 15);
            }
        } elseif (isset($model->codcliente)) {
            $alto += 42;
        }

        $mostrarEnvio = false === $estilo->ocultarEnvio
            && !empty($model->idcontactoenv)
            && ($model->idcontactoenv != $model->idcontactofact || !empty($model->codtrans));
        if ($mostrarEnvio) {
            $filasEnvio = 2 + (!empty($model->codtrans) ? 1 : 0) + (!empty($model->codigoenv) ? 1 : 0);
            $alto += 28 + ($filasEnvio * 13);
        }

        if (!empty($this->format->texto)) {
            $alto += 16 + (int)ceil(mb_strlen((string)$this->format->texto) / 85) * 12;
        }

        $objetivoY = 42 + $alto;
        if ($this->pdf->y < $objetivoY + 12) {
            $this->pdf->ezNewPage();
            $this->insertedHeader = false;
            $this->insertHeader($model->idempresa ?? null);
        }
        $this->pdf->y = min($this->pdf->y, $objetivoY);
    }

    protected function insertBusinessDocShipping($model)
    {
        $estilo = $this->estiloAuralis();
        if (null !== $estilo && $estilo->ocultarEnvio) {
            return;
        }

        if (null !== $estilo) {
            $contacto = new Contacto();
            if (false === $contacto->load($model->idcontactoenv)) {
                return;
            }

            $acento = $estilo->colorAcento;
            $this->pdf->setColor($acento[0], $acento[1], $acento[2], 1);
            $this->pdf->ezText("\n" . $this->i18n->trans('shipping-address'), self::FONT_SIZE + 3);
            $this->pdf->setColor($estilo->colorTexto[0], $estilo->colorTexto[1], $estilo->colorTexto[2], 1);

            $carrier = new AgenciaTransporte();
            $carrierName = $carrier->load($model->codtrans) ? $carrier->nombre : '-';
            $rows = [
                ['key' => '<b>' . $this->i18n->trans('name') . '</b>', 'value' => Tools::fixHtml(trim($contacto->nombre . ' ' . $contacto->apellidos))],
                ['key' => '<b>' . $this->i18n->trans('address') . '</b>', 'value' => Tools::fixHtml($this->combineAddress($contacto))],
            ];
            if (!empty($model->codtrans)) {
                $rows[] = ['key' => '<b>' . $this->i18n->trans('carrier') . '</b>', 'value' => Tools::fixHtml($carrierName)];
            }
            if (!empty($model->codigoenv)) {
                $rows[] = ['key' => '<b>' . $this->i18n->trans('tracking-code') . '</b>', 'value' => Tools::fixHtml($model->codigoenv)];
            }
            $this->pdf->ezTable($rows, ['key' => '', 'value' => ''], '', [
                'width' => $this->tableWidth,
                'showHeadings' => 0,
                'shaded' => 0,
                'lineCol' => [1, 1, 1],
                'cols' => [
                    'key' => ['justification' => 'left', 'width' => $this->tableWidth * 0.16],
                    'value' => ['justification' => 'left', 'width' => $this->tableWidth * 0.84],
                ],
            ]);
            return;
        }

        parent::insertBusinessDocShipping($model);
    }

    /**
     * Cabecera de empresa compacta. Evita el gran bloque superior del formato nativo.
     */
    protected function insertHeader($idempresa = null)
    {
        $estilo = $this->estiloAuralis();
        if (null === $estilo) {
            parent::insertHeader($idempresa);
            return;
        }
        if ($this->insertedHeader) {
            return;
        }
        $this->insertedHeader = true;

        $company = new Empresa();
        $code = $idempresa ?? Tools::settings('default', 'idempresa', '');
        if (false === $company->load($code)) {
            return;
        }

        $inicioY = $this->pdf->y;
        $logo = $estilo->ocultarLogo
            ? ['drawn' => false, 'width' => 0.0, 'height' => 0.0]
            : $this->logoCompacto($this->format->idlogo ?? $company->idlogo, $estilo->logoTamano);
        $aLaDerecha = 'derecha' === $estilo->cabeceraPosicion;
        if ($aLaDerecha) {
            $textoX = self::CONTENT_X + ($this->tableWidth * 0.50);
            $textoWidth = $this->tableWidth * 0.50;
            $justification = 'right';
        } else {
            $textoX = self::CONTENT_X + ($logo['drawn'] ? $logo['width'] + 8 : 0);
            $textoWidth = $this->tableWidth - ($textoX - self::CONTENT_X);
            $justification = 'left';
        }

        $acento = $estilo->colorAcento;
        $this->pdf->setColor($acento[0], $acento[1], $acento[2], 1);
        $this->pdf->addText(
            $textoX,
            $inicioY - $estilo->empresaNombreTamano,
            $estilo->empresaNombreTamano,
            '<b>' . Tools::fixHtml($company->nombre) . '</b>',
            $textoWidth,
            $justification
        );
        $this->pdf->setColor($estilo->colorTexto[0], $estilo->colorTexto[1], $estilo->colorTexto[2], 1);

        $direccion = trim((string)$company->direccion);
        $localidad = trim((string)$company->codpostal . ' ' . (string)$company->ciudad);
        if ('' !== $localidad) {
            $direccion .= ('' === $direccion ? '' : "\n") . $localidad;
        }
        $datos = Tools::fixHtml($direccion);
        if (!empty($company->cifnif)) {
            $datos .= ('' === $datos ? '' : "\n") . $this->i18n->trans('cifnif') . ': ' . Tools::fixHtml($company->cifnif);
        }
        if ($estilo->mostrarContactoEmpresa) {
            $contacto = [];
            foreach (['telefono1', 'telefono2', 'email', 'web'] as $campo) {
                if (!empty($company->{$campo})) {
                    $contacto[] = Tools::fixHtml((string)$company->{$campo});
                }
            }
            if (!empty($contacto)) {
                $datos .= ('' === $datos ? '' : "\n") . implode(' · ', $contacto);
            }
        }
        $lineas = preg_split('/\R/u', $datos) ?: [];
        $lineY = $inicioY - $estilo->empresaNombreTamano - $estilo->empresaDatosTamano - 2;
        $lineasPintadas = 0;
        foreach ($lineas as $linea) {
            if ('' === trim($linea)) {
                continue;
            }
            $this->pdf->addText($textoX, $lineY, $estilo->empresaDatosTamano, $linea, $textoWidth, $justification);
            $lineY -= $estilo->empresaDatosTamano + 1;
            ++$lineasPintadas;
        }
        $altoTexto = $estilo->empresaNombreTamano + 4 + ($lineasPintadas * ($estilo->empresaDatosTamano + 1));
        $this->pdf->y = $inicioY - max($logo['height'], $altoTexto) - $estilo->cabeceraEspaciado;
    }

    /**
     * Dibuja el logotipo de empresa o la reserva de Auralis en el bloque compacto.
     *
     * @return array{drawn: bool, width: float, height: float}
     */
    private function logoCompacto($idfile, int $altoMaximo): array
    {
        $attached = new AttachedFile();
        $path = '';
        $attachedLogo = false;
        if ($idfile && $attached->load($idfile) && is_file($attached->path)) {
            $path = $attached->path;
            $attachedLogo = true;
        } else {
            $path = Tools::folder('Plugins', 'Auralis', 'Assets', 'Images', 'auralis_icono-512.png');
        }
        if (false === is_file($path)) {
            return ['drawn' => false, 'width' => 0.0, 'height' => 0.0];
        }

        $size = @getimagesize($path);
        if (false === $size || empty($size[0]) || empty($size[1])) {
            return ['drawn' => false, 'width' => 0.0, 'height' => 0.0];
        }
        $anchoMaximo = min($this->tableWidth * 0.42, $altoMaximo * 3.2);
        $factor = min($anchoMaximo / $size[0], $altoMaximo / $size[1]);
        $width = $size[0] * $factor;
        $height = $size[1] * $factor;
        $x = self::CONTENT_X;
        $y = $this->pdf->y - $height;
        if ($attachedLogo) {
            $this->addImageFromAttachedFile($attached, $x, $y, $width, $height);
        } else {
            $this->addImageFromFile($path, $x, $y, $width, $height);
        }
        return ['drawn' => true, 'width' => $width, 'height' => $height];
    }

    protected function insertCompanyLogo($idfile = 0)
    {
        $estilo = $this->estiloAuralis();
        if (null !== $estilo && $estilo->ocultarLogo) {
            // Mismo margen que deja el Core tras el logo.
            $this->pdf->y -= 20;
            return;
        }

        // Un logotipo configurado por la empresa siempre tiene prioridad.
        $logo = new AttachedFile();
        if ($idfile !== 0 && $logo->load($idfile) && is_file($logo->path)) {
            parent::insertCompanyLogo($idfile);
            return;
        }

        // El fallback del Core es el logotipo de FacturaScripts. Con el estilo Auralis activo
        // usamos la marca del portal para que el documento impreso coincida con la vista previa.
        $logoPath = Tools::folder('Plugins', 'Auralis', 'Assets', 'Images', 'auralis_icono-512.png');
        if (false === is_file($logoPath)) {
            parent::insertCompanyLogo(0);
            return;
        }

        $logoSize = $this->calcImageSize($logoPath);
        $xPos = $this->pdf->ez['leftMargin'];
        $yPos = $this->pdf->ez['pageHeight'] - $logoSize['height'] - $this->pdf->ez['topMargin'];
        $this->addImageFromFile($logoPath, $xPos, $yPos, $logoSize['width'], $logoSize['height']);
        $this->pdf->y -= 20;
    }

    protected function insertInvoiceReceipts($invoice)
    {
        $estilo = $this->estiloAuralis();
        if (null !== $estilo && $estilo->ocultarRecibos) {
            // Tabla de forma de pago normal (la de otros documentos); respeta overrides de plugins superiores.
            // El Core toma el vencimiento de `finoferta`, que las facturas no tienen: se rellena con el de los recibos.
            $vencimiento = $this->vencimientoRecibos($invoice);
            $rellenar = '' !== $vencimiento && $this->pdf instanceof AuralisCezpdf;
            if ($rellenar) {
                $this->pdf->rellenarColumnaVacia(self::COLUMNA_VENCIMIENTO, $vencimiento);
            }

            try {
                $this->insertInvoicePayMethod($invoice);
            } finally {
                if ($rellenar) {
                    $this->pdf->limpiarRellenoColumna(self::COLUMNA_VENCIMIENTO);
                }
            }
            return;
        }

        if (null !== $estilo) {
            $receipts = $invoice->getReceipts();
            if (empty($receipts)) {
                return;
            }
            $headers = [
                'numero' => $this->i18n->trans('receipt'),
                'bank' => $this->i18n->trans('payment-method'),
                'importe' => $this->i18n->trans('amount'),
                'vencimiento' => $this->i18n->trans('expiration'),
            ];
            $rows = [];
            foreach ($receipts as $receipt) {
                $payLink = $receipt->url('pay');
                $rows[] = [
                    'numero' => $receipt->numero,
                    'bank' => empty($payLink) ? $this->getBankData($receipt) : '<c:alink:' . $payLink . '>'
                        . $this->i18n->trans('pay') . '</c:alink>',
                    'importe' => Tools::number($receipt->importe),
                    'vencimiento' => $receipt->pagado ? $this->i18n->trans('paid') : $receipt->vencimiento,
                ];
            }
            $this->pdf->ezTable($rows, $headers, '', [
                'width' => $this->tableWidth,
                'cols' => [
                    'numero' => ['justification' => 'center'],
                    'bank' => ['justification' => 'center'],
                    'importe' => ['justification' => 'right'],
                    'vencimiento' => ['justification' => 'right'],
                ],
            ]);
            return;
        }

        parent::insertInvoiceReceipts($invoice);
    }

    /**
     * Vencimiento del último recibo: es la fecha límite para pagar la factura completa.
     * Cadena vacía si la factura no tiene recibos.
     *
     * @param mixed $invoice
     */
    protected function vencimientoRecibos($invoice): string
    {
        if (false === method_exists($invoice, 'getReceipts')) {
            return '';
        }

        $ultimo = '';
        $ultimoTs = null;
        foreach ($invoice->getReceipts() as $recibo) {
            $fecha = (string)($recibo->vencimiento ?? '');
            $ts = '' === $fecha ? false : strtotime($fecha);
            if (false !== $ts && (null === $ultimoTs || $ts > $ultimoTs)) {
                $ultimo = $fecha;
                $ultimoTs = $ts;
            }
        }

        return $ultimo;
    }

    /**
     * Estilo aplicable a este documento (una lectura de ajustes por documento) o null si no aplica.
     */
    protected function estiloAuralis(): ?PdfEstiloAuralis
    {
        if (false === $this->estiloAuralis) {
            $estilo = PdfEstiloAuralis::cargar();
            $this->estiloAuralis = $estilo->aplicable() ? $estilo : null;
        }

        return $this->estiloAuralis;
    }
}

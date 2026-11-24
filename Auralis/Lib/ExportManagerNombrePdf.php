<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Gestor de exportación de los listados con el nombre de Nombres de PDF: el Core no pasa título al
 * exportar desde un listado, así que el PDF (título interno, descarga y adjunto de email) salía como «file_NNNN».
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Dinamic\Lib\ExportManager;

class ExportManagerNombrePdf extends ExportManager
{
    /** @var string */
    private $nombre;

    /**
     * @param ExportManager $anterior gestor del controlador, del que se conserva la orientación
     */
    public function __construct(ExportManager $anterior, string $nombre)
    {
        parent::__construct();

        $this->orientation = $anterior->orientation;
        $this->nombre = $nombre;
    }

    /**
     * Solo pone el nombre cuando el Core no trae título; si lo trae (fichas, informes), manda el suyo.
     */
    public function newDoc(string $option, string $title = '', int $format = 0, string $lang = ''): void
    {
        parent::newDoc($option, '' === $title ? $this->nombre : $title, $format, $lang);
    }
}

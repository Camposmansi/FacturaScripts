<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Extensión global de los controladores Edit*: al exportar a PDF o enviar por email un documento
 * de compra o venta, sustituye el título (que el Core usa como nombre del archivo) por el nombre configurado.
 */

namespace FacturaScripts\Plugins\Auralis\Extension\Controller;

use Closure;
use FacturaScripts\Plugins\Auralis\Lib\PdfNombreArchivo;

class EditController
{
    /**
     * Se ejecuta tras el loadData() de cada vista y antes de execAfterAction('export'),
     * que es donde el Core genera el PDF con $this->title como nombre.
     */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            // comprobación más barata primero: solo nos interesa la exportación
            if ('export' !== (string)$this->request->inputOrQuery('action', '')) {
                return;
            }

            $opcion = strtoupper((string)$this->request->queryOrInput('option', ''));
            if ('PDF' !== $opcion && 'MAIL' !== $opcion) {
                return;
            }

            if ($viewName !== $this->mainTabName() || empty($view->model) || false === $view->model->exists()) {
                return;
            }

            // null = modelo no soportado, opción desactivada o error: se deja el nombre del Core
            $nombre = PdfNombreArchivo::construir($view->model);
            if (null !== $nombre) {
                $this->title = $nombre;
            }
        };
    }
}

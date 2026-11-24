<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Extensión global de listados: columnas ordenables y orden numérico de códigos vía settings Auralis,
 * botón «Tablero» (Kanban) en los listados de documentos y nombre del PDF al imprimir o enviar por email
 * documentos marcados (Nombres de PDF).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Extension\Controller;

use Closure;
use FacturaScripts\Core\Lib\ExtendedController\ListView;
use FacturaScripts\Plugins\Auralis\Lib\ExportManagerNombrePdf;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanBotonListado;
use FacturaScripts\Plugins\Auralis\Lib\OrdenacionListados;
use FacturaScripts\Plugins\Auralis\Lib\PdfNombreArchivo;

class ListController
{
    /**
     * Se ejecuta tras createViews() del controlador (pipe), con columnas y orderOptions ya cargados.
     */
    public function createViews(): Closure
    {
        return function () {
            if (false === OrdenacionListados::activarTodasColumnas()
                && false === OrdenacionListados::activarOrdenNaturalCodigos()) {
                return;
            }

            foreach ($this->views as $view) {
                if (false === $view instanceof ListView) {
                    continue;
                }

                OrdenacionListados::aplicarEnVista($view);
            }
        };
    }

    /**
     * Tras el loadData() de cada vista (pipe):
     * - Botón «Tablero» (Kanban) en los listados de documentos con su tablero activo (KanbanBotonListado).
     * - Antes de exportAction(): al imprimir o enviar por email documentos marcados en el listado, el gestor
     *   de exportación pasa a uno que da el nombre de Nombres de PDF. El Core no pasa título desde los
     *   listados y el PDF guarda su nombre al crearse, así que tiene que llegar antes de generarlo
     *   (título interno, descarga y adjunto de email).
     */
    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            $boton = KanbanBotonListado::boton($this->getClassName(), (string)$viewName, $view, $this->user);
            if (null !== $boton) {
                $this->tab($viewName)->addButton($boton);
            }

            // comprobación más barata primero: solo nos interesa la exportación
            if ('export' !== (string)$this->request->inputOrQuery('action', '')) {
                return;
            }

            $opcion = strtoupper((string)$this->request->queryOrInput('option', ''));
            if (('PDF' !== $opcion && 'MAIL' !== $opcion) || $viewName !== $this->active) {
                return;
            }

            // sin documentos marcados se imprime el listado entero: ese nombre no se toca
            $codigos = $this->request->request->getArray('codes');
            if (empty($codigos)) {
                return;
            }

            // el cursor ya está cargado y filtrado por el Core («solo sus datos»)
            $documentos = PdfNombreArchivo::documentosMarcados($view->cursor, $codigos);
            $nombre = PdfNombreArchivo::construirParaVarios($documentos);
            if (null !== $nombre) {
                $this->exportManager = new ExportManagerNombrePdf($this->exportManager, $nombre);
            }
        };
    }
}

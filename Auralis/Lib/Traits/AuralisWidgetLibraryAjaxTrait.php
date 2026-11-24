<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * @description Búsqueda y subida AJAX del widget Biblioteca para pantallas de ajustes cuyo campo no es columna de ninguna XMLView (ConfigAuralis y Agenda), con permisos, CSRF y validación del contenido real de la imagen.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Traits;

use FacturaScripts\Core\Lib\Widget\WidgetLibrary;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;

/**
 * El Core resuelve las acciones `widget-library-search` y `widget-library-upload` buscando el campo entre las
 * columnas de las XMLView. Si el widget se pinta desde Twig no hay columna y el Core devuelve una respuesta vacía.
 * Este trait sustituye ambas acciones (los controladores PanelController las llaman desde `execAfterAction`).
 *
 * El controlador que lo use debe:
 *  - llamar a `registerWidgetLibraryAssets()` en `createViews()`, antes del layout;
 *  - llamar a `execAfterActionBiblioteca($action)` al inicio de `execAfterAction()` y salir si devuelve false;
 *  - implementar los cuatro métodos abstractos (puntos de variación).
 */
trait AuralisWidgetLibraryAjaxTrait
{
    /**
     * Nombres de campo (`col_name` del widget) que aceptan búsqueda y subida. Cualquier otro devuelve respuesta vacía.
     *
     * @return list<string>
     */
    abstract protected function bibliotecaColumnasPermitidas(): array;

    /**
     * Valor `accept` del widget (p. ej. `.png,.jpg`): WidgetLibrary::files() filtra la lista por estas extensiones.
     */
    abstract protected function bibliotecaAccept(): string;

    /**
     * Indica si la extensión (minúsculas, sin punto) se admite en la subida de este controlador.
     */
    abstract protected function bibliotecaExtensionPermitida(string $extension): bool;

    /**
     * Id del adjunto seleccionado ahora mismo para el campo; 0 si no hay ninguno.
     */
    abstract protected function bibliotecaValorSeleccionado(string $colName): int;

    /**
     * Registra JS de biblioteca de archivos en cada carga (independiente de POST/CSRF).
     * Prioridad alta primero (Core), prioridad 0 después (parche CSRF). Debe registrarse
     * en createViews() ANTES de assetManager.get('js') del layout; si solo se registra
     * en WidgetLibrary::assets() durante el body, el Core JS llega tarde o no se carga.
     */
    protected function registerWidgetLibraryAssets(): void
    {
        $route = Tools::config('route');
        AssetManager::addJs(
            $route . '/Dinamic/Assets/JS/WidgetLibrary.js?v=' . Tools::date(),
            1
        );
        AssetManager::addJs(
            $route . '/Dinamic/Assets/JS/auralis-widget-library-csrf.js?v=1.33-wl3',
            0
        );
    }

    /**
     * Valida permisos y CSRF en las acciones AJAX del widget biblioteca (subida/búsqueda).
     * Devuelve false si ya ha respondido con un error; el controlador no debe continuar.
     *
     * @param string $action
     */
    protected function execAfterActionBiblioteca($action): bool
    {
        if (in_array($action, ['widget-library-search', 'widget-library-upload'], true)) {
            return $this->guardWidgetLibraryAjax();
        }

        return true;
    }

    protected function guardWidgetLibraryAjax(): bool
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            $this->responderErrorBiblioteca(Tools::trans('access-denied'));
            return false;
        }

        // Solo firma CSRF: no usar tokenExist()/validateFormToken().
        // Búsqueda y subida son AJAX repetibles y no deben invalidar el token de Guardar.
        $token = (string) $this->request->input('multireqtoken', '');
        if ($token === '') {
            Tools::log()->warning('invalid-request-widget-library-empty-token');
            $this->responderErrorBiblioteca(Tools::trans('invalid-csrf-token'));
            return false;
        }

        if (false === $this->multiRequestProtection->validate($token)) {
            Tools::log()->warning('invalid-request-widget-library');
            $this->responderErrorBiblioteca(Tools::trans('invalid-csrf-token'));
            return false;
        }

        return true;
    }

    protected function widgetLibrarySearchAction(): array
    {
        $colName = (string) $this->request->input('col_name', '');
        $widgetId = $this->request->input('widget_id', '');

        if ($colName === '' || false === in_array($colName, $this->bibliotecaColumnasPermitidas(), true)) {
            return $this->respuestaBibliotecaVacia();
        }

        $widget = $this->crearWidgetBiblioteca($colName);
        $files = $widget->files(
            $this->request->input('query', ''),
            $this->request->input('sort', '')
        );

        $selectedValue = $this->bibliotecaValorSeleccionado($colName);

        return [
            'html' => $widget->renderFileList($files, $selectedValue ?: null, $widgetId),
            'records' => count($files),
            'newToken' => $this->multiRequestProtection->newToken(),
        ];
    }

    protected function widgetLibraryUploadAction(): array
    {
        $colName = (string) $this->request->input('col_name', '');
        $widgetId = $this->request->input('widget_id', '');

        if ($colName === '' || false === in_array($colName, $this->bibliotecaColumnasPermitidas(), true)) {
            return $this->respuestaBibliotecaVacia();
        }

        $file = $this->request->file('file');
        if (empty($file)) {
            return $this->respuestaBibliotecaVacia();
        }

        // El nombre original es obligatorio: sin él no hay extensión que contrastar con el contenido.
        $uploadName = $file->getClientOriginalName();
        $extension = mb_strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));
        if ($uploadName === ''
            || false === AuralisSecurity::isSafePublicImageFilename($uploadName)
            || false === $this->bibliotecaExtensionPermitida($extension)) {
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return $this->respuestaBibliotecaVacia();
        }

        // Contenido real (MIME, tamaño, decodificación) ANTES de mover nada a MyFiles/ ni crear el AttachedFile.
        if (false === AuralisSecurity::esImagenPublicaSegura($file->getPathname(), $extension)) {
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return $this->respuestaBibliotecaVacia();
        }

        $widget = $this->crearWidgetBiblioteca($colName);

        $attachedFile = $widget->uploadFile($file);
        if (false === $attachedFile->exists()) {
            return $this->respuestaBibliotecaVacia();
        }

        // Defensa adicional: revalida el fichero ya guardado (delete() borra también el físico).
        $extensionAdjunto = mb_strtolower(pathinfo((string) $attachedFile->filename, PATHINFO_EXTENSION));
        if (false === $this->bibliotecaExtensionPermitida($extensionAdjunto)
            || false === AuralisSecurity::esImagenPublicaSegura($attachedFile->getFullPath(), $extensionAdjunto)) {
            $attachedFile->delete();
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return $this->respuestaBibliotecaVacia();
        }

        $files = $widget->files();
        return [
            'html' => $widget->renderFileList($files, (int) $attachedFile->idfile, $widgetId),
            'records' => count($files),
            'new_file' => (int) $attachedFile->idfile,
            'new_filename' => $attachedFile->shortFileName(),
            'newToken' => $this->multiRequestProtection->newToken(),
        ];
    }

    private function crearWidgetBiblioteca(string $colName): WidgetLibrary
    {
        return new WidgetLibrary([
            'type' => 'library',
            'fieldname' => $colName,
            'accept' => $this->bibliotecaAccept(),
            'children' => [],
        ]);
    }

    /**
     * Respuesta JSON vacía (sin lista) para la subida/listado de la Biblioteca cuando se rechaza o falla.
     *
     * @return array{html: string, records: int, newToken: string}
     */
    private function respuestaBibliotecaVacia(): array
    {
        return [
            'html' => '',
            'records' => 0,
            'newToken' => $this->multiRequestProtection->newToken(),
        ];
    }

    /**
     * Responde 403 en JSON (con token nuevo para que el JS pueda reintentar) y corta el render de la plantilla.
     */
    private function responderErrorBiblioteca(string $mensaje): void
    {
        $this->setTemplate(false);
        $this->response->setStatusCode(403)->json([
            'error' => $mensaje,
            'html' => '',
            'records' => 0,
            'newToken' => $this->multiRequestProtection->newToken(),
        ]);
    }
}

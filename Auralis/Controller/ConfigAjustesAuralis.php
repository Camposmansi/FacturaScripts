<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Panel de ajustes generales Auralis: avisos internos y ordenación de listados.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaEnvio;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanAjustes;
use FacturaScripts\Plugins\Auralis\Lib\OrdenacionListados;

class ConfigAjustesAuralis extends PanelController
{
    public function privateCore(&$response, $user, $permissions)
    {
        if ($this->active === 'ListAuralisTipoAgente') {
            $this->active = 'ListTipoAgente';
        }

        parent::privateCore($response, $user, $permissions);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-ajustes-generales';
        $data['icon'] = 'fa-solid fa-sliders';
        $data['ordernum'] = 20;

        return $data;
    }

    protected function createViews(): void
    {
        $this->addEditView('ConfigAuralisAvisos', 'Settings', 'auralis-avisos', 'fa-solid fa-triangle-exclamation')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->assignSettingsPanelUrl('ConfigAuralisAvisos');

        $this->addEditView('ConfigAuralisListados', 'Settings', 'auralis-ordenacion-listados', 'fa-solid fa-table-list')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->assignSettingsPanelUrl('ConfigAuralisListados');

        $this->addEditView('ConfigAuralisGestoria', 'Settings', 'auralis-gestoria-titulo', 'fa-solid fa-file-zipper')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->assignSettingsPanelUrl('ConfigAuralisGestoria');

        $this->addListView('ListTipoAgente', 'AuralisTipoAgente', 'auralis-tipos-agentes', 'fa-solid fa-id-badge')
            ->addSearchFields(['nombre', 'codigo'])
            ->addOrderBy(['nombre'], 'name', 1)
            ->addOrderBy(['idtipo'], 'code');

        $this->addHtmlView(
            'AuralisFuncionamiento',
            'ConfigAuralisFuncionamiento',
            'Settings',
            'Cómo funciona Auralis',
            'fa-solid fa-book-open'
        );
        $this->views['AuralisFuncionamiento']->settings['btnNew'] = false;
        $this->views['AuralisFuncionamiento']->settings['btnDelete'] = false;
        $this->views['AuralisFuncionamiento']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAjustesAuralis?activetab=AuralisFuncionamiento';
            }

            public function primaryDescription(): string
            {
                return 'Cómo funciona Auralis';
            }
        };

        // La ayuda comercial solo aparece aquí y siempre como última pestaña.
        $this->addHtmlView(
            'AuralisServicios',
            'ConfigAuralisServicios',
            'Settings',
            'auralis-te-ayudamos',
            'fa-solid fa-life-ring'
        );
        $this->views['AuralisServicios']->settings['btnNew'] = false;
        $this->views['AuralisServicios']->settings['btnDelete'] = false;
        $this->views['AuralisServicios']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAjustesAuralis?activetab=AuralisServicios';
            }

            public function primaryDescription(): string
            {
                return Tools::trans('auralis-te-ayudamos');
            }
        };
    }

    /**
     * Evita que los botones «Todos» / recargar del panel redirijan a EditSettings.
     */
    private function assignSettingsPanelUrl(string $viewName): void
    {
        $this->views[$viewName]->model = new class($viewName) extends Settings {
            private string $panelTab;

            public function __construct(string $panelTab)
            {
                parent::__construct();
                $this->panelTab = $panelTab;
            }

            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAjustesAuralis?activetab=' . $this->panelTab;
            }

            public function primaryDescription(): string
            {
                return match ($this->panelTab) {
                    'ConfigAuralisAvisos' => Tools::trans('auralis-avisos'),
                    'ConfigAuralisListados' => Tools::trans('auralis-ordenacion-listados'),
                    'ConfigAuralisGestoria' => Tools::trans('auralis-gestoria-titulo'),
                    default => Tools::trans($this->panelTab),
                };
            }
        };
    }

    /**
     * @param string $viewName
     * @param mixed $view
     */
    protected function loadData($viewName, $view): void
    {
        $this->hasData = true;

        if ($viewName === 'ConfigAuralisAvisos') {
            $this->loadAuralisAvisosSettings($view, $this->getAvisosDocumentoKeys());
            return;
        }

        if ($viewName === 'ConfigAuralisListados') {
            $this->loadAuralisAvisosSettings($view, $this->getOrdenacionListadosKeys());
            return;
        }

        if ($viewName === 'ConfigAuralisGestoria') {
            $this->loadGestoriaSettings($view);
            return;
        }

        if ($viewName === 'ListTipoAgente' || $viewName === 'ListAuralisTipoAgente') {
            $view->loadData('', []);
        }
    }

    /**
     * Carga el grupo Settings auralis_gestoria con los valores efectivos (500 documentos y 10 MB por defecto).
     */
    private function loadGestoriaSettings($view): void
    {
        $view->loadData(GestoriaEnvio::GRUPO_AJUSTES);
        $view->model->name = GestoriaEnvio::GRUPO_AJUSTES;
        $view->model->{GestoriaEnvio::AJUSTE_EMAIL} = GestoriaEnvio::emailPredeterminado();
        $view->model->{GestoriaEnvio::AJUSTE_MAX_DOCUMENTOS} = GestoriaEnvio::maxDocumentos();
        $view->model->{GestoriaEnvio::AJUSTE_MAX_MB} = GestoriaEnvio::maxMb();
    }

    /**
     * Carga el grupo Settings auralis_avisos y normaliza checkboxes booleanos.
     *
     * @param string[] $keys
     */
    private function loadAuralisAvisosSettings($view, array $keys): void
    {
        $view->loadData('auralis_avisos');
        $view->model->name = 'auralis_avisos';

        foreach ($keys as $key) {
            $valor = $view->model->{$key};
            if ($valor === null) {
                // sin valor guardado todas las casillas nacen encendidas (también los tableros Kanban)
                $view->model->{$key} = true;
                continue;
            }
            $view->model->{$key} = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * @return string[]
     */
    private function getAvisosDocumentoKeys(): array
    {
        return [
            'presupuesto_cliente',
            'pedido_cliente',
            'albaran_cliente',
            'factura_cliente',
            'presupuesto_proveedor',
            'pedido_proveedor',
            'albaran_proveedor',
            'factura_proveedor',
        ];
    }

    /**
     * @return string[]
     */
    private function getOrdenacionListadosKeys(): array
    {
        return array_merge(OrdenacionListados::clavesConfiguracion(), KanbanAjustes::claves());
    }
}

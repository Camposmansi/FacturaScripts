<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Controlador ConfigAuralis: lead magnet de dolores, diseño, estilo del PDF, nombres de PDF, login y orden del menú.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Lib\Widget\WidgetLibrary;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Translator;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\Auralis\Lib\AdminGeneralMenuGrouper;
use FacturaScripts\Plugins\Auralis\Lib\InformesMenuGrouper;
use FacturaScripts\Plugins\Auralis\Lib\MenuManager as AuralisMenuManager;
use FacturaScripts\Plugins\Auralis\Lib\PdfEstiloAuralis;
use FacturaScripts\Plugins\Auralis\Lib\PdfNombreArchivo;
use FacturaScripts\Plugins\Auralis\Lib\Traits\AuralisWidgetLibraryAjaxTrait;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use FacturaScripts\Plugins\Auralis\Model\AuralisMenuOrder;

class ConfigAuralis extends PanelController
{
    use AuralisWidgetLibraryAjaxTrait;

    /** Ajuste de la vista «PDF» con los datos que necesita la vista previa (PlantillasPDF, tipografía y acento del tema). */
    public const AJUSTE_PREVIEW = 'auralis_preview';

    /** Ajuste de la vista «Nombres de PDF» con el catálogo de variables que pinta su bloque. */
    public const AJUSTE_VARIABLES = 'auralis_variables';

    /** Campos de imagen del widget Biblioteca (diseño y login) que admiten búsqueda y subida AJAX. */
    private const COLUMNAS_BIBLIOTECA = [
        'custom_logo_id',
        'login_logo_id',
        'login_header_logo_id',
        'login_bg_image_id',
        'login_left_image_id',
    ];

    /** Accesos rápidos cuya visibilidad se configura por separado. */
    private const TOPBAR_VISIBILITY_SETTINGS = [
        'topbar_show_search',
        'topbar_show_dashboard',
        'topbar_show_calendar',
        'topbar_show_notifications',
        'topbar_show_help',
        'topbar_show_user',
    ];

    /** @var array */
    public $menuTree = [];

    /** @var bool */
    public $ordenMenuActivo = false;

    /** @var bool */
    public $agruparAdminGeneral = false;

    /** @var bool */
    public $agruparInformesMenu = false;

    /** @var bool */
    public $informesPluginActivo = false;

    /** @var array */
    public $savedOrders = [];

    /**
     * Devuelve los datos de configuración de la página en el menú de FacturaScripts.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-design';
        $data['icon'] = 'fa-solid fa-palette';
        $data['ordernum'] = 10;

        return $data;
    }

    protected function createViews()
    {
        $this->addHtmlView('Auralis', 'ConfigAuralis', 'Settings', 'auralis', 'fa-solid fa-palette');
        // La barra superior del PanelController lee btnNew de la primera pestaña: en ajustes no hay nada que crear.
        $this->setSettings('Auralis', 'btnNew', false);
        $this->views['Auralis']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralis?activetab=Auralis';
            }
        };

        // Estilo del PDF: toma el acento y la tipografía del tema, por eso vive junto a Diseño.
        // Pestaña nativa (EditView + Settings): se guarda con edit/insert del PanelController, no con «save».
        $this->addEditView('ConfigAuralisPdf', 'Settings', 'auralis-pdf', 'fa-solid fa-file-pdf')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->views['ConfigAuralisPdf']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralis?activetab=ConfigAuralisPdf';
            }

            public function primaryDescription(): string
            {
                return Tools::trans('auralis-pdf');
            }
        };

        // Nombres de PDF: patrón del nombre del archivo al imprimir o enviar por email (misma mecánica que el estilo).
        $this->addEditView('ConfigAuralisPdfNombre', 'Settings', 'auralis-pdf-nombre', 'fa-solid fa-file-signature')
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->views['ConfigAuralisPdfNombre']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralis?activetab=ConfigAuralisPdfNombre';
            }

            public function primaryDescription(): string
            {
                return Tools::trans('auralis-pdf-nombre');
            }
        };

        $this->addHtmlView('LoginAuralis', 'LoginAuralis', 'Settings', 'login-auralis', 'fa-solid fa-right-to-bracket');
        $this->views['LoginAuralis']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralis?activetab=LoginAuralis';
            }
        };

        $this->addHtmlView('OrdenMenuAuralis', 'OrdenMenuAuralis', 'Settings', 'orden-menu-auralis', 'fa-solid fa-bars-staggered');
        $this->views['OrdenMenuAuralis']->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralis?activetab=OrdenMenuAuralis';
            }
        };

        $this->registerWidgetLibraryAssets();
        $this->registerPdfAssets();
    }

    /**
     * Assets de las pestañas del PDF: variables clicables de «Nombres de PDF» y vista previa del estilo.
     * Los scripts no hacen nada si su bloque no está en la página, así que se registran siempre en esta pantalla.
     * La vista previa usa las mismas tipografías que el tema (auralis-fonts.css).
     */
    private function registerPdfAssets(): void
    {
        $route = Tools::config('route');
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/auralis-fonts.css?v=1.77');
        AssetManager::addCss($route . '/Dinamic/Assets/CSS/auralis-pdf-preview.css?v=2.16.3');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/auralis-pdf-nombre-variables.js?v=1.77');
        AssetManager::addJs($route . '/Dinamic/Assets/JS/auralis-pdf-preview.js?v=2.16.3');
    }

    /**
     * Intercepta y procesa las acciones antes del renderizado de las vistas.
     * Aquí guardamos las variables del formulario de configuración.
     *
     * @param string $action
     */
    protected function execPreviousAction($action = '')
    {
        $accionesPersonalizadas = ['save', 'save_login', 'save_orden_menu', 'reset_orden_menu'];
        $accionesAjaxMenu = ['save_orden_menu', 'reset_orden_menu'];
        // No usar isXmlHttpRequest(): no existe en Core\Request (FS 2025+/2026) y provoca
        // fatal → HTTP 500 HTML. Estas acciones solo se disparan por fetch AJAX del panel.
        $isAjaxMenu = $this->request->isMethod('POST')
            && in_array($action, $accionesAjaxMenu, true);

        // Solo validar token aquí en acciones de las pestañas HTML;
        // la pestaña Settings (edit/insert) lo valida el PanelController padre.
        if ($this->request->isMethod('POST') && in_array($action, $accionesPersonalizadas, true)) {
            if (false === $this->validateFormToken()) {
                if ($isAjaxMenu) {
                    // validateFormToken ya registra invalid-request o duplicated-request en el log
                    $this->jsonMenuOrderResponse('error', Tools::trans('duplicated-request'));
                }
                return false;
            }

            if (false === $this->permissions->allowUpdate) {
                Tools::log()->error(Tools::trans('access-denied'));
                if ($isAjaxMenu) {
                    $this->jsonMenuOrderResponse('error', Tools::trans('access-denied'));
                }
                return false;
            }

            if ($action === 'save') {
                $this->saveGeneralSettings();
            } elseif ($action === 'save_login') {
                $this->saveLoginSettings();
            } elseif ($action === 'save_orden_menu') {
                $this->saveOrdenMenu();
                // Cortar el pipeline: sin setTemplate(false)+json, run() pinta Twig encima
                // y el fetch recibe HTML/<!doctype> (a menudo HTTP 500) en vez de JSON.
                return false;
            } elseif ($action === 'reset_orden_menu') {
                $this->resetOrdenMenu();
                return false;
            }
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Valida permisos y CSRF en acciones AJAX del widget biblioteca (subida/búsqueda).
     */
    protected function execAfterAction($action)
    {
        if (false === $this->execAfterActionBiblioteca($action)) {
            return;
        }

        parent::execAfterAction($action);
    }

    /** @return list<string> */
    protected function bibliotecaColumnasPermitidas(): array
    {
        return self::COLUMNAS_BIBLIOTECA;
    }

    protected function bibliotecaAccept(): string
    {
        return AuralisSecurity::PUBLIC_IMAGE_ACCEPT;
    }

    protected function bibliotecaExtensionPermitida(string $extension): bool
    {
        return AuralisSecurity::isSafePublicImageExtension($extension);
    }

    protected function bibliotecaValorSeleccionado(string $colName): int
    {
        return (int) Tools::settings('auralis', $colName);
    }

    public function getLogoLibraryWidget(string $fieldname, $currentFileId = null, string $accept = AuralisSecurity::PUBLIC_IMAGE_ACCEPT): string
    {
        $widget = new WidgetLibrary([
            'type'      => 'library',
            'fieldname' => $fieldname,
            'accept'    => $accept,
            'icon'      => 'fa-solid fa-folder-open',
            'children'  => []
        ]);

        $fakeModel = new \stdClass();
        $fakeModel->{$fieldname} = (!empty($currentFileId) && is_numeric($currentFileId)) ? (int)$currentFileId : null;

        $html = $widget->edit($fakeModel, '', '');

        // Inyectar token en el contenedor del widget por si el modal se mueve fuera del form.
        if (null === $this->multiRequestProtection) {
            $this->multiRequestProtection = new MultiRequestProtection();
        }
        $token = $this->multiRequestProtection->newToken();
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return preg_replace(
            '/^(<div class="mb-3" id=")(\d+)(")/',
            '$1$2$3 data-multireqtoken="' . $safeToken . '"',
            $html,
            1
        ) ?? $html;
    }

    private function saveGeneralSettings()
    {
        // Desvincular logotipo: solo vaciar settings (el AttachedFile permanece en la Biblioteca).
        // Early-return: WidgetLibrary reenvía el hidden custom_logo_id con el id antiguo.
        if ($this->request->request->get('delete_logo')) {
            Tools::settingsSet('auralis', 'custom_logo_id', '');
            Tools::settingsSet('auralis', 'custom_logo', '');
            if (Tools::settingsSave()) {
                Tools::log()->notice(Tools::lang()->trans('configuracion-guardada-correctamente'));
            } else {
                Tools::log()->error(Tools::lang()->trans('error-guardar-configuracion'));
            }
            return;
        }

        // Procesar selección de logo desde la librería del Core
        $logoIdFromLibrary = $this->request->request->get('custom_logo_id');
        if (!empty($logoIdFromLibrary) && is_numeric($logoIdFromLibrary)) {
            $attached = new AttachedFile();
            if ($attached->load((int)$logoIdFromLibrary) && $this->assignPublicImageIfSafe($attached, 'custom_logo')) {
                // asignado
            }
        }

        // Procesar subida de nuevo logo si existe desde PC
        $this->subirImagenPublica('custom_logo', 'custom_logo');

        $modoMenu = Tools::noHtml((string)$this->request->request->get('modo_menu', 'horizontal'));
        $temaColor = Tools::noHtml((string)$this->request->request->get('tema_color', 'default'));
        $tipografia = Tools::noHtml((string)$this->request->request->get('tipografia', 'plus_jakarta_sans'));
        if (!in_array($tipografia, AuralisSecurity::TIPOGRAFIAS_LOCALES, true)) {
            $tipografia = 'plus_jakarta_sans';
        }
        $borderRadiusCards = (string)(int)$this->request->request->get('border_radius_cards', '12');
        $borderRadiusBotones = (string)(int)$this->request->request->get('border_radius_botones', '8');
        $borderRadiusInputs = (string)(int)$this->request->request->get('border_radius_inputs', '6');
        $fontSizeBase = (string)(int)$this->request->request->get('font_size_base', '16');
        $fontSizeH1 = (string)(int)$this->request->request->get('font_size_h1', '28');
        $fontSizeH2 = (string)(int)$this->request->request->get('font_size_h2', '24');
        $fontSizeH3 = (string)(int)$this->request->request->get('font_size_h3', '20');
        $fontSizeH4 = (string)(int)$this->request->request->get('font_size_h4', '18');
        $fontSizeH5 = (string)(int)$this->request->request->get('font_size_h5', '16');
        $fontSizeH6 = (string)(int)$this->request->request->get('font_size_h6', '14');
        $logoHeightSidebar = (string)(int)$this->request->request->get('logo_height_sidebar', '90');
        $logoHeightNavbar = (string)(int)$this->request->request->get('logo_height_navbar', '40');
        $mobileBreakpoint = (string)(int)$this->request->request->get('mobile_breakpoint', '990');

        $customPrimary = $this->request->request->get('custom_primary', '#10b981');
        $customSidebar = $this->request->request->get('custom_sidebar', '#0f172a');
        $customAccent  = $this->request->request->get('custom_accent', '#6366f1');
        $customSuccess = $this->request->request->get('custom_success', '#198754');
        $customDanger = $this->request->request->get('custom_danger', '#dc3545');
        $customWarning = $this->request->request->get('custom_warning', '#ffc107');
        $customInfo = $this->request->request->get('custom_info', '#0dcaf0');
        $customLight = $this->request->request->get('custom_light', '#f8f9fa');
        $customDark = $this->request->request->get('custom_dark', '#212529');

        $hexRegex = '/^#[0-9A-Fa-f]{6}$/';
        $customPrimary = preg_match($hexRegex, $customPrimary) ? $customPrimary : '#10b981';
        $customSidebar = preg_match($hexRegex, $customSidebar) ? $customSidebar : '#0f172a';
        $customAccent  = preg_match($hexRegex, $customAccent)  ? $customAccent  : '#6366f1';
        $customSuccess = preg_match($hexRegex, $customSuccess) ? $customSuccess : '#198754';
        $customDanger = preg_match($hexRegex, $customDanger) ? $customDanger : '#dc3545';
        $customWarning = preg_match($hexRegex, $customWarning) ? $customWarning : '#ffc107';
        $customInfo = preg_match($hexRegex, $customInfo) ? $customInfo : '#0dcaf0';
        $customLight = preg_match($hexRegex, $customLight) ? $customLight : '#f8f9fa';
        $customDark = preg_match($hexRegex, $customDark) ? $customDark : '#212529';

        [$pr, $pg, $pb] = $this->hexToRgb($customPrimary);
        [$sr, $sg, $sb] = $this->hexToRgb($customSidebar);
        [$ar, $ag, $ab] = $this->hexToRgb($customAccent);
        [$sucr, $sucg, $sucb] = $this->hexToRgb($customSuccess);
        [$dangr, $dangg, $dangb] = $this->hexToRgb($customDanger);
        [$warr, $warg, $warb] = $this->hexToRgb($customWarning);
        [$infr, $infg, $infb] = $this->hexToRgb($customInfo);
        [$lightr, $lightg, $lightb] = $this->hexToRgb($customLight);
        [$darkr, $darkg, $darkb] = $this->hexToRgb($customDark);

        $primaryHover = $this->darkenHex($customPrimary, 10);
        $sidebarText = $this->getContrastColor($sr, $sg, $sb);
        // Texto sobre fondos *-subtle (alertas/badges): ~60% hacia negro ≈ BS5.3 text-emphasis.
        $successTextEmphasis = $this->darkenHex($customSuccess, 60);
        $dangerTextEmphasis = $this->darkenHex($customDanger, 60);
        $warningTextEmphasis = $this->darkenHex($customWarning, 60);
        $infoTextEmphasis = $this->darkenHex($customInfo, 60);

        Tools::settingsSet('auralis', 'modo_menu', $modoMenu);
        Tools::settingsSet('auralis', 'tema_color', $temaColor);
        Tools::settingsSet('auralis', 'tipografia', $tipografia);
        Tools::settingsSet('auralis', 'border_radius_cards', $borderRadiusCards);
        Tools::settingsSet('auralis', 'border_radius_botones', $borderRadiusBotones);
        Tools::settingsSet('auralis', 'border_radius_inputs', $borderRadiusInputs);
        Tools::settingsSet('auralis', 'font_size_base', $fontSizeBase);
        Tools::settingsSet('auralis', 'font_size_h1', $fontSizeH1);
        Tools::settingsSet('auralis', 'font_size_h2', $fontSizeH2);
        Tools::settingsSet('auralis', 'font_size_h3', $fontSizeH3);
        Tools::settingsSet('auralis', 'font_size_h4', $fontSizeH4);
        Tools::settingsSet('auralis', 'font_size_h5', $fontSizeH5);
        Tools::settingsSet('auralis', 'font_size_h6', $fontSizeH6);
        Tools::settingsSet('auralis', 'logo_height_sidebar', $logoHeightSidebar);
        Tools::settingsSet('auralis', 'logo_height_navbar', $logoHeightNavbar);
        Tools::settingsSet(
            'auralis',
            'menu_horizontal_hide_empresa',
            $this->request->request->has('menu_horizontal_hide_empresa') ? '1' : '0'
        );
        Tools::settingsSet('auralis', 'mobile_breakpoint', $mobileBreakpoint);

        Tools::settingsSet('auralis', 'custom_primary', $customPrimary);
        Tools::settingsSet('auralis', 'custom_primary_rgb', "{$pr}, {$pg}, {$pb}");
        Tools::settingsSet('auralis', 'custom_primary_hover', $primaryHover);
        Tools::settingsSet('auralis', 'custom_sidebar', $customSidebar);
        Tools::settingsSet('auralis', 'custom_sidebar_rgb', "{$sr}, {$sg}, {$sb}");
        Tools::settingsSet('auralis', 'custom_sidebar_text', $sidebarText);
        Tools::settingsSet('auralis', 'custom_accent', $customAccent);
        Tools::settingsSet('auralis', 'custom_accent_rgb', "{$ar}, {$ag}, {$ab}");
        Tools::settingsSet('auralis', 'custom_success', $customSuccess);
        Tools::settingsSet('auralis', 'custom_success_rgb', "{$sucr}, {$sucg}, {$sucb}");
        Tools::settingsSet('auralis', 'custom_success_text_emphasis', $successTextEmphasis);
        Tools::settingsSet('auralis', 'custom_danger', $customDanger);
        Tools::settingsSet('auralis', 'custom_danger_rgb', "{$dangr}, {$dangg}, {$dangb}");
        Tools::settingsSet('auralis', 'custom_danger_text_emphasis', $dangerTextEmphasis);
        Tools::settingsSet('auralis', 'custom_warning', $customWarning);
        Tools::settingsSet('auralis', 'custom_warning_rgb', "{$warr}, {$warg}, {$warb}");
        Tools::settingsSet('auralis', 'custom_warning_text_emphasis', $warningTextEmphasis);
        Tools::settingsSet('auralis', 'custom_info', $customInfo);
        Tools::settingsSet('auralis', 'custom_info_rgb', "{$infr}, {$infg}, {$infb}");
        Tools::settingsSet('auralis', 'custom_info_text_emphasis', $infoTextEmphasis);
        Tools::settingsSet('auralis', 'custom_light', $customLight);
        Tools::settingsSet('auralis', 'custom_light_rgb', "{$lightr}, {$lightg}, {$lightb}");
        Tools::settingsSet('auralis', 'custom_dark', $customDark);
        Tools::settingsSet('auralis', 'custom_dark_rgb', "{$darkr}, {$darkg}, {$darkb}");

        if (Tools::settingsSave()) {
            Tools::log()->notice(Tools::lang()->trans('configuracion-guardada-correctamente'));
        } else {
            Tools::log()->error(Tools::lang()->trans('error-guardar-configuracion'));
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function darkenHex(string $hex, int $percent): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $factor = 1 - ($percent / 100);
        return sprintf(
            '#%02x%02x%02x',
            max(0, (int) round($r * $factor)),
            max(0, (int) round($g * $factor)),
            max(0, (int) round($b * $factor))
        );
    }

    private function getContrastColor(int $r, int $g, int $b): string
    {
        $toLinear = fn(int $c): float => ($c / 255 <= 0.04045)
            ? ($c / 255) / 12.92
            : (($c / 255 + 0.055) / 1.055) ** 2.4;

        $L = 0.2126 * $toLinear($r) + 0.7152 * $toLinear($g) + 0.0722 * $toLinear($b);
        return $L > 0.179 ? '#1e293b' : '#ffffff';
    }

    private function saveLoginSettings()
    {
        $this->saveImageSetting('login_logo', 'login_logo');
        $this->saveImageSetting('login_header_logo', 'login_header_logo');
        $this->saveImageSetting('login_bg_image', 'login_bg_image');
        $this->saveImageSetting('login_left_image', 'login_left_image');

        $defaultFooterText = '© {year} Diseñado por MansiHost.com';
        Tools::settingsSet('auralis', 'login_maintenance_warning', Tools::noHtml((string)$this->request->request->get('login_maintenance_warning', '')));
        Tools::settingsSet('auralis', 'login_left_text', Tools::noHtml((string)$this->request->request->get('login_left_text', "Panel de Gestión\nAccede a tu plataforma empresarial")));
        $footerText = trim(str_replace("\0", '', (string)$this->request->request->get('login_footer_text', $defaultFooterText)));
        Tools::settingsSet('auralis', 'login_footer_text', mb_substr($footerText, 0, 500));
        Tools::settingsSet('auralis', 'login_show_forgot_password', $this->request->request->has('login_show_forgot_password'));
        $customRecovery = $this->request->request->has('login_custom_password_recovery');
        if (false === $this->request->request->has('login_show_forgot_password')) {
            $customRecovery = false;
        }
        Tools::settingsSet('auralis', 'login_custom_password_recovery', $customRecovery);
        Tools::settingsSet('auralis', 'login_header_logo_height', (string)(int)$this->request->request->get('login_header_logo_height', '40'));
        Tools::settingsSet('auralis', 'login_logo_height', (string)(int)$this->request->request->get('login_logo_height', '50'));

        if (Tools::settingsSave()) {
            Tools::log()->notice(Tools::lang()->trans('configuracion-login-guardada'));
        } else {
            Tools::log()->error(Tools::lang()->trans('error-guardar-configuracion-login'));
        }
    }

    private function saveImageSetting(string $inputName, string $settingKeyPrefix): void
    {
        // Desvincular: solo vaciar settings. Early-return evita que el hidden *_id del WidgetLibrary reasigne.
        if ($this->request->request->get('delete_' . $inputName)) {
            Tools::settingsSet('auralis', $settingKeyPrefix . '_id', '');
            Tools::settingsSet('auralis', $settingKeyPrefix, '');
            return;
        }

        // Selección desde la librería de archivos
        $libraryId = $this->request->request->get($inputName . '_id');
        if (!empty($libraryId) && is_numeric($libraryId)) {
            $attached = new AttachedFile();
            if ($attached->load((int)$libraryId) && $this->assignPublicImageIfSafe($attached, $settingKeyPrefix)) {
                // asignado
            }
        }

        $this->subirImagenPublica($inputName, $settingKeyPrefix);
    }

    /**
     * Sube una imagen pública (logotipo, login) desde el navegador. Valida el contenido real del
     * fichero ya movido (no solo la extensión) y solo entonces crea el AttachedFile y guarda los ajustes.
     */
    private function subirImagenPublica(string $inputName, string $settingKeyPrefix): void
    {
        $file = $this->request->files->get($inputName);
        if (!$file || !$file->isValid()) {
            return;
        }

        $ext = strtolower($file->extension());
        if (false === AuralisSecurity::isSafePublicImageExtension($ext)) {
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return;
        }

        $carpeta = FS_FOLDER . '/MyFiles/';
        if (!is_dir($carpeta)) {
            @mkdir($carpeta, 0755, true);
        }

        // Nombre impredecible y único: dos subidas en el mismo segundo no pueden pisarse.
        $nombreFichero = 'auralis_' . basename($inputName) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $rutaFichero = $carpeta . $nombreFichero;

        if (!$file->moveTo($rutaFichero)) {
            Tools::log()->error(Tools::trans('no-se-pudo-procesar-temp-logo'));
            return;
        }

        if (false === AuralisSecurity::esImagenPublicaSegura($rutaFichero, $ext)) {
            @unlink($rutaFichero);
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return;
        }

        $attached = new AttachedFile();
        $attached->path = $nombreFichero;
        $attached->filename = basename($file->name);

        if (false === $attached->save()) {
            @unlink($rutaFichero);
            Tools::log()->error(Tools::trans('no-se-pudo-registrar-logotipo'));
            return;
        }

        // El AttachedFile anterior se queda en la Biblioteca; solo cambiamos el ajuste.
        Tools::settingsSet('auralis', $settingKeyPrefix . '_id', $attached->idfile);
        Tools::settingsSet('auralis', $settingKeyPrefix, $attached->url('download-permanent'));
    }

    private function saveOrdenMenu(): void
    {
        try {
            // FormData envía '0'/'1' (y a veces 'on'): parsear de forma explícita.
            $activo = self::parseFormBool($this->request->input('orden_menu_activo', '0'));
            Tools::settingsSet('auralis', 'orden_menu_activo', $activo);

            $agrupar = self::parseFormBool($this->request->input('agrupar_admin_general', '0'));
            Tools::settingsSet('auralis', AdminGeneralMenuGrouper::SETTING_KEY, $agrupar);

            $agruparInformes = self::parseFormBool($this->request->input('agrupar_informes_menu', '0'));
            Tools::settingsSet('auralis', InformesMenuGrouper::SETTING_KEY, $agruparInformes);

            foreach (self::TOPBAR_VISIBILITY_SETTINGS as $settingKey) {
                $visible = self::parseFormBool($this->request->input($settingKey, '0'));
                Tools::settingsSet('auralis', $settingKey, $visible);
            }
            Tools::settingsSave();
            Tools::settingsClear();

            $positionsJson = (string) $this->request->input('menu_positions_json', '');
            if ($positionsJson !== '') {
                $items = json_decode($positionsJson, true);
                if (is_array($items)) {
                    $allowlist = $this->getMenuOrderAllowlist();

                    foreach (AuralisMenuOrder::all() as $oldItem) {
                        $oldItem->delete();
                    }

                    foreach ($items as $index => $itemData) {
                        if (!is_array($itemData) || empty($itemData['nombre']) || empty($itemData['nivel'])) {
                            continue;
                        }

                        $nivel = (int) $itemData['nivel'];
                        $nombre = $this->sanitizeMenuOrderName((string) $itemData['nombre']);
                        $padre = !empty($itemData['padre'])
                            ? $this->sanitizeMenuOrderName((string) $itemData['padre'])
                            : null;

                        if ($nombre === '' || !$this->isAllowedMenuOrderItem($nivel, $nombre, $padre, $allowlist)) {
                            continue;
                        }

                        $order = new AuralisMenuOrder();
                        $order->nivel = $nivel;
                        $order->nombre = $nombre;
                        $order->padre = ($nivel >= 2 && $padre !== '') ? $padre : null;
                        $order->posicion = (int) ($itemData['posicion'] ?? ($index + 1));
                        $order->save();
                    }
                }
            }

            AuralisMenuManager::clearCache();
            $this->jsonMenuOrderResponse('ok', Tools::trans('orden-menu-guardado'));
        } catch (\Throwable $e) {
            Tools::log()->error('auralis-save-orden-menu: ' . $e->getMessage());
            $this->jsonMenuOrderResponse('error', Tools::trans('orden-menu-save-error'));
        }
    }

    private function resetOrdenMenu(): void
    {
        try {
            Tools::settingsSet('auralis', 'orden_menu_activo', false);
            // Restablecer agrupación Admin>General al valor por defecto del plugin.
            Tools::settingsSet('auralis', AdminGeneralMenuGrouper::SETTING_KEY, true);
            Tools::settingsSet('auralis', InformesMenuGrouper::SETTING_KEY, true);
            foreach (self::TOPBAR_VISIBILITY_SETTINGS as $settingKey) {
                Tools::settingsSet('auralis', $settingKey, true);
            }
            Tools::settingsSave();
            Tools::settingsClear();

            foreach (AuralisMenuOrder::all() as $oldItem) {
                $oldItem->delete();
            }

            AuralisMenuManager::clearCache();
            $this->jsonMenuOrderResponse('ok', Tools::trans('orden-menu-restablecido'));
        } catch (\Throwable $e) {
            Tools::log()->error('auralis-reset-orden-menu: ' . $e->getMessage());
            $this->jsonMenuOrderResponse('error', Tools::trans('orden-menu-reset-error'));
        }
    }

    /**
     * Interpreta checkboxes/switches enviados por FormData ('0'/'1').
     * Nunca usar (bool) sobre el string: (bool)'0' === true en PHP.
     *
     * @param mixed $value
     */
    public static function parseFormBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function jsonMenuOrderResponse(string $status, string $message): void
    {
        // Obligatorio: si no, Controller::run() renderiza Twig HTML encima del JSON.
        $this->setTemplate(false);

        if (null === $this->multiRequestProtection) {
            $this->multiRequestProtection = new MultiRequestProtection();
        }

        // json() fija Content-Type, cuerpo y send() (idempotente si run() vuelve a send).
        $this->response->json([
            'status' => $status,
            'message' => $message,
            'newToken' => $this->multiRequestProtection->newToken(),
        ]);
    }

    protected function loadData($viewName, $view)
    {
        $this->hasData = true;

        if ($viewName === 'ConfigAuralisPdf') {
            $this->loadAuralisPdfSettings($view);
            return;
        }

        if ($viewName === 'ConfigAuralisPdfNombre') {
            $this->loadAuralisPdfNombreSettings($view);
            return;
        }

        if ($viewName === 'OrdenMenuAuralis') {
            $this->menuTree = $this->getMenuTree();
            $this->ordenMenuActivo = (bool) Tools::settings('auralis', 'orden_menu_activo', false);
            $this->agruparAdminGeneral = AdminGeneralMenuGrouper::isEnabled();
            $this->informesPluginActivo = InformesMenuGrouper::isPluginActive();
            $this->agruparInformesMenu = $this->informesPluginActivo
                ? InformesMenuGrouper::isEnabled()
                : (bool) Tools::settings('auralis', InformesMenuGrouper::SETTING_KEY, true);

            $savedOrders = [];
            foreach (AuralisMenuOrder::all([], ['posicion' => 'ASC']) as $row) {
                $savedOrders[] = [
                    'id' => $row->id,
                    'nivel' => (int) $row->nivel,
                    'nombre' => $row->nombre,
                    'padre' => $row->padre,
                    'posicion' => (int) $row->posicion,
                ];
            }
            $this->savedOrders = $savedOrders;
        }
    }

    /**
     * Carga el grupo Settings auralis_pdf con sus valores por defecto y avisa si PlantillasPDF manda.
     */
    private function loadAuralisPdfSettings($view): void
    {
        $view->loadData(PdfEstiloAuralis::GRUPO);
        $view->model->name = PdfEstiloAuralis::GRUPO;

        foreach (PdfEstiloAuralis::valoresPorDefecto() as $key => $valorDefecto) {
            $valor = $view->model->{$key};
            if ($valor === null) {
                $view->model->{$key} = $valorDefecto;
                continue;
            }
            if (in_array($key, PdfEstiloAuralis::CLAVES_BOOLEANAS, true)) {
                $view->model->{$key} = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // El aviso solo se ve si PlantillasPDF está activo (entonces este estilo no se aplica).
        $plantillas = PdfEstiloAuralis::plantillasPdfActivo();
        $view->disableColumn('aviso_plantillas', false === $plantillas);

        // Vista previa (Block/AuralisPdfPreview): con PlantillasPDF solo muestra el aviso; «tema» se resuelve aquí
        // con la misma regla que el PDF real (cae a Helvetica si la tipografía del tema no se incrusta).
        $view->setSettings(self::AJUSTE_PREVIEW, [
            'plantillas' => $plantillas,
            'fuente_tema' => PdfEstiloAuralis::desdeArray(['fuente' => PdfEstiloAuralis::FUENTE_TEMA])->fuenteEfectiva(),
            'acento_defecto' => PdfEstiloAuralis::colorAcentoPorDefecto(),
        ]);
    }

    /**
     * Carga el grupo Settings auralis_pdf_nombre con sus valores por defecto.
     */
    private function loadAuralisPdfNombreSettings($view): void
    {
        $view->loadData(PdfNombreArchivo::GRUPO);
        $view->model->name = PdfNombreArchivo::GRUPO;

        // La ayuda de variables (Block/ConfigAuralisPdfNombreVariables) sale de la misma lista que usa el patrón.
        $view->setSettings(self::AJUSTE_VARIABLES, PdfNombreArchivo::catalogoVariables());

        foreach (PdfNombreArchivo::valoresPorDefecto() as $key => $valorDefecto) {
            $valor = $view->model->{$key};
            if ($valor === null) {
                $view->model->{$key} = $valorDefecto;
                continue;
            }
            if (in_array($key, PdfNombreArchivo::CLAVES_BOOLEANAS, true)) {
                $view->model->{$key} = filter_var($valor, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Cualquier Tools::settingsSave() persiste los defaults en memoria: si la fila aún no existe, se crea
        // ya, para que el formulario guarde con «edit» y no choque con «Registro duplicado» al hacer «insert».
        // Solo con permiso de edición: quien no puede guardar tampoco crea la fila.
        if ($this->permissions->allowUpdate && false === $view->model->exists()) {
            $view->model->save();
        }
    }

    private function getMenuTree(): array
    {
        $user = $this->user ?? Session::user();
        if ($user && !empty($user->nick)) {
            Session::set('user', $user);
        }

        // Leer directamente desde la tabla pages (fiable en cualquier contexto)
        $i18n = new Translator();
        $whereShowOnMenu = [Where::eq('showonmenu', true)];
        $orderBy = [
            'lower(menu)' => 'ASC',
            'lower(submenu)' => 'ASC',
            'ordernum' => 'ASC',
            'lower(title)' => 'ASC'
        ];

        $allPages = Page::all($whereShowOnMenu, $orderBy);
        $menuTree = [];

        foreach ($allPages as $page) {
            if (empty($page->menu)) {
                continue;
            }

            $menuKey = $page->menu;
            if (!isset($menuTree[$menuKey])) {
                $menuTree[$menuKey] = [
                    'name'  => $menuKey,
                    'title' => $i18n->trans($menuKey),
                    'icon'  => 'fa-solid fa-folder',
                    'menu'  => []
                ];
            }

            $submenuKey = $page->submenu;
            if (!empty($submenuKey)) {
                // Nivel 2: el elemento es un submenú
                if (!isset($menuTree[$menuKey]['menu'][$submenuKey])) {
                    $menuTree[$menuKey]['menu'][$submenuKey] = [
                        'name'  => $submenuKey,
                        'title' => $i18n->trans($submenuKey),
                        'icon'  => 'fa-solid fa-folder-open',
                        'menu'  => []
                    ];
                }
                $menuTree[$menuKey]['menu'][$submenuKey]['menu'][$page->name] = [
                    'name'  => $page->name,
                    'title' => $i18n->trans($page->title),
                    'icon'  => $page->icon ?: 'fa-solid fa-file',
                    'menu'  => []
                ];
            } else {
                // Nivel 2 directo (sin submenú intermedio)
                $menuTree[$menuKey]['menu'][$page->name] = [
                    'name'  => $page->name,
                    'title' => $i18n->trans($page->title),
                    'icon'  => $page->icon ?: 'fa-solid fa-file',
                    'menu'  => []
                ];
            }
        }

        if (isset($menuTree['admin']['menu'])) {
            AdminGeneralMenuGrouper::applyToTree($menuTree['admin']['menu']);
        }

        if (isset($menuTree[InformesMenuGrouper::MENU_KEY]['menu'])) {
            InformesMenuGrouper::applyToTree($menuTree[InformesMenuGrouper::MENU_KEY]['menu']);
        }

        return $menuTree;
    }

    /**
     * Asigna un adjunto como imagen pública (login/tema) solo si la extensión es segura.
     */
    private function assignPublicImageIfSafe(AttachedFile $attached, string $settingKeyPrefix): bool
    {
        $currentId = (int) Tools::settings('auralis', $settingKeyPrefix . '_id');
        if ($currentId > 0 && (int) $attached->idfile === $currentId) {
            return true;
        }

        $filename = (string) ($attached->filename ?? '');
        if (false === AuralisSecurity::isSafePublicImageFilename($filename)) {
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return false;
        }

        // Además del nombre, el contenido real del fichero debe ser una imagen coherente con la extensión.
        $extension = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_EXTENSION);
        if (false === AuralisSecurity::esImagenPublicaSegura($attached->getFullPath(), $extension)) {
            Tools::log()->error(Tools::trans('formato-logotipo-no-permitido'));
            return false;
        }

        Tools::settingsSet('auralis', $settingKeyPrefix . '_id', $attached->idfile);
        Tools::settingsSet('auralis', $settingKeyPrefix, $attached->url('download-permanent'));
        return true;
    }

    /**
     * Allowlist de claves de menú válidas para ordenación (nivel 1, 2 y 3 del árbol actual).
     *
     * @return array{level1: string[], level2: array<string, string[]>, level3: array<string, string[]>}
     */
    private function getMenuOrderAllowlist(): array
    {
        $tree = $this->getMenuTree();
        $level1 = [];
        $level2 = [];
        $level3 = [];

        foreach ($tree as $menuKey => $menuNode) {
            $menuKey = (string) $menuKey;
            $level1[] = $menuKey;
            $level2[$menuKey] = [];

            if (!empty($menuNode['menu']) && is_array($menuNode['menu'])) {
                foreach ($menuNode['menu'] as $subKey => $subNode) {
                    $subKey = (string) $subKey;
                    $level2[$menuKey][] = $subKey;
                    $compositeKey = $menuKey . '/' . $subKey;
                    $level3[$compositeKey] = [];
                    $level3[$subKey] = [];

                    if (!empty($subNode['menu']) && is_array($subNode['menu'])) {
                        foreach (array_keys($subNode['menu']) as $pageKey) {
                            $pageName = (string) $pageKey;
                            $level3[$compositeKey][] = $pageName;
                            $level3[$subKey][] = $pageName;
                        }
                    }
                }
            }
        }

        return [
            'level1' => $level1,
            'level2' => $level2,
            'level3' => $level3,
        ];
    }

    private function sanitizeMenuOrderName(string $value): string
    {
        return mb_substr(Tools::noHtml(trim($value)), 0, 150);
    }

    /**
     * @param array{level1: string[], level2: array<string, string[]>, level3: array<string, string[]>} $allowlist
     */
    private function isAllowedMenuOrderItem(int $nivel, string $nombre, ?string $padre, array $allowlist): bool
    {
        if ($nivel === 1) {
            return in_array($nombre, $allowlist['level1'], true);
        }

        if ($nivel === 2) {
            if ($padre === null || $padre === '') {
                return false;
            }

            return isset($allowlist['level2'][$padre])
                && in_array($nombre, $allowlist['level2'][$padre], true);
        }

        if ($nivel === 3) {
            if ($padre === null || $padre === '') {
                return false;
            }

            return isset($allowlist['level3'][$padre])
                && in_array($nombre, $allowlist['level3'][$padre], true);
        }

        return false;
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Inicializador para el plugin de tema visual Auralis
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Lib\AjaxForms\PurchasesHeaderHTML;
use FacturaScripts\Core\Lib\AjaxForms\SalesHeaderHTML;
use FacturaScripts\Core\Lib\API\APIModel;
use FacturaScripts\Core\Migrations;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Request;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Extension\Controller\EditController as EditControllerExtension;
use FacturaScripts\Plugins\Auralis\Extension\Controller\ListAgente as ListAgenteExtension;
use FacturaScripts\Plugins\Auralis\Extension\Controller\ListController as ListControllerExtension;
use FacturaScripts\Plugins\Auralis\Extension\Controller\ListFacturaCliente as ListFacturaClienteExtension;
use FacturaScripts\Plugins\Auralis\Extension\Controller\ListFacturaProveedor as ListFacturaProveedorExtension;
use FacturaScripts\Plugins\Auralis\Extension\Model\Agente as AgenteExtension;
use FacturaScripts\Plugins\Auralis\Extension\Model\User as UserExtension;
use FacturaScripts\Plugins\Auralis\Lib\AvisosDocumento;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaEnvio;
use FacturaScripts\Plugins\Auralis\Lib\HomepageAdmins;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanAjustes;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanRegistry;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\Providers\DocumentoKanbanProvider;
use FacturaScripts\Plugins\Auralis\Lib\OrdenacionListados;
use FacturaScripts\Plugins\Auralis\Lib\PdfEstiloAuralis;
use FacturaScripts\Plugins\Auralis\Lib\PdfNombreArchivo;
use FacturaScripts\Plugins\Auralis\Lib\AuralisRolesPredefinidos;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use FacturaScripts\Plugins\Auralis\Lib\AuralisTwigFunctions;
use FacturaScripts\Plugins\Auralis\Mod\AuralisAvisosPurchasesMod;
use FacturaScripts\Plugins\Auralis\Mod\AuralisAvisosSalesMod;
use FacturaScripts\Plugins\Auralis\Model\AuralisPasswordReset;
use FacturaScripts\Plugins\Auralis\Model\AuralisTipoAgente;
use Throwable;

class Init extends InitClass
{
    /**
     * Prefijo del marcador en Cache que indica que el esquema/datos de esta versión ya se revisaron.
     * Cache::EXPIRATION (3600 s por filemtime) y el borrado de MyFiles/Cache en Plugins::deploy()
     * fuerzan la revisión como mucho una vez por hora y tras cada despliegue o limpieza de caché.
     */
    private const PREFIJO_MARCADOR_INSTALACION = 'auralis-init-esquema-';

    private const NOMBRE_PLUGIN = 'Auralis';

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        // Modelos internos fuera de la API REST del Core: con una API key de escritura se podría insertar
        // un token de restablecimiento y cambiar la contraseña de otro usuario, o leer/alterar
        // suscripciones push y notificaciones. El calendario (eventos, salas, tipos...) sigue expuesto.
        APIModel::excludeModel('AuralisPasswordReset');

        // Registrar extensión del modelo User y Agente
        User::addExtension(new UserExtension());
        Agente::addExtension(new AgenteExtension());
        // Tableros Kanban de presupuestos y pedidos de cliente y de proveedor: registro en memoria, sin BD.
        foreach (DocumentoKanbanProvider::todos() as $tableroDocumento) {
            KanbanRegistry::register($tableroDocumento);
        }

        // Incondicional: otras plantillas (login incluido) dependen de las funciones Twig registradas.
        AuralisTwigFunctions::register();

        // Defaults de settings en cada petición: Tools::settingsSet() solo escribe en memoria (no persiste),
        // así que no pueden ir tras el centinela. Coste: lecturas del array ya cargado, sin consultas.
        $this->ensureGestoriaSettings();
        // Estilo básico del PDF del Core (grupo auralis_pdf): desactivado por defecto.
        PdfEstiloAuralis::asegurarValoresPorDefecto();
        // Nombres de archivo PDF de ventas y compras (grupo auralis_pdf_nombre): desactivado por defecto.
        PdfNombreArchivo::asegurarValoresPorDefecto();
        // Tableros Kanban (uno por tipo de documento): encendidos si no existe el ajuste (solo memoria, sin consultas).
        KanbanAjustes::asegurarValorPorDefecto();

        // Auto-corrección de valor obsoleto o erróneo en configuración de tema
        if (Tools::settings('auralis', 'tema_color') === 'auralis_oscuro') {
            Tools::settingsSet('auralis', 'tema_color', 'auralis_dark');
        }

        // Todos los List*: ordenación (columnas + códigos varchar), botón «Tablero» (Kanban) en los
        // listados de documentos y nombre del PDF de los documentos marcados
        $this->loadExtension(new ListControllerExtension());

        // Filtro por tipo de agente en ListAgente
        $this->loadExtension(new ListAgenteExtension());

        // Columnas % cobrado/por cobrar (ventas) y % pagado/por pagar (compras)
        $this->loadExtension(new ListFacturaClienteExtension());
        $this->loadExtension(new ListFacturaProveedorExtension());

        // Nombre del archivo al imprimir o enviar por email ventas y compras desde su ficha (todos los Edit*)
        $this->loadExtension(new EditControllerExtension());

        // Avisos internos en cabeceras Ajax de compra y venta
        SalesHeaderHTML::addMod(new AuralisAvisosSalesMod());
        PurchasesHeaderHTML::addMod(new AuralisAvisosPurchasesMod());

        // Registrar la ruta en el Kernel para que FacturaScripts dirija /AuralisResetPassword al controlador
        Kernel::addRoute('/AuralisResetPassword', '\\FacturaScripts\\Dinamic\\Controller\\AuralisResetPassword', 1);

        register_shutdown_function(function () {
            // Evitar Request::isMethod() en CLI/cron: REQUEST_METHOD es null y el Core TypeError.
            if (false === AuralisSecurity::isHttpContextForLoginAudit()) {
                return;
            }

            $request = Request::createFromGlobals();
            $action = (string) $request->input('action', '');
            if ($action !== 'login' && $action !== 'two-factor-validation') {
                return;
            }

            $nick = (string) $request->input('fsNick', '');
            if (empty($nick)) {
                return;
            }

            $user = Session::user();
            $ip = Session::getClientIp();
            $agent = AuralisSecurity::sanitizeLogLabel((string) $request->userAgent(), 180);

            // Estado de IP bloqueada ANTES de que MiniLog se vacíe / con fallback a caché del Core.
            $isBlocked = AuralisSecurity::isLoginBanned($ip, $nick);

            // El núcleo ya puede haber cerrado la BD: abrir conexión propia para load/save.
            $db = new DataBase();
            if (false === $db->connect()) {
                return;
            }

            try {
                // Contraseña OK pendiente de 2FA: no registrar como fallido ni contar bloqueo.
                if (AuralisSecurity::shouldSkipLoginAudit($action, $user, $ip, $nick)) {
                    return;
                }

                AuralisSecurity::persistLoginAudit($action, $nick, $user, $ip, $agent, $isBlocked);
            } finally {
                $db->close();
            }
        });

        // Esquema y datos: solo si no hay marcador vigente para esta versión (hot path sin consultas).
        if (false === Cache::has(self::claveMarcadorInstalacion())) {
            $this->asegurarInstalacionConMarcador();
        }
    }

    /**
     * @inheritDoc
     */
    public function uninstall(): void
    {
        $page = new Page();
        if ($page->load('AuralisResetPassword')) {
            $page->delete();
        }
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // Cubre carrera enable/deploy: registrar Twig antes del primer render del layout.
        AuralisTwigFunctions::register();

        // Registrar configuraciones predeterminadas si no existen
        if (null === Tools::settings('auralis', 'modo_menu')) {
            Tools::settingsSet('auralis', 'modo_menu', 'horizontal');
        }
        if (null === Tools::settings('auralis', 'tema_color')) {
            Tools::settingsSet('auralis', 'tema_color', 'default');
        } elseif (Tools::settings('auralis', 'tema_color') === 'auralis_oscuro') {
            Tools::settingsSet('auralis', 'tema_color', 'auralis_dark');
        }
        if (null === Tools::settings('auralis', 'tipografia')) {
            Tools::settingsSet('auralis', 'tipografia', 'plus_jakarta_sans');
        }
        if (null === Tools::settings('auralis', 'border_radius_cards')) {
            Tools::settingsSet('auralis', 'border_radius_cards', '8');
        }
        if (null === Tools::settings('auralis', 'border_radius_botones')) {
            Tools::settingsSet('auralis', 'border_radius_botones', '8');
        }
        if (null === Tools::settings('auralis', 'border_radius_inputs')) {
            Tools::settingsSet('auralis', 'border_radius_inputs', '8');
        }
        if (null === Tools::settings('auralis', 'font_size_base')) {
            Tools::settingsSet('auralis', 'font_size_base', '16');
        }
        if (null === Tools::settings('auralis', 'orden_menu_activo')) {
            Tools::settingsSet('auralis', 'orden_menu_activo', false);
        }
        if (null === Tools::settings('auralis', 'agrupar_admin_general')) {
            Tools::settingsSet('auralis', 'agrupar_admin_general', false);
        }
        if (null === Tools::settings('auralis', 'agrupar_informes_menu')) {
            Tools::settingsSet('auralis', 'agrupar_informes_menu', false);
        }
        if (null === Tools::settings('auralis', 'logo_height_sidebar')) {
            Tools::settingsSet('auralis', 'logo_height_sidebar', '90');
        }
        if (null === Tools::settings('auralis', 'logo_height_navbar')) {
            Tools::settingsSet('auralis', 'logo_height_navbar', '40');
        }
        if (null === Tools::settings('auralis', 'menu_horizontal_hide_empresa')) {
            Tools::settingsSet('auralis', 'menu_horizontal_hide_empresa', '0');
        }
        if (null === Tools::settings('auralis', 'custom_success')) {
            Tools::settingsSet('auralis', 'custom_success', '#198754');
            Tools::settingsSet('auralis', 'custom_success_rgb', '25, 135, 84');
        }
        if (null === Tools::settings('auralis', 'custom_danger')) {
            Tools::settingsSet('auralis', 'custom_danger', '#dc3545');
            Tools::settingsSet('auralis', 'custom_danger_rgb', '220, 53, 69');
        }
        if (null === Tools::settings('auralis', 'custom_warning')) {
            Tools::settingsSet('auralis', 'custom_warning', '#ffc107');
            Tools::settingsSet('auralis', 'custom_warning_rgb', '255, 193, 7');
        }
        if (null === Tools::settings('auralis', 'custom_info')) {
            Tools::settingsSet('auralis', 'custom_info', '#0dcaf0');
            Tools::settingsSet('auralis', 'custom_info_rgb', '13, 202, 240');
        }
        // text-emphasis BS5.3 (legible sobre bg-*-subtle); upgrade: defaults de fábrica.
        if (null === Tools::settings('auralis', 'custom_success_text_emphasis')) {
            Tools::settingsSet('auralis', 'custom_success_text_emphasis', '#0a3622');
        }
        if (null === Tools::settings('auralis', 'custom_danger_text_emphasis')) {
            Tools::settingsSet('auralis', 'custom_danger_text_emphasis', '#58151c');
        }
        if (null === Tools::settings('auralis', 'custom_warning_text_emphasis')) {
            Tools::settingsSet('auralis', 'custom_warning_text_emphasis', '#664d03');
        }
        if (null === Tools::settings('auralis', 'custom_info_text_emphasis')) {
            Tools::settingsSet('auralis', 'custom_info_text_emphasis', '#055160');
        }
        if (null === Tools::settings('auralis', 'custom_light')) {
            Tools::settingsSet('auralis', 'custom_light', '#f8f9fa');
            Tools::settingsSet('auralis', 'custom_light_rgb', '248, 249, 250');
        }
        if (null === Tools::settings('auralis', 'custom_dark')) {
            Tools::settingsSet('auralis', 'custom_dark', '#212529');
            Tools::settingsSet('auralis', 'custom_dark_rgb', '33, 37, 41');
        }
        if (null === Tools::settings('auralis', 'mobile_breakpoint')) {
            Tools::settingsSet('auralis', 'mobile_breakpoint', '990');
        }
        if (null === Tools::settings('auralis', 'login_header_logo_height')) {
            Tools::settingsSet('auralis', 'login_header_logo_height', '55');
        }
        if (null === Tools::settings('auralis', 'login_logo_height')) {
            Tools::settingsSet('auralis', 'login_logo_height', '180');
        }
        if (null === Tools::settings('auralis', 'login_show_forgot_password')) {
            Tools::settingsSet('auralis', 'login_show_forgot_password', true);
        }
        if (null === Tools::settings('auralis', 'login_custom_password_recovery')) {
            Tools::settingsSet('auralis', 'login_custom_password_recovery', false);
        }
        if (null === Tools::settings('auralis', 'login_footer_text')) {
            Tools::settingsSet(
                'auralis',
                'login_footer_text',
                '© {year} Diseñado por MansiHost.com'
            );
        }
        // Avisos internos: activos por defecto en todos los documentos estándar
        foreach (AvisosDocumento::clavesConfiguracion() as $clave) {
            if (null === Tools::settings('auralis_avisos', $clave)) {
                Tools::settingsSet('auralis_avisos', $clave, true);
            }
        }

        // Ordenación de listados: activas por defecto (desmarcar = comportamiento core)
        foreach (OrdenacionListados::clavesConfiguracion() as $clave) {
            if (null === Tools::settings('auralis_avisos', $clave)) {
                Tools::settingsSet('auralis_avisos', $clave, true);
            }
        }

        // Homepage nativa: DashBoardAuralis para instalación y admins sin preferencia propia.
        HomepageAdmins::asignar();

        // Asegurar que las tablas de Auralis se crean / sincronizan XML (antes del backfill).
        // El catálogo de tipos va antes que los eventos: si no, MySQL rechaza la FK (errno 150).
        new AuralisPasswordReset();
        new AuralisTipoAgente();

        Migrations::runPluginMigrations([
            new Migration\AplicarDefaultsAuralis211(),
            new Migration\AsignarHomepageAdmins(),
            new Migration\NormalizarTitulosPaginasI18n(),
            new Migration\AlinearDebajaAgentesConFechabaja(),
        ]);
        // Roles plantilla (Agenda, Gestoría, Administración): solo si el codrole no existe; nunca se asignan.
        self::asegurarRolesPredefinidos();
        // Tableros Kanban: guarda encendido cada ajuste que no exista; uno ya guardado (true o false) no se toca.
        try {
            KanbanAjustes::persistirValorPorDefecto();
        } catch (Throwable $excepcion) {
            Tools::log(self::NOMBRE_PLUGIN)->warning('Auralis: ajustes de tableros: ' . $excepcion->getMessage());
        }

        // Mismo bloque que init() ejecutaba tras update() en esta petición; si termina bien fija el
        // marcador y el init() posterior (Core\Internal\Plugin::init()) ya no lo repite.
        $this->asegurarInstalacionConMarcador();
    }

    /**
     * Clave del marcador en Cache: incluye la versión del facturascripts.ini (leído en cada petición
     * por Core\Internal\Plugin), así copiar ficheros de una versión nueva sin desplegar fuerza la revisión.
     */
    private static function claveMarcadorInstalacion(): string
    {
        $plugin = Plugins::get(self::NOMBRE_PLUGIN);
        $version = null === $plugin ? '0' : (string)$plugin->version;

        return self::PREFIJO_MARCADOR_INSTALACION . $version;
    }

    /**
     * Ejecuta el trabajo de esquema/datos y fija el marcador solo si termina bien.
     * Si algo falla no se fija y se reintenta en la siguiente petición (autocuración).
     * Dos peticiones simultáneas pueden ejecutarlo a la vez: todo es idempotente (igual que antes del centinela).
     */
    private function asegurarInstalacionConMarcador(): void
    {
        try {
            if ($this->asegurarInstalacion()) {
                Cache::set(self::claveMarcadorInstalacion(), true);
            }
        } catch (Throwable $excepcion) {
            Tools::log(self::NOMBRE_PLUGIN)->warning('auralis-init-schema: ' . $excepcion->getMessage());
        }
    }

    /**
     * Trabajo de esquema y datos que antes se hacía en cada init(): tablas (incluida la reapertura
     * de tablas marcadas como revisadas pero ausentes), página pública, migraciones y menú de salas.
     *
     * @return bool false si alguna tabla clave sigue sin existir o una migración queda pendiente
     */
    private function asegurarInstalacion(): bool
    {
        $correcto = true;

        // Asegurar que la página pública AuralisResetPassword está registrada en la BD
        $page = new Page();
        if (false === $page->load('AuralisResetPassword')) {
            $page->name = 'AuralisResetPassword';
            $page->title = 'restablecer-contrasena';
            $page->icon = 'fa-solid fa-key';
            $page->showonmenu = false;
            $page->public = true;
            $page->save();
        }

        $migracionesOk = Migrations::runPluginMigrations([
            new Migration\AplicarDefaultsAuralis211(),
            new Migration\AsignarHomepageAdmins(),
            new Migration\NormalizarTitulosPaginasI18n(),
            new Migration\AlinearDebajaAgentesConFechabaja(),
        ]);

        return $correcto && $migracionesOk;
    }

    /** Defaults del informe «Documentos para la gestoría» (grupo auralis_gestoria). */
    private function ensureGestoriaSettings(): void
    {
        $defaults = [
            GestoriaEnvio::AJUSTE_EMAIL => '',
            GestoriaEnvio::AJUSTE_MAX_MB => (string)GestoriaEnvio::DEFECTO_MAX_MB,
            GestoriaEnvio::AJUSTE_MAX_DOCUMENTOS => (string)GestoriaEnvio::DEFECTO_MAX_DOCUMENTOS,
        ];
        foreach ($defaults as $clave => $valor) {
            if (null === Tools::settings(GestoriaEnvio::GRUPO_AJUSTES, $clave)) {
                Tools::settingsSet(GestoriaEnvio::GRUPO_AJUSTES, $clave, $valor);
            }
        }
    }

    /** Calendario personal, avisos in-app y PWA (lista única: AuralisRolesPredefinidos::PAGINAS_CONCESION_GENERAL). */
    private static function concederPaginasConcesionGeneral(): void
    {
        foreach (AuralisRolesPredefinidos::PAGINAS_CONCESION_GENERAL as $pagename) {
            self::concederPaginaARoles($pagename);
        }
    }

    private static function asegurarRolesPredefinidos(): void
    {
        try {
            (new AuralisRolesPredefinidos())->ensure();
        } catch (Throwable $excepcion) {
            Tools::log(self::NOMBRE_PLUGIN)->warning(
                'Auralis: no se pudieron crear los roles predefinidos: ' . $excepcion->getMessage()
            );
        }
    }

    /** Limpieza única de las reglas que la concesión general dejó en Gestoría y Administración. */
    private static function limpiarPaginasExtraRolesPlantilla(): void
    {
        try {
            (new AuralisRolesPredefinidos())->limpiarPaginasExtraDePlantillas();
        } catch (Throwable $excepcion) {
            Tools::log(self::NOMBRE_PLUGIN)->warning(
                'Auralis: no se pudieron limpiar las páginas extra de los roles plantilla: ' . $excepcion->getMessage()
            );
        }
    }

    /**
     * Concede la página a los roles que no la tengan, salvo los roles plantilla
     * (sus reglas salen solo de AuralisRolesPredefinidos::definiciones()).
     */
    private static function concederPaginaARoles(string $pagename): void
    {
        try {
            AuralisRolesPredefinidos::concederPaginaARolesNoPlantilla($pagename);
        } catch (Throwable $excepcion) {
            Tools::log()->warning(
                'Auralis: no se pudo conceder ' . $pagename . ' a los roles: ' . $excepcion->getMessage()
            );
        }
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Panel central de gestión y catálogos de calendario Auralis (lead magnet, ajustes, salas, tipos y estados).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListView;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Lib\Widget\WidgetLibrary;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;
use FacturaScripts\Dinamic\Model\AttachedFile;
use FacturaScripts\Dinamic\Model\Settings;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarNotificationDefaults;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarOrigins;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;
use FacturaScripts\Plugins\AuralisAgenda\Lib\Traits\AuralisWidgetLibraryAjaxTrait;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisNotificationManager;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPwaPaths;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisSecurity;
use FacturaScripts\Plugins\AuralisAgenda\Lib\WebPush\PushCryptoCapability;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendario;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioAcceso;

class ConfigAuralisServiciosCalendario extends PanelController
{
    use AuralisWidgetLibraryAjaxTrait;

    public function privateCore(&$response, $user, $permissions)
    {
        // En PanelController, si hasData es false, el núcleo deshabilita todas las pestañas secundarias
        $this->hasData = true;

        // Si no viene activetab explícito, abrimos directamente los ajustes del calendario.
        if (empty($this->request->query('activetab', '')) && empty($this->request->request->get('activetab', ''))) {
            $this->active = 'ConfigCalendarioAuralis';
        }

        parent::privateCore($response, $user, $permissions);
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'auralis';
        $data['title'] = 'auralis-calendario';
        $data['icon'] = 'fa-solid fa-calendar-days';
        $data['showonmenu'] = true;
        $data['ordernum'] = 30;

        return $data;
    }

    /** Widget librería para icono PWA (solo raster). */
    public function getPwaIconLibraryWidget(): string
    {
        $widget = new WidgetLibrary([
            'type' => 'library',
            'fieldname' => AuralisPushSettings::SETTING_PWA_ICON_ID,
            'accept' => AuralisPushSettings::PWA_ICON_ACCEPT,
            'icon' => 'fa-solid fa-folder-open',
            'children' => [],
        ]);

        $fakeModel = new \stdClass();
        $id = AuralisPushSettings::pwaIconId();
        $fakeModel->{AuralisPushSettings::SETTING_PWA_ICON_ID} = $id > 0 ? $id : null;

        $html = $widget->edit($fakeModel, '', '');

        $protection = new MultiRequestProtection();
        $token = $protection->newToken();
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return preg_replace(
            '/^(<div class="mb-3" id=")(\d+)(")/',
            '$1$2$3 data-multireqtoken="' . $safeToken . '"',
            $html,
            1
        ) ?? $html;
    }

    /**
     * URL pública ya resuelta (custom MyFiles o fallback Dinamic).
     * No pasar por asset() de nuevo en Twig.
     */
    public function getPwaIconPreviewUrl(): string
    {
        return AuralisPushSettings::pwaIconUrl('192');
    }

    public function hasCustomPwaIcon(): bool
    {
        $custom = trim((string)Tools::settings(
            AuralisPushSettings::group(),
            AuralisPushSettings::SETTING_PWA_ICON,
            ''
        ));

        return $custom !== '' && AuralisPushSettings::isSafePwaIconStoredPath($custom);
    }

    protected function createViews(): void
    {
        // Primera pestaña: ajustes de la rejilla Timegrid (ConfigCalendarioAuralis).
        $this->addEditView(
            'ConfigCalendarioAuralis',
            'Settings',
            'auralis-config-calendario',
            'fa-solid fa-sliders'
        )
            ->setSettings('btnNew', false)
            ->setSettings('btnDelete', false);
        $this->assignSettingsPanelUrl('ConfigCalendarioAuralis');
        $this->disableSettingsCrudButtons('ConfigCalendarioAuralis');
        $this->assignCalendarSettingsValues('ConfigCalendarioAuralis');

        // Calendarios comunes y de módulo (los personales se gestionan en Mi calendario). Solo gestores.
        if (CalendarioAcceso::puedeGestionarComunes($this->user)) {
            $this->addListView('ListAuralisCalendario', 'AuralisCalendario', 'auralis-calendarios', 'fa-solid fa-calendar-week')
                ->addSearchFields(['nombre', 'codigo', 'descripcion'])
                ->addOrderBy(['nombre'], 'name', 1)
                ->addOrderBy(['codigo'], 'code')
                ->addOrderBy(['tipo'], 'type')
                ->addFilterSelect('tipo', 'type', 'tipo', [
                    AuralisCalendario::TIPO_COMUN => Tools::trans('auralis-calendario-tipo-comun'),
                    AuralisCalendario::TIPO_MODULO => Tools::trans('auralis-calendario-tipo-modulo'),
                ])
                ->addFilterCheckbox('activo', 'active', 'activo');
        }

        // 3. Pestaña de Salas físicas
        $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
        $this->addListView('ListAuralisSala', 'AuralisSala', 'auralis-salas', 'fa-solid fa-door-open')
            ->addSearchFields(['nombre', 'descripcion', 'codalmacen', 'idsala'])
            ->addOrderBy(['idsala'], 'code')
            ->addOrderBy(['nombre'], 'name', 1)
            ->addOrderBy(['codalmacen'], 'warehouse')
            ->addFilterSelect('codalmacen', 'warehouse', 'codalmacen', $warehouses)
            ->addFilterCheckbox('activa', 'active', 'activa');

        // 4. Pestaña de Tipos de Evento
        $originFilters = CalendarOrigins::getFilterOptions();
        $this->addListView('ListAuralisCalendarEventType', 'AuralisCalendarEventType', 'auralis-calendar-event-types', 'fa-solid fa-tags')
            ->addSearchFields(['nombre', 'origen_tipo', 'id'])
            ->addOrderBy(['id'], 'code')
            ->addOrderBy(['nombre'], 'name', 1)
            ->addOrderBy(['duracion'], 'duration')
            ->addFilterSelect('origen_tipo', 'origen_tipo', 'origen_tipo', $originFilters)
            ->addFilterCheckbox('activo', 'active', 'activo');
        $this->assignOriginColumnValues('ListAuralisCalendarEventType');

        // 5. Pestaña de Estados de Evento (Pipeline)
        $this->addListView('ListAuralisCalendarEventStatus', 'AuralisCalendarEventStatus', 'auralis-calendar-event-statuses', 'fa-solid fa-traffic-light')
            ->addSearchFields(['nombre', 'codestado', 'origen_tipo'])
            ->addOrderBy(['orden'], 'calendar-status-order', 1)
            ->addOrderBy(['nombre'], 'name')
            ->addFilterSelect('origen_tipo', 'origen_tipo', 'origen_tipo', $originFilters)
            ->addFilterCheckbox('activo', 'active', 'activo');
        $this->assignOriginColumnValues('ListAuralisCalendarEventStatus');

        // El icono PWA es un widget Biblioteca pintado desde Twig (no es columna de ninguna XMLView).
        $this->registerWidgetLibraryAssets();
    }

    protected function loadData($viewName, $view): void
    {
        $this->hasData = true;

        if ($viewName === 'ConfigCalendarioAuralis') {
            $this->disableSettingsCrudButtons($viewName);
            $view->loadData(CalendarTimegrid::SETTING_GROUP);
            $view->model->name = CalendarTimegrid::SETTING_GROUP;
            $view->model->calendar_hora_inicio = (string)CalendarTimegrid::horaInicioScroll();
            $view->model->calendar_hora_fin = (string)CalendarTimegrid::horaFinConfigurada();
            $view->model->mostrar_en_dashboard_auralis = (string)Tools::settings(CalendarTimegrid::SETTING_GROUP, 'mostrar_en_dashboard_auralis', '0');
            $view->model->calendar_duracion_default = (string)CalendarTimegrid::duracionDefault();
            $view->model->calendar_default_notif_offset = (string)CalendarNotificationDefaults::offsetValue();
            $view->model->calendar_default_notif_unit = CalendarNotificationDefaults::offsetUnit();
            $view->model->calendar_inapp_notifications = AuralisNotificationManager::isInAppEnabled();
            $view->model->calendar_inapp_poll_seconds = (string)AuralisNotificationManager::pollSeconds();
            $view->model->calendar_mi_show_event_types = CalendarTimegrid::miCalendarioMuestraTipos();
            $view->model->calendar_mi_show_event_status = CalendarTimegrid::miCalendarioMuestraEstados();
            $view->model->calendar_mi_show_rooms = CalendarTimegrid::miCalendarioMuestraSalas();
            $view->model->calendar_event_doc_show = CalendarTimegrid::miCalendarioMuestraDocVenta();
            $view->model->calendar_event_doc_tipo = CalendarTimegrid::miCalendarioTipoDocVenta();
            $view->model->calendar_push_notifications = AuralisPushSettings::isPushEnabled();
            $view->model->calendar_push_skip_if_online_seconds = (string)AuralisPushSettings::skipIfOnlineSeconds();
            $view->model->calendar_push_batch_limit = (string)AuralisPushSettings::batchLimit();
            $view->model->calendar_push_max_retry_hours = (string)AuralisPushSettings::maxRetryHours();
            $view->model->calendar_push_max_subs_per_user = (string)AuralisPushSettings::maxSubsPerUser();
            $view->model->calendar_push_vapid_public = AuralisPushSettings::vapidPublic();
            $view->model->calendar_push_generate_vapid = false;
            $view->model->{AuralisPushSettings::SETTING_PWA_ICON_ID} = AuralisPushSettings::pwaIconId() ?: null;
            $view->model->{CalendarioAcceso::SETTING_COMUNES_GESTIONAN} = CalendarioAcceso::modoGestionComunes();
            $view->model->{CalendarioAcceso::SETTING_COMUNES_ROLES} = implode(',', CalendarioAcceso::rolesGestores());
            $view->model->{CalendarioAcceso::SETTING_CALENDARIO_DEFECTO} = CalendarioAcceso::calendarioDefectoConfig();
            $view->model->{CalendarioAcceso::SETTING_PERMITIR_COMPARTIR} = CalendarioAcceso::permitirCompartir();
            $view->model->{CalendarioAcceso::SETTING_PERMITIR_EXTRA} = CalendarioAcceso::permitirCalendariosExtra();
            return;
        }

        if ($viewName === 'ListAuralisCalendario') {
            // Al vuelo: una fila por módulo activo. Los personales nunca se listan aquí.
            CalendarioAcceso::asegurarCalendariosModulo();
            $view->loadData('', [Where::in('tipo', [AuralisCalendario::TIPO_COMUN, AuralisCalendario::TIPO_MODULO])]);
            $this->assignAccessCounts($view);
            return;
        }

        if ($view instanceof ListView) {
            $view->loadData('', []);
        }
    }

    private function disableSettingsCrudButtons(string $viewName): void
    {
        if (!isset($this->views[$viewName])) {
            return;
        }

        $this->views[$viewName]->settings['btnNew'] = false;
        $this->views[$viewName]->settings['btnDelete'] = false;
    }

    /**
     * Opciones dinámicas de los ajustes de calendarios: roles gestores y calendario por defecto (personal o un común).
     */
    private function assignCalendarSettingsValues(string $viewName): void
    {
        if (!isset($this->views[$viewName])) {
            return;
        }

        $roles = $this->views[$viewName]->columnForName(CalendarioAcceso::SETTING_COMUNES_ROLES);
        if ($roles && $roles->widget) {
            $roles->widget->setValuesFromCodeModel($this->codeModel->all('roles', 'codrole', 'descripcion', false));
        }

        $defecto = $this->views[$viewName]->columnForName(CalendarioAcceso::SETTING_CALENDARIO_DEFECTO);
        if ($defecto && $defecto->widget) {
            $opciones = [['value' => CalendarioAcceso::DEFECTO_PERSONAL, 'title' => Tools::trans('auralis-calendario-defecto-personal')]];
            $comunes = AuralisCalendario::all([
                Where::eq('tipo', AuralisCalendario::TIPO_COMUN),
                Where::eq('activo', true),
            ], ['nombre' => 'ASC'], 0, 0);
            foreach ($comunes as $comun) {
                $opciones[] = ['value' => (string)$comun->idcalendario, 'title' => (string)$comun->nombre];
            }
            $defecto->widget->setValuesFromArray($opciones, false, false);
        }
    }

    /**
     * Columna «Accesos» del listado de calendarios: número de accesos de cada uno (una sola consulta).
     *
     * @param ListView $view
     */
    private function assignAccessCounts($view): void
    {
        $ids = [];
        foreach ($view->cursor as $calendario) {
            $ids[] = (int)$calendario->idcalendario;
        }

        $totales = [];
        if ($ids !== []) {
            foreach (AuralisCalendarioAcceso::all([Where::in('idcalendario', $ids)], [], 0, 0) as $acceso) {
                $totales[(int)$acceso->idcalendario] = ($totales[(int)$acceso->idcalendario] ?? 0) + 1;
            }
        }

        foreach ($view->cursor as $calendario) {
            $calendario->num_accesos = $totales[(int)$calendario->idcalendario] ?? 0;
        }
    }

    private function assignOriginColumnValues(string $viewName): void
    {
        if (!isset($this->views[$viewName])) {
            return;
        }

        $column = $this->views[$viewName]->columnForName('origen_tipo');
        if ($column && $column->widget) {
            $column->widget->setValuesFromArray(CalendarOrigins::getSelectOptions(), false, false);
        }
    }

    /**
     * Permisos y CSRF de las acciones AJAX de la Biblioteca (icono PWA) antes de que las resuelva el PanelController.
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
        return [AuralisPushSettings::SETTING_PWA_ICON_ID];
    }

    protected function bibliotecaAccept(): string
    {
        return AuralisPushSettings::PWA_ICON_ACCEPT;
    }

    protected function bibliotecaExtensionPermitida(string $extension): bool
    {
        return AuralisPushSettings::isSafePwaIconExtension($extension);
    }

    protected function bibliotecaValorSeleccionado(string $colName): int
    {
        return AuralisPushSettings::pwaIconId();
    }

    protected function execPreviousAction($action = '')
    {
        if (in_array($action, ['edit', 'insert'], true) && $this->active === 'ConfigCalendarioAuralis') {
            return $this->saveCalendarConfigAction();
        }

        return parent::execPreviousAction($action);
    }

    private function saveCalendarConfigAction(): bool
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return false;
        }

        if (false === $this->validateFormToken()) {
            return false;
        }

        $post = $this->request->request->all();
        $group = CalendarTimegrid::SETTING_GROUP;
        $hora = (int)($post['calendar_hora_inicio'] ?? 8);
        $hora = max(0, min(23, $hora));
        $horaFin = CalendarTimegrid::normalizarHoraFin(($post[CalendarTimegrid::SETTING_HORA_FIN] ?? '') !== '' ? $post[CalendarTimegrid::SETTING_HORA_FIN] : CalendarTimegrid::DEFAULT_HORA_FIN);
        $duracion = CalendarTimegrid::normalizarDuracion($post['calendar_duracion_default'] ?? CalendarTimegrid::DEFAULT_DURACION);

        // settingsSet sobrescribe; Tools::settings() solo asigna default si la clave no existe.
        Tools::settingsSet($group, 'calendar_hora_inicio', $hora);
        Tools::settingsSet($group, CalendarTimegrid::SETTING_HORA_FIN, $horaFin);
        if ($horaFin <= $hora) {
            Tools::log()->warning('calendar-hora-fin-no-posterior', ['%fin%' => $horaFin, '%inicio%' => $hora]);
        }
        Tools::settingsSet($group, 'calendar_duracion_default', $duracion);
        Tools::settingsSet($group, 'mostrar_en_dashboard_auralis', !empty($post['mostrar_en_dashboard_auralis']) ? '1' : '0');

        $notifOffset = (int)($post['calendar_default_notif_offset'] ?? 15);
        $notifUnit = (string)($post['calendar_default_notif_unit'] ?? AuralisCalendarEventNotification::UNIT_MINUTES);
        if (!in_array($notifUnit, [
            AuralisCalendarEventNotification::UNIT_MINUTES,
            AuralisCalendarEventNotification::UNIT_HOURS,
            AuralisCalendarEventNotification::UNIT_DAYS,
        ], true)) {
            $notifUnit = AuralisCalendarEventNotification::UNIT_MINUTES;
        }
        $notifOffset = max(0, min(10080, $notifOffset));

        Tools::settingsSet($group, CalendarNotificationDefaults::SETTING_OFFSET, $notifOffset);
        Tools::settingsSet($group, CalendarNotificationDefaults::SETTING_UNIT, $notifUnit);

        $inAppEnabled = !empty($post['calendar_inapp_notifications']) ? '1' : '0';
        $pollSeconds = (int)($post['calendar_inapp_poll_seconds'] ?? AuralisNotificationManager::DEFAULT_POLL_SECONDS);
        $pollSeconds = max(60, min(90, $pollSeconds));

        Tools::settingsSet($group, AuralisNotificationManager::SETTING_INAPP_ENABLED, $inAppEnabled);
        Tools::settingsSet($group, AuralisNotificationManager::SETTING_POLL_SECONDS, $pollSeconds);

        $showTypes = !empty($post['calendar_mi_show_event_types']) ? '1' : '0';
        $showStatus = !empty($post['calendar_mi_show_event_status']) ? '1' : '0';
        $showRooms = !empty($post['calendar_mi_show_rooms']) ? '1' : '0';
        Tools::settingsSet($group, CalendarTimegrid::SETTING_MI_SHOW_EVENT_TYPES, $showTypes);
        Tools::settingsSet($group, CalendarTimegrid::SETTING_MI_SHOW_EVENT_STATUS, $showStatus);
        Tools::settingsSet($group, CalendarTimegrid::SETTING_MI_SHOW_ROOMS, $showRooms);

        $showDoc = !empty($post['calendar_event_doc_show']) ? '1' : '0';
        $tipoDoc = CalendarTimegrid::normalizarTipoDocVenta($post['calendar_event_doc_tipo'] ?? CalendarTimegrid::DEFAULT_EVENT_DOC_TIPO);
        Tools::settingsSet($group, CalendarTimegrid::SETTING_EVENT_DOC_SHOW, $showDoc);
        Tools::settingsSet($group, CalendarTimegrid::SETTING_EVENT_DOC_TIPO, $tipoDoc);

        $pushEnabled = !empty($post['calendar_push_notifications']) ? '1' : '0';
        $skipOnline = (int)($post['calendar_push_skip_if_online_seconds'] ?? AuralisPushSettings::DEFAULT_SKIP_ONLINE);
        $skipOnline = max(60, min(900, $skipOnline));
        $batchLimit = (int)($post['calendar_push_batch_limit'] ?? AuralisPushSettings::DEFAULT_BATCH_LIMIT);
        $batchLimit = max(1, min(200, $batchLimit));
        $maxRetryHours = (int)($post['calendar_push_max_retry_hours'] ?? AuralisPushSettings::DEFAULT_MAX_RETRY_HOURS);
        $maxRetryHours = max(1, min(168, $maxRetryHours));
        $maxSubs = (int)($post['calendar_push_max_subs_per_user'] ?? AuralisPushSettings::DEFAULT_MAX_SUBS);
        $maxSubs = max(1, min(20, $maxSubs));

        Tools::settingsSet($group, AuralisPushSettings::SETTING_PUSH_ENABLED, $pushEnabled);
        Tools::settingsSet($group, AuralisPushSettings::SETTING_SKIP_ONLINE, $skipOnline);
        Tools::settingsSet($group, AuralisPushSettings::SETTING_BATCH_LIMIT, $batchLimit);
        Tools::settingsSet($group, AuralisPushSettings::SETTING_MAX_RETRY_HOURS, $maxRetryHours);
        Tools::settingsSet($group, AuralisPushSettings::SETTING_MAX_SUBS, $maxSubs);

        $this->savePwaIconFromRequest($post);
        $this->saveCalendarsSettings($post);

        if (!empty($post['calendar_push_generate_vapid']) || ($pushEnabled === '1' && AuralisPushSettings::vapidPublic() === '')) {
            if (false === PushCryptoCapability::isReady()) {
                Tools::log()->warning('auralis-push-sin-openssl');
            } elseif (false === AuralisPushSettings::generateAndStoreVapid(
                isset($this->user->nick) ? (string)$this->user->nick : null
            )) {
                Tools::log()->warning('auralis-push-vapid-fail');
            } else {
                Tools::log()->notice('auralis-push-vapid-ok');
            }
        }

        // Si hay pública pero el privado legacy no abre como PEM, forzar regeneración segura.
        if ($pushEnabled === '1'
            && AuralisPushSettings::vapidPublic() !== ''
            && AuralisPushSettings::vapidPrivatePem() === ''
            && PushCryptoCapability::isReady()) {
            AuralisPushSettings::generateAndStoreVapid(
                isset($this->user->nick) ? (string)$this->user->nick : null
            );
        }

        if ($pushEnabled === '1' && false === AuralisPwaPaths::isHttps()) {
            Tools::log()->warning('auralis-push-https-required');
        }

        Tools::settingsSave();
        Tools::log()->notice('record-updated-correctly');

        return true;
    }

    /**
     * Ajustes de calendarios (grupo auralis_calendario): quién gestiona los comunes, calendario por defecto,
     * compartir y calendarios personales extra.
     *
     * @param array<string, mixed> $post
     */
    private function saveCalendarsSettings(array $post): void
    {
        $group = CalendarTimegrid::SETTING_GROUP;

        $modo = (string)($post[CalendarioAcceso::SETTING_COMUNES_GESTIONAN] ?? CalendarioAcceso::GESTIONAN_ADMIN);
        $modo = $modo === CalendarioAcceso::GESTIONAN_ROLES ? CalendarioAcceso::GESTIONAN_ROLES : CalendarioAcceso::GESTIONAN_ADMIN;
        $roles = CalendarioAcceso::normalizarListaRoles($post[CalendarioAcceso::SETTING_COMUNES_ROLES] ?? '');

        $defecto = trim((string)($post[CalendarioAcceso::SETTING_CALENDARIO_DEFECTO] ?? CalendarioAcceso::DEFECTO_PERSONAL));
        if ($defecto !== CalendarioAcceso::DEFECTO_PERSONAL) {
            $comun = new AuralisCalendario();
            $valido = ctype_digit($defecto) && $comun->load((int)$defecto) && $comun->esComun();
            $defecto = $valido ? (string)(int)$defecto : CalendarioAcceso::DEFECTO_PERSONAL;
        }

        Tools::settingsSet($group, CalendarioAcceso::SETTING_COMUNES_GESTIONAN, $modo);
        Tools::settingsSet($group, CalendarioAcceso::SETTING_COMUNES_ROLES, implode(',', $roles));
        Tools::settingsSet($group, CalendarioAcceso::SETTING_CALENDARIO_DEFECTO, $defecto);
        Tools::settingsSet($group, CalendarioAcceso::SETTING_PERMITIR_COMPARTIR, !empty($post[CalendarioAcceso::SETTING_PERMITIR_COMPARTIR]) ? '1' : '0');
        Tools::settingsSet($group, CalendarioAcceso::SETTING_PERMITIR_EXTRA, !empty($post[CalendarioAcceso::SETTING_PERMITIR_EXTRA]) ? '1' : '0');
    }

    /**
     * @param array<string, mixed> $post
     */
    private function savePwaIconFromRequest(array $post): void
    {
        $group = AuralisPushSettings::group();

        if (!empty($post['delete_calendar_pwa_icon'])) {
            $oldId = (int)Tools::settings($group, AuralisPushSettings::SETTING_PWA_ICON_ID, '0');
            if ($oldId > 0) {
                $oldAttached = new AttachedFile();
                if ($oldAttached->load($oldId)) {
                    $oldAttached->delete();
                }
            }
            Tools::settingsSet($group, AuralisPushSettings::SETTING_PWA_ICON_ID, '');
            Tools::settingsSet($group, AuralisPushSettings::SETTING_PWA_ICON, '');
            return;
        }

        $libraryId = $post[AuralisPushSettings::SETTING_PWA_ICON_ID] ?? null;
        if (empty($libraryId) || false === is_numeric($libraryId)) {
            return;
        }

        $attached = new AttachedFile();
        if (false === $attached->load((int)$libraryId)) {
            return;
        }

        $filename = (string)($attached->filename ?? '');
        if (false === AuralisPushSettings::isSafePwaIconFilename($filename)) {
            Tools::log()->warning('auralis-pwa-icon-invalid-format');
            return;
        }

        // Además del nombre, el contenido real del fichero debe ser una imagen coherente con la extensión.
        $extension = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_EXTENSION);
        if (false === AuralisSecurity::esImagenPublicaSegura($attached->getFullPath(), $extension)) {
            Tools::log()->warning('auralis-pwa-icon-invalid-format');
            return;
        }

        $currentId = (int)Tools::settings($group, AuralisPushSettings::SETTING_PWA_ICON_ID, '0');
        if ($currentId > 0 && $currentId === (int)$attached->idfile) {
            return;
        }

        Tools::settingsSet($group, AuralisPushSettings::SETTING_PWA_ICON_ID, (string)$attached->idfile);
        Tools::settingsSet($group, AuralisPushSettings::SETTING_PWA_ICON, $attached->url('download-permanent'));
    }

    private function assignSettingsPanelUrl(string $viewName): void
    {
        if (!isset($this->views[$viewName])) {
            return;
        }

        $this->views[$viewName]->model = new class extends Settings {
            public function url(string $type = 'auto', string $list = 'Edit'): string
            {
                return 'ConfigAuralisServiciosCalendario?activetab=ConfigCalendarioAuralis';
            }

            public function primaryDescription(): string
            {
                return Tools::trans('auralis-config-calendario');
            }
        };
    }
}

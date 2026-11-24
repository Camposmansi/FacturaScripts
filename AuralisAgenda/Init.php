<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda;

use FacturaScripts\Core\Lib\AjaxForms\SalesHeaderHTML;
use FacturaScripts\Core\Lib\API\APIModel;
use FacturaScripts\Core\Migrations;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Dinamic\Model\EmailNotification;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\AlbaranCliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Controller\EditRole;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Controller\EditUser;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Model\Empresa as EmpresaExtension;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Model\AlbaranCliente as AlbaranClienteExtension;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Model\FacturaCliente as FacturaClienteExtension;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Model\PedidoCliente as PedidoClienteExtension;
use FacturaScripts\Plugins\AuralisAgenda\Extension\Model\PresupuestoCliente as PresupuestoClienteExtension;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisNotificationManager;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisPushSettings;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisRolesPredefinidos;
use FacturaScripts\Plugins\AuralisAgenda\Lib\AuralisTwigFunctions;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarEventAjax;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarNotificationRegistry;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarParticipantGroupRegistry;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarParticipantSync;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarSalasMenu;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarTimegrid;
use FacturaScripts\Plugins\AuralisAgenda\Lib\GeneralCalendarNotificationProvider;
use FacturaScripts\Plugins\AuralisAgenda\Lib\NotificationTypeRegistry;
use FacturaScripts\Plugins\AuralisAgenda\Lib\Providers\RoleCalendarParticipantGroupProvider;
use FacturaScripts\Plugins\AuralisAgenda\Mod\AuralisCalendarEventDocSalesMod;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEvent;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventStatus;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarEventType;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendario;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisCalendarioPreferencia;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisNotification;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisPushSubscription;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisSala;
use Throwable;

/** Inicialización autónoma de Auralis Agenda. No requiere el plugin Auralis. */
final class Init extends InitClass
{
    private const SETTING_GROUP = 'auralis_calendario';

    public function init(): void
    {
        APIModel::excludeModel('AuralisPushSubscription');
        APIModel::excludeModel('AuralisNotification');
        APIModel::excludeModel('AuralisCalendarEventNotifDelivery');
        CalendarNotificationRegistry::register(new GeneralCalendarNotificationProvider());
        CalendarParticipantGroupRegistry::register(new RoleCalendarParticipantGroupProvider());
        NotificationTypeRegistry::ensureIncorporados();
        WorkQueue::addWorker('AuralisPushDispatchWorker', 'AuralisAgenda.Push.DispatchLate');
        AuralisTwigFunctions::register();
        Empresa::addExtension(new EmpresaExtension());
        PresupuestoCliente::addExtension(new PresupuestoClienteExtension());
        PedidoCliente::addExtension(new PedidoClienteExtension());
        AlbaranCliente::addExtension(new AlbaranClienteExtension());
        FacturaCliente::addExtension(new FacturaClienteExtension());
        $this->loadExtension(new EditUser());
        $this->loadExtension(new EditRole());
        SalesHeaderHTML::addMod(new AuralisCalendarEventDocSalesMod());
        $this->ensureDefaults();
    }

    public function update(): void
    {
        $this->ensureDefaults();
        $this->ensureSchema();
        Migrations::runPluginMigrations([
            new Migration\BackfillCalendarNotificationScheduledAt(),
            new Migration\LimpiarDescripcionesFranjaHorariaCalendario(),
            new Migration\BackfillCalendarEventParticipants(),
            new Migration\MigrationCalendariosPersonales(),
            new Migration\CrearComunEmpresa(),
            new Migration\ResellarSecretoVapid(),
        ]);
        CalendarSalasMenu::sincronizar();
        CalendarEventAjax::seedDefaultStatusesIfEmpty();
        CalendarioAcceso::asegurarCalendariosModulo();
        $this->ensureRoles();
        $this->ensureEmailNotification();
    }

    public function uninstall(): void
    {
        // Se conservan tablas y datos para que una reinstalación no pierda agendas.
    }

    private function ensureDefaults(): void
    {
        AuralisPushSettings::ensureDefaults();
        $defaults = [
            CalendarTimegrid::SETTING_HORA_INICIO => (string)CalendarTimegrid::DEFAULT_HORA_INICIO,
            CalendarTimegrid::SETTING_HORA_FIN => (string)CalendarTimegrid::DEFAULT_HORA_FIN,
            CalendarTimegrid::SETTING_DURACION_DEFAULT => (string)CalendarTimegrid::DEFAULT_DURACION,
            AuralisNotificationManager::SETTING_INAPP_ENABLED => '1',
            AuralisNotificationManager::SETTING_POLL_SECONDS => (string)AuralisNotificationManager::DEFAULT_POLL_SECONDS,
            CalendarTimegrid::SETTING_MI_SHOW_EVENT_TYPES => '0',
            CalendarTimegrid::SETTING_MI_SHOW_EVENT_STATUS => '0',
            CalendarTimegrid::SETTING_MI_SHOW_ROOMS => '0',
            CalendarTimegrid::SETTING_EVENT_DOC_SHOW => '1',
            CalendarTimegrid::SETTING_EVENT_DOC_TIPO => CalendarTimegrid::DEFAULT_EVENT_DOC_TIPO,
            'mostrar_en_dashboard_auralis' => '0',
        ];
        foreach ($defaults as $key => $value) {
            if (null === Tools::settings(self::SETTING_GROUP, $key)) {
                Tools::settingsSet(self::SETTING_GROUP, $key, $value);
            }
        }
    }

    private function ensureSchema(): void
    {
        new AuralisCalendarEventType();
        new AuralisCalendarEventStatus();
        new AuralisCalendario();
        new AuralisCalendarioAcceso();
        new AuralisCalendarioPreferencia();
        new AuralisSala();
        new AuralisCalendarEvent();
        new AuralisCalendarEventNotification();
        CalendarParticipantSync::ensureSchema();
        new AuralisNotification();
        new AuralisPushSubscription();
    }

    private function ensureRoles(): void
    {
        try {
            foreach (AuralisRolesPredefinidos::PAGINAS_CONCESION_GENERAL as $page) {
                AuralisRolesPredefinidos::concederPaginaARolesNoPlantilla($page);
            }
            (new AuralisRolesPredefinidos())->ensure();
        } catch (Throwable $e) {
            Tools::log('AuralisAgenda')->warning('Permisos de Agenda: ' . $e->getMessage());
        }
    }

    private function ensureEmailNotification(): void
    {
        $notification = new EmailNotification();
        if ($notification->load(GeneralCalendarNotificationProvider::NOTIFICATION_GENERAL)) {
            return;
        }
        $notification->name = GeneralCalendarNotificationProvider::NOTIFICATION_GENERAL;
        $notification->subject = Tools::trans('auralis-calendar-reminder');
        $notification->body = Tools::trans('auralis-calendar-reminder-body');
        $notification->enabled = true;
        $notification->save();
    }
}

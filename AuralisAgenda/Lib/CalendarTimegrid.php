<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Configuración y variables Twig del calendario timegrid 24h de Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;

class CalendarTimegrid
{
    public const SETTING_GROUP = 'auralis_calendario';

    public const SETTING_HORA_INICIO = 'calendar_hora_inicio';

    /** Hora (1–24) en la que acaba el horario visible de la rejilla (exclusiva). */
    public const SETTING_HORA_FIN = 'calendar_hora_fin';

    public const SETTING_DURACION_DEFAULT = 'calendar_duracion_default';

    /** Mostrar selector de tipo de evento en Mi calendario (modal + filtro). */
    public const SETTING_MI_SHOW_EVENT_TYPES = 'calendar_mi_show_event_types';

    /** Mostrar selector de estado en Mi calendario (modal + filtro). */
    public const SETTING_MI_SHOW_EVENT_STATUS = 'calendar_mi_show_event_status';

    /** Mostrar selector de sala en Mi calendario (modal). */
    public const SETTING_MI_SHOW_ROOMS = 'calendar_mi_show_rooms';

    /** Controlador destino del icono de agenda en la barra (verticales pueden sustituirlo). */
    public const SETTING_TOPBAR_CONTROLLER = 'calendar_topbar_controller';

    /** Avisos de cita a todos los usuarios activos de la empresa. */
    public const SETTING_NOTIF_ALL_USERS = 'calendar_notif_all_users';

    /** Mostrar botón de documento de venta en el modal de Mi calendario. */
    public const SETTING_EVENT_DOC_SHOW = 'calendar_event_doc_show';

    /** Tipo de documento de venta a crear desde el modal (modelo Dinamic). */
    public const SETTING_EVENT_DOC_TIPO = 'calendar_event_doc_tipo';

    public const DEFAULT_EVENT_DOC_TIPO = 'PresupuestoCliente';

    /** @var list<string> */
    public const EVENT_DOC_TIPOS = [
        'PresupuestoCliente',
        'PedidoCliente',
        'AlbaranCliente',
        'FacturaCliente',
    ];

    public const DEFAULT_HORA_INICIO = 7;

    public const DEFAULT_HORA_FIN = 21;

    public const DEFAULT_DURACION = 60;

    public const MIN_DURACION = 5;

    public const MAX_DURACION = 480;

    public const TOTAL_HORAS = 24;

    /**
     * Hora (0–23) en la que debe posicionarse el scroll inicial del timegrid.
     */
    public static function horaInicioScroll(): int
    {
        $raw = Tools::settings(self::SETTING_GROUP, self::SETTING_HORA_INICIO, (string)self::DEFAULT_HORA_INICIO);
        $hora = (int)$raw;

        return max(0, min(23, $hora));
    }

    /**
     * Hora en la que acaba el horario visible (1–24, exclusiva). La rejilla de página completa ajusta de
     * horaInicioScroll() a esta hora al alto de la pantalla; si no es posterior al inicio, se ven hasta las 24.
     */
    public static function horaFinVisible(): int
    {
        $hora = self::horaFinConfigurada();

        return $hora > self::horaInicioScroll() ? $hora : self::TOTAL_HORAS;
    }

    /**
     * Hora de fin guardada tal cual (1–24), sin el ajuste de horaFinVisible(). Es la que se muestra en el formulario.
     */
    public static function horaFinConfigurada(): int
    {
        return self::normalizarHoraFin(
            Tools::settings(self::SETTING_GROUP, self::SETTING_HORA_FIN, (string)self::DEFAULT_HORA_FIN)
        );
    }

    /**
     * Normaliza la hora de fin (1–24) para persistencia en settings.
     */
    public static function normalizarHoraFin(mixed $valor): int
    {
        return max(1, min(24, (int)$valor));
    }

    /**
     * Duración por defecto (minutos) al crear eventos nuevos.
     */
    public static function duracionDefault(): int
    {
        return self::normalizarDuracion(
            Tools::settings(self::SETTING_GROUP, self::SETTING_DURACION_DEFAULT, (string)self::DEFAULT_DURACION)
        );
    }

    /**
     * Normaliza una hora de entrada para persistencia en settings.
     */
    public static function normalizarHora(mixed $valor): int
    {
        $hora = (int)$valor;

        return max(0, min(23, $hora));
    }

    /**
     * Normaliza duración en minutos (5–480) para persistencia en settings.
     */
    public static function normalizarDuracion(mixed $valor): int
    {
        $duracion = (int)$valor;
        if ($duracion < self::MIN_DURACION || $duracion > self::MAX_DURACION) {
            return self::DEFAULT_DURACION;
        }

        return $duracion;
    }

    /**
     * ¿Mostrar tipos de evento en Mi calendario (modal + filtro de cabecera)?
     */
    public static function miCalendarioMuestraTipos(): bool
    {
        return (int)Tools::settings(self::SETTING_GROUP, self::SETTING_MI_SHOW_EVENT_TYPES, '0') === 1;
    }

    /**
     * ¿Mostrar estados de evento en Mi calendario (modal + filtro de cabecera)?
     */
    public static function miCalendarioMuestraEstados(): bool
    {
        return (int)Tools::settings(self::SETTING_GROUP, self::SETTING_MI_SHOW_EVENT_STATUS, '0') === 1;
    }

    /**
     * ¿Mostrar salas en Mi calendario (selector del modal)?
     */
    public static function miCalendarioMuestraSalas(): bool
    {
        return (int)Tools::settings(self::SETTING_GROUP, self::SETTING_MI_SHOW_ROOMS, '0') === 1;
    }

    /**
     * Controlador del icono de agenda en la barra superior.
     */
    public static function topbarController(): string
    {
        $ctrl = trim((string) Tools::settings(self::SETTING_GROUP, self::SETTING_TOPBAR_CONTROLLER, 'CalendarioUsuario'));
        if ($ctrl === '') {
            return 'CalendarioUsuario';
        }

        $class = 'FacturaScripts\\Dinamic\\Controller\\' . $ctrl;
        if (false === class_exists($class)) {
            return 'CalendarioUsuario';
        }

        return $ctrl;
    }

    /**
     * ¿Materializar avisos de agenda para todos los usuarios activos?
     */
    public static function notifAllUsers(): bool
    {
        return (int) Tools::settings(self::SETTING_GROUP, self::SETTING_NOTIF_ALL_USERS, '0') === 1;
    }

    /**
     * ¿Mostrar el botón de crear documento de venta en el modal de Mi calendario?
     */
    public static function miCalendarioMuestraDocVenta(): bool
    {
        return (int) Tools::settings(self::SETTING_GROUP, self::SETTING_EVENT_DOC_SHOW, '1') === 1;
    }

    /**
     * Modelo de documento de venta configurado (allowlist; fallback presupuesto).
     */
    public static function miCalendarioTipoDocVenta(): string
    {
        $tipo = trim((string) Tools::settings(
            self::SETTING_GROUP,
            self::SETTING_EVENT_DOC_TIPO,
            self::DEFAULT_EVENT_DOC_TIPO
        ));

        return self::normalizarTipoDocVenta($tipo);
    }

    /**
     * Controlador Edit* del tipo de documento de venta configurado.
     */
    public static function miCalendarioEditDocVenta(): string
    {
        return self::editControllerParaTipoDoc(self::miCalendarioTipoDocVenta());
    }

    /**
     * Clave i18n Core del tipo de documento (estimation, order, …).
     */
    public static function miCalendarioLabelKeyDocVenta(): string
    {
        return self::labelKeyParaTipoDoc(self::miCalendarioTipoDocVenta());
    }

    public static function normalizarTipoDocVenta(mixed $valor): string
    {
        $tipo = trim((string) $valor);
        if (in_array($tipo, self::EVENT_DOC_TIPOS, true)) {
            return $tipo;
        }

        return self::DEFAULT_EVENT_DOC_TIPO;
    }

    public static function editControllerParaTipoDoc(string $tipo): string
    {
        return match (self::normalizarTipoDocVenta($tipo)) {
            'PedidoCliente' => 'EditPedidoCliente',
            'AlbaranCliente' => 'EditAlbaranCliente',
            'FacturaCliente' => 'EditFacturaCliente',
            default => 'EditPresupuestoCliente',
        };
    }

    public static function labelKeyParaTipoDoc(string $tipo): string
    {
        return match (self::normalizarTipoDocVenta($tipo)) {
            'PedidoCliente' => 'order',
            'AlbaranCliente' => 'delivery-note',
            'FacturaCliente' => 'invoice',
            default => 'estimation',
        };
    }

    /**
     * URL de alta precargada desde el evento (conserva action=from-calendar).
     *
     * @param array<string, string> $query
     */
    public static function urlCrearDocDesdeEvento(string $editController, array $query): string
    {
        $edit = trim($editController) !== '' ? $editController : 'EditPresupuestoCliente';
        $params = array_merge(['action' => 'from-calendar'], $query);

        return $edit . '?' . http_build_query($params);
    }

    /**
     * URL de edición del documento ya vinculado.
     */
    public static function urlVerDocVinculado(string $modelo, int $docId): string
    {
        if ($docId <= 0) {
            return self::editControllerParaTipoDoc($modelo);
        }

        return self::editControllerParaTipoDoc($modelo) . '?code=' . $docId;
    }

    /**
     * Variables para Html::render() del partial _calendar_timegrid.html.twig.
     *
     * @return array<string, mixed>
     */
    public static function twigVars(): array
    {
        return [
            'timegrid_hora_fin' => self::horaFinVisible(),
            'timegrid_hours' => range(0, self::TOTAL_HORAS - 1),
            'timegrid_scroll_hour' => self::horaInicioScroll(),
            'timegrid_total_hours' => self::TOTAL_HORAS,
        ];
    }
}

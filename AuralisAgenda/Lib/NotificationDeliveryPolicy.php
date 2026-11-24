<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Quién ve cada tipo de aviso y cuándo se reintenta el push, sin tocar la base de datos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\AuralisAgenda\Model\AuralisNotification;

final class NotificationDeliveryPolicy
{
    public const MAX_INTENTOS_PUSH = 5;

    /**
     * Esperas tras un 429, un 5xx o un aplazamiento (en línea, sin suscripción), en minutos.
     * El quinto fallo cierra el aviso
     * y no programa la última espera: si no, el cron seguiría intentándolo.
     *
     * @var list<int>
     */
    public const ESPERAS_MINUTOS = [1, 5, 15, 60, 180];

    /**
     * Tipos que pueden salir en Actividad para este usuario.
     * Changelog no entra: las novedades siguen en su pestaña.
     *
     * @param callable(string): bool $puede recibe el permiso del tipo
     * @return list<string>
     */
    public static function clavesCampana(callable $puede): array
    {
        $claves = [];
        foreach (NotificationTypeRegistry::claves() as $clave) {
            if (self::visibleEnCampana(NotificationTypeRegistry::get($clave), $puede)) {
                $claves[] = $clave;
            }
        }

        return $claves;
    }

    /**
     * @param callable(string): bool $puede
     * @return list<string>
     */
    public static function clavesVentana(callable $puede): array
    {
        $claves = [];
        foreach (self::clavesCampana($puede) as $clave) {
            $tipo = NotificationTypeRegistry::get($clave);
            if ($tipo !== null && $tipo->ventanaEmergente()) {
                $claves[] = $clave;
            }
        }

        return $claves;
    }

    /**
     * @param callable(string): bool $puede
     */
    public static function visibleEnCampana(?NotificationTypeInterface $tipo, callable $puede): bool
    {
        if ($tipo === null || $tipo->clave() === AuralisNotification::TYPE_CHANGELOG) {
            return false;
        }

        $permiso = $tipo->permiso();
        if ($permiso === '') {
            return true;
        }

        return (bool)$puede($permiso);
    }

    /**
     * @param callable(string): bool $puede
     */
    public static function admiteVentana(?NotificationTypeInterface $tipo, callable $puede): bool
    {
        return $tipo !== null
            && $tipo->ventanaEmergente()
            && self::visibleEnCampana($tipo, $puede);
    }

    public static function admitePush(?NotificationTypeInterface $tipo): bool
    {
        return $tipo !== null && $tipo->push();
    }

    /**
     * Texto genérico del tipo. No usa el título ni el cuerpo de la fila.
     *
     * @return array{titulo: string, cuerpo: string}
     */
    public static function textoPush(NotificationTypeInterface $tipo): array
    {
        $titulo = $tipo->texto();
        $cuerpo = $tipo->textoPush();

        return [
            'titulo' => $titulo === '' ? '' : Tools::trans($titulo),
            'cuerpo' => $cuerpo === '' ? '' : Tools::trans($cuerpo),
        ];
    }

    public static function httpReintentable(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * @return array{marcar_enviado: bool, espera_minutos: int, intentos: int}
     */
    public static function decidirReintento(int $status, int $intentos): array
    {
        $intentos = max(0, $intentos);
        if (false === self::httpReintentable($status)) {
            return [
                'marcar_enviado' => true,
                'espera_minutos' => 0,
                'intentos' => $intentos,
            ];
        }

        $nuevos = $intentos + 1;
        if ($nuevos >= self::MAX_INTENTOS_PUSH) {
            return [
                'marcar_enviado' => true,
                'espera_minutos' => 0,
                'intentos' => $nuevos,
            ];
        }

        return [
            'marcar_enviado' => false,
            'espera_minutos' => self::ESPERAS_MINUTOS[$nuevos - 1],
            'intentos' => $nuevos,
        ];
    }

    /**
     * Estados que el despachador vuelve a examinar. Null (filas antiguas) también cuenta.
     *
     * @return list<string>
     */
    public static function estadosPushCandidatos(): array
    {
        return [
            AuralisNotification::PUSH_PENDIENTE,
            AuralisNotification::PUSH_PENDIENTE_ONLINE,
            AuralisNotification::PUSH_SIN_SUSCRIPCION,
            AuralisNotification::PUSH_REINTENTO,
        ];
    }

    /**
     * Cierres sin entrega. Enviado no está: solo lo pone un 2xx.
     *
     * @return list<string>
     */
    public static function estadosPushCierre(): array
    {
        return [
            AuralisNotification::PUSH_SIN_PERMISO,
            AuralisNotification::PUSH_TIPO_SIN_PUSH,
            AuralisNotification::PUSH_MISSED,
            AuralisNotification::PUSH_AGOTADO,
            AuralisNotification::PUSH_CADUCADO,
        ];
    }

    public static function esEstadoPushCierre(string $estado): bool
    {
        return in_array($estado, self::estadosPushCierre(), true);
    }

    /**
     * Aplazamiento sin HTTP (destinatario en línea o sin suscripción) o tras 429/5xx.
     * Comparte techo y esperas con decidirReintento: el quinto intento cierra como agotado.
     *
     * @return array{estado: string, cerrar: bool, espera_minutos: int, intentos: int}
     */
    public static function decidirAplazamiento(string $estado, int $intentos): array
    {
        $diferidos = [
            AuralisNotification::PUSH_PENDIENTE_ONLINE,
            AuralisNotification::PUSH_SIN_SUSCRIPCION,
            AuralisNotification::PUSH_REINTENTO,
        ];
        if (false === in_array($estado, $diferidos, true)) {
            $estado = AuralisNotification::PUSH_REINTENTO;
        }

        $nuevos = max(0, $intentos) + 1;
        if ($nuevos >= self::MAX_INTENTOS_PUSH) {
            return [
                'estado' => AuralisNotification::PUSH_AGOTADO,
                'cerrar' => true,
                'espera_minutos' => 0,
                'intentos' => $nuevos,
            ];
        }

        return [
            'estado' => $estado,
            'cerrar' => false,
            'espera_minutos' => self::ESPERAS_MINUTOS[$nuevos - 1],
            'intentos' => $nuevos,
        ];
    }

    /**
     * El aviso es más antiguo que las horas máximas de reintento.
     * Sin fecha válida no caduca: una fila rara no se cierra a ciegas.
     */
    public static function pushCaducado(?string $createdAt, int $horasMax, ?int $nowTs = null): bool
    {
        $createdAt = trim((string)$createdAt);
        if ($createdAt === '') {
            return false;
        }

        $creadoTs = strtotime($createdAt);
        if ($creadoTs === false) {
            return false;
        }

        $horasMax = max(1, $horasMax);
        $nowTs = $nowTs ?? time();

        return $creadoTs + ($horasMax * 3600) < $nowTs;
    }
}

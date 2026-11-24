<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Permisos del usuario en una página concreta (roles del Core) para condicionar los botones del pie del modal de evento.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Session;

final class AuralisPermisosPagina
{
    /**
     * Lo que el rol del usuario permite en la página destino de un botón:
     * - ver: puede abrirla (allowAccess);
     * - editar: puede crear o modificar registros en ella (allowUpdate).
     * Un administrador lo tiene todo. Sin sesión o sin página, nada.
     *
     * @return array{ver: bool, editar: bool}
     */
    public static function de(string $pagina, ?User $usuario = null): array
    {
        $usuario ??= Session::user();
        $pagina = trim($pagina);
        if (empty($usuario->nick) || $pagina === '') {
            return ['ver' => false, 'editar' => false];
        }

        $permisos = new ControllerPermissions($usuario, $pagina);

        return [
            'ver' => $permisos->allowAccess,
            'editar' => $permisos->allowAccess && $permisos->allowUpdate,
        ];
    }
}

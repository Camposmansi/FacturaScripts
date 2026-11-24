<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Réplica de las reglas «solo sus datos» del Core (ListController::getOwnerFilter y OwnerDataTrait::checkOwnerData) para usarlas fuera de un controlador.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\User;

/**
 * Solo se aplica cuando ControllerPermissions(...)->onlyOwnerData es true;
 * esa comprobación la hace quien llama.
 */
final class KanbanPropiedad
{
    /**
     * Filtro de propiedad: (codagente = X OR nick = Y). Si el modelo tiene codagente
     * y el usuario no puede cumplir ningún criterio, devuelve un filtro imposible.
     *
     * @return Where[]
     */
    public static function filtro(ModelClass $model, User $user): array
    {
        $owner = [];

        if ($model->hasColumn('codagente') && false === empty($user->codagente)) {
            $owner[] = Where::eq('codagente', $user->codagente);
        }

        if ($model->hasColumn('nick')) {
            $owner[] = empty($owner)
                ? Where::eq('nick', $user->nick)
                : Where::orEq('nick', $user->nick);
        }

        if (empty($owner) && $model->hasColumn('codagente')) {
            return [Where::isNull($model->primaryColumn())];
        }

        return empty($owner) ? [] : [Where::sub($owner)];
    }

    /** El usuario es dueño del registro (coincide su agente o su nick). */
    public static function esPropietario(ModelClass $model, User $user): bool
    {
        if (empty($model->id())) {
            return true;
        }

        if (false === $model->hasColumn('codagente') && false === $model->hasColumn('nick')) {
            return true;
        }

        $checkAgente = $model->hasColumn('codagente') && false === empty($user->codagente);
        $checkNick = $model->hasColumn('nick');

        if ($checkAgente && $model->codagente === $user->codagente) {
            return true;
        }

        if ($checkNick && $model->nick === $user->nick) {
            return true;
        }

        return false;
    }
}

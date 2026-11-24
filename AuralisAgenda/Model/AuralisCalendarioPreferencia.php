<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Preferencia de un usuario sobre un calendario que ve: si lo muestra y con qué color (manda sobre el del calendario).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class AuralisCalendarioPreferencia extends ModelClass
{
    use ModelTrait;

    /** @var int|null */
    public $id;

    /** @var string */
    public $nick;

    /** @var string cal:{idcalendario} */
    public $clave;

    /** @var bool */
    public $visible;

    /** @var string|null NULL = color del calendario. */
    public $color;

    public static function claveDe(int $idcalendario): string
    {
        return 'cal:' . $idcalendario;
    }

    public function clear(): void
    {
        parent::clear();
        $this->nick = '';
        $this->clave = '';
        $this->visible = true;
        $this->color = null;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendario_preferencias';
    }

    public function test(): bool
    {
        $this->nick = Tools::noHtml(trim((string)$this->nick));
        $this->clave = trim((string)$this->clave);
        $this->visible = (bool)$this->visible;

        $color = trim((string)($this->color ?? ''));
        $this->color = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : null;

        if ($this->nick === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nick']);
            return false;
        }

        if (preg_match('/^cal:\d{1,10}$/', $this->clave) !== 1) {
            Tools::log()->warning('auralis-calendario-clave-invalida');
            return false;
        }

        $where = [Where::eq('nick', $this->nick), Where::eq('clave', $this->clave)];
        if (!empty($this->id)) {
            $where[] = Where::notEq('id', (int)$this->id);
        }
        if (static::count($where) > 0) {
            Tools::log()->warning('auralis-calendario-preferencia-duplicada');
            return false;
        }

        return parent::test();
    }
}

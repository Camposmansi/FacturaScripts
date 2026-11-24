<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Acceso compartido a un calendario: un usuario, un rol o toda la empresa (ambos vacíos) con permiso ocupado, ver o editar.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;

class AuralisCalendarioAcceso extends ModelClass
{
    use ModelTrait;

    public const PERMISO_OCUPADO = 'ocupado';
    public const PERMISO_VER = 'ver';
    public const PERMISO_EDITAR = 'editar';

    /** @var int|null */
    public $id;

    /** @var int */
    public $idcalendario;

    /** @var string|null Usuario concreto; NULL si el acceso es por rol o para toda la empresa. */
    public $nick;

    /** @var string|null Rol del Core; NULL si el acceso es por usuario o para toda la empresa. */
    public $codrole;

    /** @var string ocupado|ver|editar */
    public $permiso;

    /**
     * Permisos ordenados de menor a mayor.
     *
     * @return string[]
     */
    public static function permisosPermitidos(): array
    {
        return [self::PERMISO_OCUPADO, self::PERMISO_VER, self::PERMISO_EDITAR];
    }

    /**
     * Peso del permiso para compararlos (0 = ninguno).
     */
    public static function nivel(?string $permiso): int
    {
        $pos = array_search((string)$permiso, self::permisosPermitidos(), true);
        return $pos === false ? 0 : $pos + 1;
    }

    public function clear(): void
    {
        parent::clear();
        $this->idcalendario = null;
        $this->nick = null;
        $this->codrole = null;
        $this->permiso = self::PERMISO_VER;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_calendario_accesos';
    }

    public function esTodaLaEmpresa(): bool
    {
        return empty($this->nick) && empty($this->codrole);
    }

    public function test(): bool
    {
        $this->idcalendario = empty($this->idcalendario) ? null : (int)$this->idcalendario;
        $this->nick = self::textoONull($this->nick);
        $this->codrole = self::textoONull($this->codrole);
        $this->permiso = mb_strtolower(trim((string)$this->permiso));

        if ($this->idcalendario === null) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'idcalendario']);
            return false;
        }

        if (false === in_array($this->permiso, self::permisosPermitidos(), true)) {
            Tools::log()->warning('auralis-calendario-permiso-invalido');
            return false;
        }

        if ($this->nick !== null && $this->codrole !== null) {
            Tools::log()->warning('auralis-calendario-acceso-usuario-o-rol');
            return false;
        }

        // UNIQUE con NULL se comporta distinto en MySQL y PostgreSQL: la unicidad se valida aquí.
        if ($this->duplicado()) {
            Tools::log()->warning('auralis-calendario-acceso-duplicado');
            return false;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if (!empty($this->idcalendario)) {
            return 'EditAuralisCalendario?code=' . (int)$this->idcalendario . '&activetab=EditAuralisCalendarioAcceso';
        }

        return parent::url($type, $list);
    }

    private function duplicado(): bool
    {
        $where = [
            Where::eq('idcalendario', $this->idcalendario),
            $this->nick === null ? Where::isNull('nick') : Where::eq('nick', $this->nick),
            $this->codrole === null ? Where::isNull('codrole') : Where::eq('codrole', $this->codrole),
        ];
        if (!empty($this->id)) {
            $where[] = Where::notEq('id', (int)$this->id);
        }

        return static::count($where) > 0;
    }

    /**
     * @param mixed $valor
     */
    private static function textoONull($valor): ?string
    {
        $texto = Tools::noHtml(trim((string)($valor ?? '')));
        return $texto === '' ? null : $texto;
    }
}

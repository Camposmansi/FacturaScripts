<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Calendario de la agenda: personal (con dueño), común de empresa (sin dueño) o de módulo (uno por vertical, enlazado por origen_tipo).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarioAcceso;
use FacturaScripts\Plugins\AuralisAgenda\Lib\CalendarOrigins;

class AuralisCalendario extends ModelClass
{
    use ModelTrait;

    public const TIPO_PERSONAL = 'personal';
    public const TIPO_COMUN = 'comun';
    public const TIPO_MODULO = 'modulo';

    public const COLOR_DEFECTO = '#4285f4';

    /** @var int|null */
    public $idcalendario;

    /** @var string|null Solo los de módulo (origen_tipo) o códigos de comunes; NULL en personales. */
    public $codigo;

    /** @var string */
    public $nombre;

    /** @var string */
    public $color;

    /** @var string personal|comun|modulo */
    public $tipo;

    /** @var string|null Dueño (users.nick); NULL en comunes y módulos. */
    public $propietario;

    /** @var bool Calendario personal principal del propietario (uno por usuario). */
    public $principal;

    /** @var int|null NULL en módulos. */
    public $idempresa;

    /** @var bool */
    public $activo;

    /** @var string|null */
    public $descripcion;

    /** @var string|null */
    public $creado;

    /**
     * @return string[]
     */
    public static function tiposPermitidos(): array
    {
        return [self::TIPO_PERSONAL, self::TIPO_COMUN, self::TIPO_MODULO];
    }

    public function clear(): void
    {
        parent::clear();
        $this->codigo = null;
        $this->nombre = '';
        $this->color = self::COLOR_DEFECTO;
        $this->tipo = self::TIPO_COMUN;
        $this->propietario = null;
        $this->principal = false;
        $this->idempresa = null;
        $this->activo = true;
        $this->descripcion = null;
        $this->creado = Tools::dateTime();
    }

    public static function primaryColumn(): string
    {
        return 'idcalendario';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'nombre';
    }

    public static function tableName(): string
    {
        return 'auralis_calendarios';
    }

    public function esPersonal(): bool
    {
        return (string)$this->tipo === self::TIPO_PERSONAL;
    }

    public function esComun(): bool
    {
        return (string)$this->tipo === self::TIPO_COMUN;
    }

    public function esModulo(): bool
    {
        return (string)$this->tipo === self::TIPO_MODULO;
    }

    /**
     * Los de módulo y el personal principal no se borran (los de módulo se desactivan).
     */
    public function delete(): bool
    {
        if ($this->getOriginal('tipo') === self::TIPO_MODULO || $this->esModulo()) {
            Tools::log()->warning('auralis-calendario-modulo-no-borrable');
            return false;
        }

        if ($this->esPersonal() && (bool)$this->principal) {
            Tools::log()->warning('auralis-calendario-principal-no-borrable');
            return false;
        }

        $id = (int)($this->idcalendario ?? 0);
        $nombre = (string)$this->nombre;
        $eventos = $id > 0 ? AuralisCalendarEvent::count([Where::eq('idcalendario', $id)]) : 0;

        if (false === parent::delete()) {
            return false;
        }

        Tools::log('Auralis')->notice(Tools::trans('auralis-calendario-audit-borrado', [
            '%id%' => (string)$id,
            '%nombre%' => $nombre,
            '%tipo%' => (string)$this->tipo,
            '%eventos%' => (string)$eventos,
        ]));

        return true;
    }

    public function test(): bool
    {
        $this->nombre = Tools::noHtml(trim((string)$this->nombre));
        if (mb_strlen($this->nombre) > 100) {
            $this->nombre = mb_substr($this->nombre, 0, 100);
        }
        $this->descripcion = empty($this->descripcion) ? null : Tools::noHtml((string)$this->descripcion);
        $this->color = AuralisCalendarEvent::normalizarColorHex((string)$this->color, self::COLOR_DEFECTO);
        $this->tipo = mb_strtolower(trim((string)$this->tipo));
        $this->activo = (bool)$this->activo;
        $this->principal = (bool)$this->principal;
        $this->propietario = self::textoONull($this->propietario, 50);
        $this->idempresa = empty($this->idempresa) ? null : (int)$this->idempresa;

        $codigo = self::textoONull($this->codigo, 30);
        $this->codigo = $codigo === null ? null : mb_strtolower($codigo);

        if ($this->nombre === '') {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'nombre']);
            return false;
        }

        if (false === in_array($this->tipo, self::tiposPermitidos(), true)) {
            Tools::log()->warning('auralis-calendario-tipo-invalido');
            return false;
        }

        // El tipo no cambia una vez guardado; el de módulo tampoco cambia de código (lo enlaza origen_tipo).
        $tipoOriginal = $this->getOriginal('tipo');
        if ($tipoOriginal !== null && $this->tipo !== $tipoOriginal) {
            Tools::log()->warning('auralis-calendario-tipo-fijo');
            return false;
        }
        if ($tipoOriginal === self::TIPO_MODULO && $this->codigo !== $this->getOriginal('codigo')) {
            Tools::log()->warning('auralis-calendario-modulo-codigo-fijo');
            return false;
        }

        if ($this->codigo !== null && preg_match('/^[a-z0-9_-]{1,30}$/', $this->codigo) !== 1) {
            Tools::log()->warning('auralis-calendario-codigo-invalido');
            return false;
        }

        switch ($this->tipo) {
            case self::TIPO_MODULO:
                // Solo los módulos conocidos (CalendarOrigins::MODULOS) tienen fila de módulo.
                if ($this->codigo === null || false === isset(CalendarOrigins::MODULOS[$this->codigo])) {
                    Tools::log()->warning('auralis-calendario-modulo-desconocido');
                    return false;
                }
                $this->propietario = null;
                $this->principal = false;
                $this->idempresa = null;
                break;

            case self::TIPO_COMUN:
                $this->propietario = null;
                $this->principal = false;
                // El código no se pide al usuario: si viene vacío se genera a partir del nombre.
                if ($this->codigo === null) {
                    $this->codigo = $this->generarCodigoComun();
                }
                break;

            default:
                $this->codigo = null;
                break;
        }

        // Los códigos de módulo (origen_tipo) quedan reservados para su fila automática.
        if ($this->tipo !== self::TIPO_MODULO && $this->codigo !== null && isset(CalendarOrigins::MODULOS[$this->codigo])) {
            Tools::log()->warning('auralis-calendario-codigo-reservado', ['%codigo%' => $this->codigo]);
            return false;
        }

        if ($this->codigo !== null && $this->codigoDuplicado()) {
            Tools::log()->warning('auralis-calendario-codigo-duplicado', ['%codigo%' => $this->codigo]);
            return false;
        }

        if ($this->esPersonal() && $this->principal && $this->propietario !== null && $this->principalDuplicado()) {
            Tools::log()->warning('auralis-calendario-principal-duplicado');
            return false;
        }

        if (empty($this->creado)) {
            $this->creado = Tools::dateTime();
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        if ($type === 'list') {
            return 'ConfigAuralisServiciosCalendario?activetab=ListAuralisCalendario';
        }

        return parent::url($type, $list);
    }

    /**
     * Código único (^[a-z0-9_-]{1,30}$) a partir del nombre, sin pisar los códigos de módulo ni empresa_{id}.
     */
    private function generarCodigoComun(): string
    {
        $base = mb_substr(Tools::slug($this->nombre), 0, 24);
        $base = trim(preg_replace('/[^a-z0-9_-]/', '', mb_strtolower($base)) ?? '', '-_');
        if ($base === '' || isset(CalendarOrigins::MODULOS[$base]) || str_starts_with($base, CalendarioAcceso::PREFIJO_COMUN_EMPRESA)) {
            $base = 'calendario';
        }

        $candidato = $base;
        $i = 1;
        while (true) {
            $this->codigo = $candidato;
            if (false === isset(CalendarOrigins::MODULOS[$candidato]) && false === $this->codigoDuplicado()) {
                return $candidato;
            }
            $candidato = $base . '-' . $i;
            $i++;
        }
    }

    private function codigoDuplicado(): bool
    {
        $where = [Where::eq('codigo', $this->codigo)];
        if (!empty($this->idcalendario)) {
            $where[] = Where::notEq('idcalendario', (int)$this->idcalendario);
        }

        return static::count($where) > 0;
    }

    private function principalDuplicado(): bool
    {
        $where = [
            Where::eq('tipo', self::TIPO_PERSONAL),
            Where::eq('propietario', $this->propietario),
            Where::eq('principal', true),
        ];
        if (!empty($this->idcalendario)) {
            $where[] = Where::notEq('idcalendario', (int)$this->idcalendario);
        }

        return static::count($where) > 0;
    }

    /**
     * @param mixed $valor
     */
    private static function textoONull($valor, int $max): ?string
    {
        $texto = Tools::noHtml(trim((string)($valor ?? '')));
        if ($texto === '') {
            return null;
        }

        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max) : $texto;
    }
}

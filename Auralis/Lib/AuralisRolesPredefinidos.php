<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * @description Roles plantilla de Auralis (Agenda, Gestoría y Administración): se crean en update() solo si el codrole no existe y todas sus páginas están registradas; nunca se modifican ni se asignan a usuarios.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Page;
use FacturaScripts\Dinamic\Model\Role;
use FacturaScripts\Dinamic\Model\RoleAccess;
use FacturaScripts\Dinamic\Model\Settings;
use Throwable;

/**
 * Reglas duras:
 * - Si el codrole ya existe no se toca nada (ni descripción ni reglas, ni para completar páginas).
 * - Si falta alguna página del rol en `pages`, el rol no se crea en esta pasada (se creará entero en
 *   el siguiente update(), cuando el deploy ya la haya registrado). Nunca quedan plantillas a medias.
 * - Los cinco flags de cada RoleAccess se fijan explícitamente (no se usan los defaults de clear(),
 *   ni Role::addPage() ni RoleAccess::addPagesToRole()).
 * - Nunca se crean RoleUser: los roles quedan disponibles para que el administrador los asigne.
 * - Las concesiones generales de páginas a todos los roles (Init::update()) se saltan los roles
 *   plantilla: sus páginas y flags salen solo de definiciones() (fuente única: esta clase).
 * - Excepción única y acotada: limpiarPaginasExtraDePlantillas() quita, una sola vez, las reglas de
 *   PAGINAS_CONCESION_GENERAL que esa concesión dejó en plantillas que no las incluyen.
 */
final class AuralisRolesPredefinidos
{
    public const ROL_GESTORIA = 'auralis_gestoria';

    public const ROL_ADMIN = 'auralis_admin';

    /**
     * Páginas que Init::update() concede a todos los roles que no son plantilla
     * (Calendario personal, avisos in-app y PWA). Fuente única de esa lista.
     */
    public const PAGINAS_CONCESION_GENERAL = [];

    /** Grupo de ajustes del plugin (tabla settings). */
    public const GRUPO_AJUSTES = 'auralis';

    /**
     * Marca de la limpieza única de limpiarPaginasExtraDePlantillas(): hasta la 1.82 la concesión
     * general también llegaba a los roles plantilla y les dejó reglas que no son de su plantilla.
     */
    public const AJUSTE_LIMPIEZA_PAGINAS_EXTRA = 'roles_plantilla_sin_paginas_extra_1_82';

    private const CANAL_LOG = 'Auralis';

    /** Longitud de roles.descripcion (Core/Table/roles.xml). */
    private const LONGITUD_DESCRIPCION = 200;

    /** @var array<string, array{descripcion: string, paginas: array<string, array<string, bool>>}> */
    private array $definiciones;

    /**
     * @param array<string, array{descripcion: string, paginas: array<string, array<string, bool>>}>|null $definiciones
     *        null = las del producto (definiciones()). Los tests inyectan codroles propios con el mismo formato.
     */
    public function __construct(?array $definiciones = null)
    {
        $this->definiciones = $definiciones ?? self::definiciones();
    }

    /**
     * Roles plantilla: codrole => clave i18n de la descripción y páginas con sus flags.
     * La existencia de la fila en roles_access ya da el acceso («ver»).
     *
     * @return array<string, array{descripcion: string, paginas: array<string, array<string, bool>>}>
     */
    public static function definiciones(): array
    {
        $soloVer = self::flags();

        return [
            self::ROL_GESTORIA => [
                'descripcion' => 'auralis-role-gestoria',
                'paginas' => [
                    'DocumentosGestoriaAuralis' => self::flags(false, false, true),
                    'DashBoardAuralis' => $soloVer,
                ],
            ],
            self::ROL_ADMIN => [
                'descripcion' => 'auralis-role-admin',
                'paginas' => [
                    'ConfigAuralis' => self::flags(true),
                    'ConfigAjustesAuralis' => self::flags(true),
                ],
            ],
        ];
    }

    /**
     * Codroles de los roles plantilla del producto (fuente única: definiciones()).
     *
     * @return string[]
     */
    public static function codrolesPlantilla(): array
    {
        return array_map('strval', array_keys(self::definiciones()));
    }

    public static function esPlantilla(string $codrole): bool
    {
        return in_array($codrole, self::codrolesPlantilla(), true);
    }

    /**
     * Concede la página a todos los roles que no sean plantilla y aún no la tengan
     * (lo usa Init::update() para Calendario personal, avisos in-app y PWA).
     * - Sin la página en `pages` no hace nada.
     * - Si el rol ya tiene la regla no se toca (ni sus flags).
     * - Los roles plantilla se saltan: sus reglas son las de definiciones(), nunca las de esta concesión.
     * Las excepciones las captura y registra quien llama.
     */
    public static function concederPaginaARolesNoPlantilla(string $pagename): void
    {
        $pagina = new Page();
        if (false === $pagina->load($pagename)) {
            return;
        }

        foreach (Role::all([], ['codrole' => 'ASC']) as $rol) {
            if (self::esPlantilla((string)$rol->codrole)) {
                continue;
            }

            $acceso = new RoleAccess();
            $filtros = [
                Where::eq('codrole', $rol->codrole),
                Where::eq('pagename', $pagename),
            ];
            if ($acceso->loadWhere($filtros)) {
                continue;
            }

            $acceso->codrole = $rol->codrole;
            $acceso->pagename = $pagename;
            $acceso->allowdelete = true;
            $acceso->allowupdate = true;
            $acceso->onlyownerdata = false;
            $acceso->save();
        }
    }

    /**
     * Crea los roles plantilla que falten (punto de entrada desde Init::update()).
     */
    public function ensure(): void
    {
        $this->crearQueFalten();
    }

    /**
     * Igual que ensure(), pero devuelve los codroles creados en esta pasada.
     *
     * @return string[]
     */
    public function crearQueFalten(): array
    {
        $creados = [];
        foreach ($this->definiciones as $codrole => $definicion) {
            if ($this->crearRolSiFalta((string)$codrole, $definicion)) {
                $creados[] = (string)$codrole;
            }
        }

        return $creados;
    }

    /**
     * Limpieza única (Init::update()): borra de cada rol de las definiciones las reglas de
     * PAGINAS_CONCESION_GENERAL que no figuran en su plantilla (con las del producto: Gestoría y
     * Administración; Agenda las trae todas y no se toca).
     * - Con la marca AJUSTE_LIMPIEZA_PAGINAS_EXTRA ya puesta no hace nada (ni consultas de borrado).
     * - Nunca borra otras páginas, ni toca roles fuera de las definiciones, ni RoleUser, ni los flags
     *   o la descripción de lo que queda.
     * - Si todos los borrados van bien (o no hay nada que borrar: roles aún no creados) guarda la marca;
     *   si alguno falla no la guarda (se reintenta en el próximo update()) y lo registra.
     *
     * @return int reglas borradas en esta pasada
     */
    public function limpiarPaginasExtraDePlantillas(): int
    {
        if (self::limpiezaPaginasExtraHecha()) {
            return 0;
        }

        $borradas = 0;
        $fallos = [];
        foreach ($this->definiciones as $codrole => $definicion) {
            $extra = array_values(array_diff(self::PAGINAS_CONCESION_GENERAL, array_keys($definicion['paginas'])));
            if (empty($extra)) {
                continue;
            }

            $filtros = [
                Where::eq('codrole', (string)$codrole),
                Where::in('pagename', $extra),
            ];
            foreach (RoleAccess::all($filtros, [], 0, 0) as $acceso) {
                if ($acceso->delete()) {
                    $borradas++;
                    continue;
                }
                $fallos[] = $codrole . '/' . $acceso->pagename;
            }
        }

        if ($borradas > 0) {
            // delete() ya la limpia; se repite para dejar sin restos los permisos cacheados (model-RoleAccess-*).
            (new RoleAccess())->clearCache();
        }

        if (!empty($fallos)) {
            Tools::log(self::CANAL_LOG)->warning(
                'Auralis: no se pudieron quitar de los roles plantilla las reglas ' . implode(', ', $fallos)
                . '; se reintentará en la próxima actualización.'
            );
            return $borradas;
        }

        self::marcarLimpiezaPaginasExtraHecha();
        return $borradas;
    }

    private static function limpiezaPaginasExtraHecha(): bool
    {
        return filter_var(
            Tools::settings(self::GRUPO_AJUSTES, self::AJUSTE_LIMPIEZA_PAGINAS_EXTRA),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Guarda la marca solo en el grupo auralis (no Tools::settingsSave(), que escribiría todos los
     * grupos en memoria, incluidos los defaults que init() solo pone en memoria).
     */
    private static function marcarLimpiezaPaginasExtraHecha(): void
    {
        $ajustes = new Settings();
        if (false === $ajustes->load(self::GRUPO_AJUSTES)) {
            $ajustes->name = self::GRUPO_AJUSTES;
        }

        $ajustes->setProperty(self::AJUSTE_LIMPIEZA_PAGINAS_EXTRA, '1');
        if (false === $ajustes->save()) {
            Tools::log(self::CANAL_LOG)->warning('Auralis: no se pudo guardar la marca de limpieza de los roles plantilla.');
            return;
        }

        Tools::settingsSet(self::GRUPO_AJUSTES, self::AJUSTE_LIMPIEZA_PAGINAS_EXTRA, true);
    }

    /**
     * @param array{descripcion: string, paginas: array<string, array<string, bool>>} $definicion
     */
    private function crearRolSiFalta(string $codrole, array $definicion): bool
    {
        $rol = new Role();
        if ($rol->load($codrole)) {
            return false;
        }

        foreach (array_keys($definicion['paginas']) as $pagename) {
            $pagina = new Page();
            if (false === $pagina->load($pagename)) {
                return false;
            }
        }

        // Rol + reglas en bloque: si una inserción falla no queda un rol a medias. Si ya hay una
        // transacción abierta por quien llama no se confirma la suya: se borra el rol recién creado
        // (roles_access cae en cascada por la FK de roles_access.codrole).
        $db = new DataBase();
        $transaccionPropia = false === $db->inTransaction();
        if ($transaccionPropia) {
            $db->beginTransaction();
        }

        $error = '';
        try {
            $ok = $this->insertarRolYReglas($rol, $codrole, $definicion, $error);
        } catch (Throwable $excepcion) {
            $ok = false;
            $error = $excepcion->getMessage();
        }

        if ($ok) {
            if ($transaccionPropia) {
                $db->commit();
            }
            return true;
        }

        if ($transaccionPropia) {
            $db->rollback();
        } else {
            $this->borrarRolAMedias($codrole);
        }
        // Tras el rollback la caché de modelos podría recordar filas que ya no existen.
        (new Role())->clearCache();

        Tools::log(self::CANAL_LOG)->warning('Auralis: no se pudo crear el rol predefinido ' . $codrole . ': ' . $error);
        return false;
    }

    /**
     * @param array{descripcion: string, paginas: array<string, array<string, bool>>} $definicion
     */
    private function insertarRolYReglas(Role $rol, string $codrole, array $definicion, string &$error): bool
    {
        $rol->codrole = $codrole;
        $rol->descripcion = mb_substr(Tools::trans($definicion['descripcion']), 0, self::LONGITUD_DESCRIPCION);
        if (false === $rol->save()) {
            $error = 'roles';
            return false;
        }

        foreach ($definicion['paginas'] as $pagename => $flags) {
            $acceso = new RoleAccess();
            $acceso->codrole = $codrole;
            $acceso->pagename = (string)$pagename;
            // Los cinco flags explícitos: nunca los defaults de RoleAccess::clear() (todo true).
            $acceso->allowupdate = (bool)($flags['allowupdate'] ?? false);
            $acceso->allowdelete = (bool)($flags['allowdelete'] ?? false);
            $acceso->allowexport = (bool)($flags['allowexport'] ?? false);
            $acceso->allowimport = (bool)($flags['allowimport'] ?? false);
            $acceso->onlyownerdata = (bool)($flags['onlyownerdata'] ?? false);
            if (false === $acceso->save()) {
                $error = 'roles_access ' . $pagename;
                return false;
            }
        }

        return true;
    }

    private function borrarRolAMedias(string $codrole): void
    {
        try {
            $rol = new Role();
            if ($rol->load($codrole)) {
                $rol->delete();
            }
        } catch (Throwable $excepcion) {
            Tools::log(self::CANAL_LOG)->warning('Auralis: no se pudo deshacer el rol predefinido ' . $codrole . ': ' . $excepcion->getMessage());
        }
    }

    /**
     * Los cinco flags de RoleAccess, explícitos (false salvo lo indicado).
     *
     * @return array<string, bool>
     */
    private static function flags(
        bool $allowupdate = false,
        bool $allowdelete = false,
        bool $allowexport = false,
        bool $allowimport = false,
        bool $onlyownerdata = false
    ): array {
        return [
            'allowupdate' => $allowupdate,
            'allowdelete' => $allowdelete,
            'allowexport' => $allowexport,
            'allowimport' => $allowimport,
            'onlyownerdata' => $onlyownerdata,
        ];
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Contrato enganchable de los proveedores de datos del visor de árbol AuralisArbol.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

interface AuralisArbolProviderInterface
{
    public const MODO_EXPLOSION = 'explosion';
    public const MODO_IMPLOSION = 'implosion';

    /**
     * Clave única del proveedor (minúsculas, dígitos, guion o guion bajo), p. ej. 'zmrp-bom'.
     */
    public function clave(): string;

    /**
     * Controlador sobre el que se comprueba el acceso del usuario, p. ej. 'EditZmrpBom'.
     */
    public function paginaPermiso(): string;

    /**
     * Devuelve los nodos del árbol: el primero con padre null es la raíz.
     * En la implosión cada aparición es un nodo propio (ids únicos), así que sigue siendo un árbol.
     *
     * @param string $idRaiz clave del elemento raíz en el dominio del proveedor
     * @param string $modo self::MODO_EXPLOSION | self::MODO_IMPLOSION
     * @param int $profundidad niveles bajo la raíz (0 = todos)
     * @param array<string, mixed> $opciones contexto de la petición: nick, idempresa, maxNodos
     *
     * @return array<int, array<string, mixed>> nodos con el contrato del README (id, padre, etiqueta…)
     */
    public function cargar(string $idRaiz, string $modo, int $profundidad, array $opciones): array;
}

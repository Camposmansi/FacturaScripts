<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Parámetros validados y tipados de una petición GET al endpoint ArbolAuralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use InvalidArgumentException;

final class AuralisArbolPeticion
{
    public const ID_MAX_LONGITUD = 100;
    public const PROFUNDIDAD_MAX = 50;
    public const ERROR_PARAMETROS = 'auralis-arbol-parametros-invalidos';

    private function __construct(
        public readonly string $proveedor,
        public readonly string $id,
        public readonly string $modo,
        public readonly int $profundidad
    ) {
    }

    /**
     * Valida y castea los parámetros crudos de la query.
     * Lanza InvalidArgumentException con la clave i18n ERROR_PARAMETROS si alguno no es válido.
     */
    public static function crear(?string $proveedor, ?string $id, ?string $modo, ?string $profundidad): self
    {
        $proveedor = trim((string)$proveedor);
        if (1 !== preg_match(AuralisArbolRegistry::PATRON_CLAVE, $proveedor)) {
            throw new InvalidArgumentException(self::ERROR_PARAMETROS);
        }

        $id = trim((string)$id);
        if ($id === '' || false === mb_check_encoding($id, 'UTF-8')
            || mb_strlen($id) > self::ID_MAX_LONGITUD || 1 === preg_match('/[\x00-\x1F\x7F]/', $id)) {
            throw new InvalidArgumentException(self::ERROR_PARAMETROS);
        }

        $modo = trim((string)$modo);
        if ($modo === '') {
            $modo = AuralisArbolProviderInterface::MODO_EXPLOSION;
        }
        if (!in_array($modo, [AuralisArbolProviderInterface::MODO_EXPLOSION, AuralisArbolProviderInterface::MODO_IMPLOSION], true)) {
            throw new InvalidArgumentException(self::ERROR_PARAMETROS);
        }

        $profundidad = trim((string)$profundidad);
        if ($profundidad === '') {
            $profundidad = '0';
        }
        if (1 !== preg_match('/^\d{1,3}$/', $profundidad) || (int)$profundidad > self::PROFUNDIDAD_MAX) {
            throw new InvalidArgumentException(self::ERROR_PARAMETROS);
        }

        return new self($proveedor, $id, $modo, (int)$profundidad);
    }
}

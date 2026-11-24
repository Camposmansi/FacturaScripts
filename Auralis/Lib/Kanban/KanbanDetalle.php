<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Carcasa del detalle Kanban: modo efectivo (página o modal), saneado del detalle del proveedor, lista permitida al guardar y assets seguros.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;

/**
 * Estática y sin BD. El servidor recalcula siempre el detalle con detalle() antes de
 * filtrar la entrada: los nombres de campo que mande el cliente nunca se usan por sí solos.
 */
final class KanbanDetalle
{
    /** Tipos de campo admitidos por la carcasa. */
    public const TIPOS = ['texto', 'textarea', 'numero', 'fecha', 'select', 'casilla', 'solo-lectura'];

    /** Anchos admitidos (rejilla de 12). */
    public const ANCHOS = [4, 6, 12];

    /** Tamaños de modal admitidos. */
    public const TAMANOS = ['lg', 'xl'];

    /** Máximo de campos por detalle. */
    public const MAX_CAMPOS = 30;

    private const MAX_TITULO = 200;
    private const MAX_ETIQUETA = 100;
    /** Longitud por defecto de un campo `texto` y de un `textarea` (si el proveedor no declara maxlength). */
    private const MAX_TEXTO = 255;
    private const MAX_TEXTAREA = 5000;
    /** Tope de maxlength que puede declarar un proveedor. */
    private const MAX_LONGITUD = 16000;
    /** Longitud máxima del valor mostrado: un valor más largo deja el campo no editable (no se recorta al guardar). */
    private const MAX_VALOR = 16000;
    private const MAX_OPCION = 200;

    private const PATRON_NOMBRE = '/^[a-z][a-z0-9_]{0,49}\z/';
    private const PATRON_URL = '/^[A-Za-z][A-Za-z0-9_]*(\?[A-Za-z0-9_=&%.\-]*)?\z/';
    private const PATRON_PLANTILLA = '/^@?[A-Za-z0-9_\-\/]+\.html\.twig\z/';
    private const PATRON_ASSET = '/^Dinamic\/Assets\/(JS|CSS)\/[A-Za-z0-9_\-\/]+\.(js|css)\z/';

    /**
     * Modo efectivo del detalle: 'page' si el proveedor no implementa la capacidad;
     * si no, el pedido ('page' | 'modal') o, en su defecto, el del proveedor.
     */
    public static function modo(KanbanProviderInterface $provider, ?string $pedido): string
    {
        if (false === $provider instanceof KanbanDetalleInterface) {
            return KanbanDetalleInterface::DETALLE_PAGINA;
        }

        $pedido = self::modoValido($pedido);
        if ($pedido !== null) {
            return $pedido;
        }

        return self::modoValido($provider->modoDetallePorDefecto()) ?? KanbanDetalleInterface::DETALLE_PAGINA;
    }

    /** 'page' | 'modal' si el texto es uno de ellos (sin distinguir mayúsculas); si no, null. */
    public static function modoValido(?string $modo): ?string
    {
        $modo = mb_strtolower(trim((string) $modo));
        $validos = [KanbanDetalleInterface::DETALLE_PAGINA, KanbanDetalleInterface::DETALLE_MODAL];

        return in_array($modo, $validos, true) ? $modo : null;
    }

    /**
     * Sanea el detalle que devuelve el proveedor. Null si falta el título.
     *
     * @param array<string, mixed>|null $detalle
     * @return array{titulo: string, subtitulo: ?string, url: ?string, tamano: string,
     *   campos: array<int, array{nombre: string, etiqueta: string, tipo: string, valor: string|bool,
     *   opciones: array<string, string>, editable: bool, requerido: bool, ancho: int, maxlength: ?int}>,
     *   plantilla: ?string, datos: array}|null
     */
    public static function normalizar(?array $detalle): ?array
    {
        if ($detalle === null) {
            return null;
        }

        $titulo = self::textoPlano($detalle['titulo'] ?? null, self::MAX_TITULO);
        if ($titulo === '') {
            return null;
        }

        $subtitulo = self::textoPlano($detalle['subtitulo'] ?? null, self::MAX_TITULO);
        $tamano = is_string($detalle['tamano'] ?? null) ? mb_strtolower(trim($detalle['tamano'])) : '';

        return [
            'titulo' => $titulo,
            'subtitulo' => $subtitulo === '' ? null : $subtitulo,
            'url' => self::urlSegura($detalle['url'] ?? null),
            'tamano' => in_array($tamano, self::TAMANOS, true) ? $tamano : 'lg',
            'campos' => self::normalizarCampos($detalle['campos'] ?? []),
            'plantilla' => self::plantillaSegura($detalle['plantilla'] ?? null),
            'datos' => is_array($detalle['datos'] ?? null) ? $detalle['datos'] : [],
        ];
    }

    /** Hay al menos un campo editable en el detalle normalizado. */
    public static function hayEditables(array $normalizado): bool
    {
        foreach ($normalizado['campos'] ?? [] as $campo) {
            if (false === empty($campo['editable'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lista permitida al guardar: solo los campos editables del detalle normalizado,
     * leídos uno a uno de la entrada y convertidos según su tipo.
     *
     * @param array<string, mixed> $normalizado salida de normalizar(), recalculada en servidor
     * @param array<string, mixed> $post campos del formulario (en el AJAX, el grupo campos[nombre] del POST)
     * @return array{0: array<string, mixed>, 1: array<string, string>} [valores, errores por nombre]
     */
    public static function filtrarEntrada(array $normalizado, array $post): array
    {
        $valores = [];
        $errores = [];

        foreach ($normalizado['campos'] ?? [] as $campo) {
            if (empty($campo['editable'])) {
                continue;
            }

            $nombre = (string) $campo['nombre'];
            $etiqueta = (string) ($campo['etiqueta'] ?? '') === '' ? $nombre : (string) $campo['etiqueta'];

            if ($campo['tipo'] === 'casilla') {
                $marcada = array_key_exists($nombre, $post) && is_scalar($post[$nombre])
                    && filter_var($post[$nombre], FILTER_VALIDATE_BOOLEAN);
                if (false === $marcada && false === empty($campo['requerido'])) {
                    $errores[$nombre] = self::errorRequerido($etiqueta);
                    continue;
                }

                $valores[$nombre] = $marcada;
                continue;
            }

            // campo editable ausente: no se toca
            if (false === array_key_exists($nombre, $post)) {
                continue;
            }

            if (false === is_scalar($post[$nombre]) && $post[$nombre] !== null) {
                $errores[$nombre] = self::errorNoValido($etiqueta);
                continue;
            }

            $bruto = trim((string) $post[$nombre]);
            if ($bruto === '' && false === empty($campo['requerido'])) {
                $errores[$nombre] = self::errorRequerido($etiqueta);
                continue;
            }

            // texto y textarea: nunca se recorta en silencio, se avisa
            if (in_array($campo['tipo'], ['texto', 'textarea'], true)) {
                $maximo = self::longitudMaxima($campo);
                if (mb_strlen($bruto) > $maximo) {
                    $errores[$nombre] = self::errorLargo($etiqueta, $maximo);
                    continue;
                }
            }

            [$valor, $valido] = self::convertir($campo, $bruto);
            if (false === $valido) {
                $errores[$nombre] = self::errorNoValido($etiqueta);
                continue;
            }

            $valores[$nombre] = $valor;
        }

        return [$valores, $errores];
    }

    /**
     * Assets del detalle del proveedor que pasan la ruta permitida (sin '..', JS en JS/ y CSS en CSS/).
     *
     * @return array{js: string[], css: string[]}
     */
    public static function assets(KanbanProviderInterface $provider): array
    {
        $resultado = ['js' => [], 'css' => []];
        if (false === $provider instanceof KanbanDetalleInterface) {
            return $resultado;
        }

        $declarados = $provider->detalleAssets();
        foreach (['js' => 'JS', 'css' => 'CSS'] as $tipo => $carpeta) {
            $rutas = $declarados[$tipo] ?? [];
            if (false === is_array($rutas)) {
                continue;
            }

            foreach ($rutas as $ruta) {
                if (false === is_string($ruta)) {
                    continue;
                }

                $ruta = trim($ruta);
                if ($ruta === '' || str_contains($ruta, '..')) {
                    continue;
                }

                if (preg_match(self::PATRON_ASSET, $ruta, $partes) !== 1 || $partes[1] !== $carpeta || $partes[2] !== $tipo) {
                    continue;
                }

                if (false === in_array($ruta, $resultado[$tipo], true)) {
                    $resultado[$tipo][] = $ruta;
                }
            }
        }

        return $resultado;
    }

    /**
     * @param mixed $campos
     * @return array<int, array<string, mixed>>
     */
    private static function normalizarCampos($campos): array
    {
        if (false === is_array($campos)) {
            return [];
        }

        $resultado = [];
        $vistos = [];
        foreach ($campos as $campo) {
            if (count($resultado) >= self::MAX_CAMPOS) {
                break;
            }

            $normalizado = is_array($campo) ? self::normalizarCampo($campo) : null;
            if ($normalizado === null || isset($vistos[$normalizado['nombre']])) {
                continue;
            }

            $vistos[$normalizado['nombre']] = true;
            $resultado[] = $normalizado;
        }

        return $resultado;
    }

    /**
     * @param array<string, mixed> $campo
     * @return array<string, mixed>|null
     */
    private static function normalizarCampo(array $campo): ?array
    {
        $nombre = $campo['nombre'] ?? null;
        if (false === is_string($nombre) || preg_match(self::PATRON_NOMBRE, $nombre) !== 1) {
            return null;
        }

        $tipo = $campo['tipo'] ?? null;
        if (false === is_string($tipo) || false === in_array($tipo, self::TIPOS, true)) {
            return null;
        }

        $valor = $tipo === 'casilla'
            ? self::booleano($campo['valor'] ?? false)
            : self::textoPlano($campo['valor'] ?? null, self::MAX_VALOR, false);

        // un valor más largo que lo que se muestra se recortaría al reenviarlo: el campo no se puede editar
        $demasiadoLargo = $tipo !== 'casilla' && is_scalar($campo['valor'] ?? null)
            && mb_strlen((string) $campo['valor']) > self::MAX_VALOR;

        $ancho = is_numeric($campo['ancho'] ?? null) ? (int) $campo['ancho'] : 12;

        return [
            'nombre' => $nombre,
            'etiqueta' => self::textoPlano($campo['etiqueta'] ?? null, self::MAX_ETIQUETA),
            'tipo' => $tipo,
            'valor' => $valor,
            'opciones' => $tipo === 'select' ? self::opciones($campo['opciones'] ?? []) : [],
            'editable' => $tipo !== 'solo-lectura' && false === $demasiadoLargo && self::booleano($campo['editable'] ?? false),
            'requerido' => self::booleano($campo['requerido'] ?? false),
            'ancho' => in_array($ancho, self::ANCHOS, true) ? $ancho : 12,
            'maxlength' => in_array($tipo, ['texto', 'textarea'], true) ? self::longitudMaxima($campo + ['tipo' => $tipo]) : null,
        ];
    }

    /**
     * @param mixed $opciones
     * @return array<string, string> clave => etiqueta
     */
    private static function opciones($opciones): array
    {
        if (false === is_array($opciones)) {
            return [];
        }

        $resultado = [];
        foreach ($opciones as $clave => $etiqueta) {
            if (false === is_scalar($etiqueta)) {
                continue;
            }

            $resultado[mb_substr((string) $clave, 0, self::MAX_OPCION)] = self::textoPlano($etiqueta, self::MAX_OPCION);
        }

        return $resultado;
    }

    /**
     * Valor de un campo no casilla según su tipo. $bruto ya viene sin espacios en los extremos
     * y, en texto y textarea, ya se comprobó su longitud.
     *
     * @param array<string, mixed> $campo
     * @return array{0: mixed, 1: bool} [valor, válido]
     */
    private static function convertir(array $campo, string $bruto): array
    {
        switch ($campo['tipo']) {
            case 'texto':
                return [$bruto, true];

            case 'textarea':
                return [$bruto, true];

            case 'numero':
                if ($bruto === '') {
                    return [null, true];
                }
                return is_numeric($bruto) ? [(float) $bruto, true] : [null, false];

            case 'fecha':
                if ($bruto === '') {
                    return [null, true];
                }
                $fecha = self::fechaIso($bruto);
                return $fecha === null ? [null, false] : [$fecha, true];

            case 'select':
                if ($bruto === '') {
                    return [null, true];
                }
                return array_key_exists($bruto, $campo['opciones'] ?? []) ? [$bruto, true] : [null, false];
        }

        return [null, false];
    }

    /**
     * Longitud máxima de un campo texto o textarea: el maxlength declarado (entero de 1 a 16000)
     * o, si falta o no es válido, el de por defecto (255 en texto, 5000 en textarea).
     *
     * @param array<string, mixed> $campo
     */
    private static function longitudMaxima(array $campo): int
    {
        $defecto = ($campo['tipo'] ?? '') === 'textarea' ? self::MAX_TEXTAREA : self::MAX_TEXTO;
        $declarado = $campo['maxlength'] ?? null;

        if (is_int($declarado) && $declarado >= 1 && $declarado <= self::MAX_LONGITUD) {
            return $declarado;
        }

        return $defecto;
    }

    /** Fecha Y-m-d (o d-m-Y convertida) válida; si no, null. */
    private static function fechaIso(string $valor): ?string
    {
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})\z/', $valor, $partes) === 1) {
            $valor = $partes[3] . '-' . $partes[2] . '-' . $partes[1];
        }

        return AuralisSecurity::sanitizeIsoDate($valor);
    }

    /** Ruta relativa interna (Controlador?query) o null. */
    private static function urlSegura($url): ?string
    {
        if (false === is_string($url)) {
            return null;
        }

        $url = trim($url);

        return $url !== '' && preg_match(self::PATRON_URL, $url) === 1 ? $url : null;
    }

    /** Plantilla Twig del consumidor (sin '..') o null. */
    private static function plantillaSegura($plantilla): ?string
    {
        if (false === is_string($plantilla)) {
            return null;
        }

        $plantilla = trim($plantilla);
        if ($plantilla === '' || str_contains($plantilla, '..')) {
            return null;
        }

        return preg_match(self::PATRON_PLANTILLA, $plantilla) === 1 ? $plantilla : null;
    }

    /** Texto plano recortado (solo escalares; el resto, vacío). */
    private static function textoPlano($valor, int $maximo, bool $recortar = true): string
    {
        if (false === is_scalar($valor)) {
            return '';
        }

        if (is_bool($valor)) {
            $valor = $valor ? '1' : '0';
        }

        $texto = $recortar ? trim((string) $valor) : (string) $valor;

        return mb_substr($texto, 0, $maximo);
    }

    private static function booleano($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return is_scalar($valor) && filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    private static function errorRequerido(string $etiqueta): string
    {
        return Tools::trans('auralis-kanban-campo-requerido', ['%campo%' => $etiqueta]);
    }

    private static function errorLargo(string $etiqueta, int $maximo): string
    {
        return Tools::trans('auralis-kanban-campo-largo', ['%campo%' => $etiqueta, '%max%' => (string) $maximo]);
    }

    private static function errorNoValido(string $etiqueta): string
    {
        return Tools::trans('auralis-kanban-campo-no-valido', ['%campo%' => $etiqueta]);
    }
}

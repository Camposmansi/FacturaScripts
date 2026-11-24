<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Nombre del PDF de un documento de compra o venta a partir de un patrón con variables: lee el grupo de ajustes auralis_pdf_nombre y sanea el resultado.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaNombres;
use Normalizer;
use Throwable;

final class PdfNombreArchivo
{
    public const GRUPO = 'auralis_pdf_nombre';

    /** Longitud máxima por defecto del nombre (sin extensión). */
    public const MAX_LONGITUD = 120;

    public const LONGITUD_MIN = 40;

    public const LONGITUD_MAX = 200;

    /**
     * Tope en bytes: el adjunto de email se guarda como «nombre_mail_<hora>_<10>.pdf» (unos 31 bytes más)
     * y ext4 no admite nombres de más de 255 bytes; con letras multibyte 200 caracteres pasarían de ahí.
     */
    public const MAX_BYTES = 200;

    /** Claves booleanas del grupo (checkboxes del XMLView). */
    public const CLAVES_BOOLEANAS = ['activo', 'previsualizar_adjuntos_email'];

    public const PATRON_VENTAS_DEFECTO = '{codigo}_{cliente}';

    public const PATRON_COMPRAS_DEFECTO = '{codigo}_{proveedor}';

    public const MODELOS_VENTAS = ['PresupuestoCliente', 'PedidoCliente', 'AlbaranCliente', 'FacturaCliente'];

    public const MODELOS_COMPRAS = ['PresupuestoProveedor', 'PedidoProveedor', 'AlbaranProveedor', 'FacturaProveedor'];

    /** Variables que se pueden usar en los patrones, entre llaves: {codigo}. */
    public const VARIABLES = [
        'tipo', 'codigo', 'numero', 'numero2', 'serie', 'cliente', 'proveedor',
        'tercero', 'cif', 'fecha', 'fecha_ymd', 'empresa',
    ];

    /** Prefijo del nombre del modelo => clave de traducción del tipo de documento. */
    private const TIPOS = [
        'Presupuesto' => 'estimation',
        'Pedido' => 'order',
        'Albaran' => 'delivery-note',
        'Factura' => 'invoice',
    ];

    /**
     * @return array<string, bool|string>
     */
    public static function valoresPorDefecto(): array
    {
        return [
            'activo' => false,
            'previsualizar_adjuntos_email' => false,
            'patron_ventas' => self::PATRON_VENTAS_DEFECTO,
            'patron_compras' => self::PATRON_COMPRAS_DEFECTO,
            'max_longitud' => (string)self::MAX_LONGITUD,
        ];
    }

    /**
     * Registra en memoria los valores por defecto que falten (Tools::settingsSet no persiste: sin consultas).
     */
    public static function asegurarValoresPorDefecto(): void
    {
        foreach (self::valoresPorDefecto() as $clave => $valor) {
            if (null === Tools::settings(self::GRUPO, $clave)) {
                Tools::settingsSet(self::GRUPO, $clave, $valor);
            }
        }
    }

    public static function estaActivo(): bool
    {
        return filter_var(Tools::settings(self::GRUPO, 'activo', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Longitud máxima del nombre, acotada entre LONGITUD_MIN y LONGITUD_MAX (no numérico: MAX_LONGITUD).
     */
    public static function maxLongitud(): int
    {
        $valor = Tools::settings(self::GRUPO, 'max_longitud', self::MAX_LONGITUD);
        if (false === is_numeric($valor)) {
            return self::MAX_LONGITUD;
        }

        return max(self::LONGITUD_MIN, min(self::LONGITUD_MAX, (int)$valor));
    }

    /**
     * 'ventas', 'compras' o '' según el modelo (nombre corto o FQCN).
     */
    public static function lado(string $modelClass): string
    {
        $corto = self::nombreCorto($modelClass);
        if (in_array($corto, self::MODELOS_VENTAS, true)) {
            return 'ventas';
        }

        return in_array($corto, self::MODELOS_COMPRAS, true) ? 'compras' : '';
    }

    /**
     * Patrón configurado para el lado del modelo; '' si el lado no existe o el patrón está vacío.
     */
    public static function patronPara(string $modelClass): string
    {
        $lado = self::lado($modelClass);
        if ('' === $lado) {
            return '';
        }

        $porDefecto = 'ventas' === $lado ? self::PATRON_VENTAS_DEFECTO : self::PATRON_COMPRAS_DEFECTO;

        return trim((string)Tools::settings(self::GRUPO, 'patron_' . $lado, $porDefecto));
    }

    /**
     * Valores de todas las variables para un documento (siempre texto, sin llaves en las claves).
     *
     * @return array<string, string>
     */
    public static function variables(object $model, bool $conEmpresa = true): array
    {
        $clase = self::nombreModelo($model);
        $lado = self::lado($clase);
        $fecha = self::texto($model, 'fecha');

        $tipo = '';
        foreach (self::TIPOS as $prefijo => $clave) {
            if (str_starts_with(self::nombreCorto($clase), $prefijo)) {
                $tipo = Tools::trans($clave);
                break;
            }
        }

        $numero2 = '';
        $cliente = '';
        $proveedor = '';
        if ('ventas' === $lado) {
            $numero2 = self::texto($model, 'numero2');
            $cliente = self::texto($model, 'nombrecliente');
        } elseif ('compras' === $lado) {
            $numero2 = self::texto($model, 'numproveedor');
            $proveedor = self::texto($model, 'nombre');
        }

        return [
            'tipo' => $tipo,
            'codigo' => self::texto($model, 'codigo'),
            'numero' => self::texto($model, 'numero'),
            'numero2' => $numero2,
            'serie' => self::texto($model, 'codserie'),
            'cliente' => $cliente,
            'proveedor' => $proveedor,
            'tercero' => '' !== $cliente ? $cliente : $proveedor,
            'cif' => self::texto($model, 'cifnif'),
            'fecha' => $fecha,
            'fecha_ymd' => self::fechaYmd($fecha),
            'empresa' => $conEmpresa ? self::nombreEmpresa($model) : '',
        ];
    }

    /**
     * Catálogo de variables para la ayuda de la pantalla de ajustes: una fila por cada entrada de VARIABLES,
     * con su descripción (clave «auralis-pdf-nombre-var-<nombre>») y un ejemplo calculado con un documento ficticio.
     *
     * @return array<int, array{nombre: string, descripcion: string, ejemplo: string}>
     */
    public static function catalogoVariables(): array
    {
        $ejemplos = self::ejemplosVariables();
        $catalogo = [];
        foreach (self::VARIABLES as $nombre) {
            $catalogo[] = [
                'nombre' => $nombre,
                'descripcion' => Tools::trans('auralis-pdf-nombre-var-' . $nombre),
                'ejemplo' => $ejemplos[$nombre] ?? '',
            ];
        }

        return $catalogo;
    }

    /**
     * Valores de ejemplo de cada variable: salen de variables() con una factura de cliente ficticia
     * (y una de proveedor para {proveedor}), así que siguen a la lógica real. No consulta la base de datos.
     *
     * @return array<string, string>
     */
    public static function ejemplosVariables(): array
    {
        $venta = new class {
            public $codigo = 'FAC2026A42';
            public $numero = '42';
            public $numero2 = 'PED-18';
            public $codserie = 'A';
            public $nombrecliente = 'Ana García Ruiz';
            public $cifnif = 'B12345678';
            public $fecha = '28-10-2026';

            public function modelClassName(): string
            {
                return 'FacturaCliente';
            }
        };
        $compra = new class {
            public $nombre = 'Suministros Norte SL';

            public function modelClassName(): string
            {
                return 'FacturaProveedor';
            }
        };

        $ejemplos = self::variables($venta, false);
        $ejemplos['proveedor'] = self::variables($compra, false)['proveedor'];
        $ejemplos['empresa'] = Tools::trans('auralis-pdf-nombre-ejemplo-empresa');

        return $ejemplos;
    }

    /**
     * Sustituye {clave} por su valor (sin distinguir mayúsculas ni espacios dentro de las llaves);
     * cualquier {otra} desconocida, como {código}, se elimina. No sanea.
     *
     * @param array<string, string> $variables
     */
    public static function aplicarPatron(string $patron, array $variables): string
    {
        return (string)preg_replace_callback(
            '/\{([^{}]*)\}/u',
            static function (array $coincidencia) use ($variables): string {
                $clave = mb_strtolower(trim($coincidencia[1]));
                if (false === in_array($clave, self::VARIABLES, true)) {
                    return '';
                }

                return (string)($variables[$clave] ?? '');
            },
            $patron
        );
    }

    /**
     * Deja un nombre de fichero seguro: sin controles, separadores de ruta, rachas de «.», «_» o «-»
     * ni extremos con esos caracteres, y con la longitud acotada.
     */
    public static function sanear(string $nombre, int $maxLongitud = self::MAX_LONGITUD): string
    {
        // UTF-8 inválido: no hay forma fiable de limpiarlo
        if (1 !== preg_match('//u', $nombre)) {
            return '';
        }

        // tildes descompuestas (NFD) a su forma compuesta, para que no se pierdan como «_»
        if (class_exists(Normalizer::class)) {
            $nombre = (string)(Normalizer::normalize($nombre, Normalizer::FORM_C) ?: $nombre);
        }

        // controles (CR, LF, TAB, NUL...) a espacio, para que acaben como separador
        $nombre = (string)preg_replace('/\p{C}+/u', ' ', $nombre);
        $nombre = GestoriaNombres::sanear($nombre);

        // defensa: ningún carácter de ruta o reservado en sistemas de ficheros
        $nombre = (string)preg_replace('/[\/\\\\:*?"<>|]+/u', '', $nombre);

        // nunca «..» ni «__»: la racha se queda con su primer separador
        $nombre = (string)preg_replace('/([._-])[._-]+/u', '$1', $nombre);

        $nombre = trim($nombre, '._-');
        $nombre = mb_substr($nombre, 0, max(1, $maxLongitud));
        if (strlen($nombre) > self::MAX_BYTES) {
            $nombre = mb_strcut($nombre, 0, self::MAX_BYTES, 'UTF-8');
        }

        return trim($nombre, '._-');
    }

    /**
     * Nombre del PDF (sin «.pdf») para el documento; null = dejar el nombre del Core.
     */
    public static function construir(object $model): ?string
    {
        try {
            if (false === self::estaActivo()) {
                return null;
            }

            $clase = self::nombreModelo($model);
            if ('' === self::lado($clase)) {
                return null;
            }

            $patron = self::patronPara($clase);
            if ('' === $patron) {
                return null;
            }

            // {empresa} es la única variable que consulta datos: solo se resuelve si el patrón la usa
            $conEmpresa = 1 === preg_match('/\{\s*empresa\s*\}/iu', $patron);
            $variables = self::variables($model, $conEmpresa);
            $nombre = self::sanear(self::aplicarPatron($patron, $variables), self::maxLongitud());

            return '' === $nombre ? null : $nombre;
        } catch (Throwable $exception) {
            Tools::log()->warning('auralis-pdf-nombre-error', ['%error%' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * Documentos marcados en un listado, en el orden del cursor (el mismo del PDF). El cursor ya viene
     * filtrado por el Core («solo sus datos»), así que nunca entra un documento que el usuario no vea.
     *
     * @param iterable<object> $cursor
     * @param array<int, mixed> $codigos
     *
     * @return object[]
     */
    public static function documentosMarcados(iterable $cursor, array $codigos): array
    {
        $codigos = array_map('strval', $codigos);
        $documentos = [];
        foreach ($cursor as $model) {
            if (is_object($model) && method_exists($model, 'primaryColumnValue')
                && in_array((string)$model->primaryColumnValue(), $codigos, true)) {
                $documentos[] = $model;
            }
        }

        return $documentos;
    }

    /**
     * Nombre para un PDF con uno o varios documentos: el del primero y, si hay más, «_y_N_mas».
     * null = dejar el nombre del Core.
     *
     * @param object[] $documentos
     */
    public static function construirParaVarios(array $documentos): ?string
    {
        $primero = reset($documentos);
        if (false === $primero) {
            return null;
        }

        $nombre = self::construir($primero);
        $resto = count($documentos) - 1;
        if (null === $nombre || $resto < 1) {
            return $nombre;
        }

        // se recorta el nombre del primero para que el sufijo quepa siempre en la longitud máxima
        $sufijo = '_y_' . $resto . '_mas';
        $base = self::sanear($nombre, self::maxLongitud() - mb_strlen($sufijo));

        return '' === $base ? null : $base . $sufijo;
    }

    /**
     * Nombre de la clase del modelo: modelClassName() si existe, si no get_class().
     */
    private static function nombreModelo(object $model): string
    {
        if (method_exists($model, 'modelClassName')) {
            return (string)$model->modelClassName();
        }

        return get_class($model);
    }

    /**
     * Lo que va tras la última «\» (admite nombre corto o FQCN).
     */
    private static function nombreCorto(string $clase): string
    {
        $posicion = strrpos($clase, '\\');

        return false === $posicion ? $clase : substr($clase, $posicion + 1);
    }

    /**
     * Valor escalar de una propiedad del modelo como texto, con las entidades de Tools::noHtml() deshechas.
     */
    private static function texto(object $model, string $propiedad): string
    {
        $valor = $model->{$propiedad} ?? '';
        if (false === is_scalar($valor)) {
            return '';
        }

        return (string)Tools::fixHtml((string)$valor);
    }

    private static function fechaYmd(string $fecha): string
    {
        if ('' === $fecha) {
            return '';
        }

        $marca = strtotime($fecha);

        return false === $marca ? '' : date('Y-m-d', $marca);
    }

    private static function nombreEmpresa(object $model): string
    {
        try {
            $empresa = Empresas::get((int)($model->idempresa ?? 0));
            $nombre = (string)$empresa->nombrecorto;
            if ('' === trim($nombre)) {
                $nombre = (string)$empresa->nombre;
            }

            return (string)Tools::fixHtml($nombre);
        } catch (Throwable $exception) {
            return '';
        }
    }
}

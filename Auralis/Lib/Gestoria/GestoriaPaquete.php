<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Construye el ZIP para la gestoría: PDF de cada documento, adjuntos reales de compras y resumen CSV.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

use Closure;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\AttachedFileRelation;
use FacturaScripts\Plugins\Auralis\Lib\PdfNombreArchivo;
use RuntimeException;
use Throwable;
use ZipArchive;

final class GestoriaPaquete
{
    /** Carpeta de trabajo bajo MyFiles. */
    public const CARPETA_TMP = 'MyFiles/Tmp/AuralisGestoria';

    /** Antigüedad a partir de la cual se purgan restos de ejecuciones anteriores. */
    public const CADUCIDAD_SEGUNDOS = 86400;

    public const NOMBRE_CSV = 'resumen.csv';

    private GestoriaCriterios $criterios;

    /** @var Closure(BusinessDocument): string */
    private Closure $generadorPdf;

    private GestoriaNombres $nombres;

    private GestoriaResumenCsv $csv;

    private string $directorio = '';

    /** @var list<string> */
    private array $temporales = [];

    /** @var list<array{codigo: string, nombre: string, url: string}> */
    private array $sinAdjunto = [];

    private int $ficheros = 0;

    /**
     * @param Closure(BusinessDocument): string|null $generadorPdf Devuelve el PDF binario; por defecto el ExportManager.
     */
    public function __construct(GestoriaCriterios $criterios, ?Closure $generadorPdf = null)
    {
        $this->criterios = $criterios;
        $this->generadorPdf = $generadorPdf ?? static function (BusinessDocument $doc): string {
            return self::pdfDocumento($doc);
        };
        $this->nombres = new GestoriaNombres();
        $this->csv = new GestoriaResumenCsv();
    }

    public static function zipDisponible(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Genera el ZIP con los documentos recibidos (en el orden dado).
     *
     * @param list<array{ambito: string, tipo: string, doc: BusinessDocument}> $entradas
     *
     * @return array{ruta: string, nombre: string, ficheros: int, documentos: int, sin_adjunto: list<array{codigo: string, nombre: string, url: string}>}
     */
    public function construir(array $entradas): array
    {
        if (false === self::zipDisponible()) {
            throw new RuntimeException('auralis-gestoria-error-zip-extension');
        }

        self::purgarAntiguos();
        $this->directorio = self::crearDirectorio();
        $nombrePaquete = GestoriaNombres::zip($this->criterios->desde, $this->criterios->hasta);
        $rutaZip = $this->directorio . '/' . $nombrePaquete;

        $zip = new ZipArchive();
        if (true !== $zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            self::borrarDirectorio($this->directorio);
            throw new RuntimeException('auralis-gestoria-error-zip');
        }

        try {
            foreach ($entradas as $entrada) {
                $this->anadirDocumento($zip, $entrada['ambito'], $entrada['tipo'], $entrada['doc']);
            }

            if ($this->criterios->incluirCsv) {
                $zip->addFromString(self::NOMBRE_CSV, $this->csv->generar());
            }

            if (0 === $zip->numFiles) {
                $zip->addFromString(self::NOMBRE_CSV, $this->csv->generar());
            }

            if (true !== $zip->close()) {
                throw new RuntimeException('auralis-gestoria-error-zip');
            }
        } catch (Throwable $excepcion) {
            $this->borrarTemporales();
            self::borrarDirectorio($this->directorio);
            throw $excepcion;
        }

        // ZipArchive lee los ficheros al cerrar: hasta entonces no se pueden borrar
        $this->borrarTemporales();

        return [
            'ruta' => $rutaZip,
            'nombre' => $nombrePaquete,
            'ficheros' => $this->ficheros,
            'documentos' => count($entradas),
            'sin_adjunto' => $this->sinAdjunto,
        ];
    }

    /**
     * Adjuntos reales del documento (ruta validada dentro de MyFiles y nombre visible).
     *
     * @return list<array{ruta: string, nombre: string}>
     */
    public static function adjuntosDe(BusinessDocument $doc): array
    {
        $id = (string)$doc->id();
        if ($id === '') {
            return [];
        }

        $filtros = [
            Where::eq('model', $doc->modelClassName()),
            Where::eq('modelid|modelcode', $id),
        ];

        $carpetaBase = realpath(FS_FOLDER . '/MyFiles');
        if (false === $carpetaBase) {
            return [];
        }

        $adjuntos = [];
        foreach (AttachedFileRelation::all($filtros, ['orden' => 'ASC', 'id' => 'ASC']) as $relacion) {
            $fichero = $relacion->getFile();
            if (null === $fichero) {
                continue;
            }

            $ruta = realpath($fichero->getFullPath());
            if (false === $ruta || false === is_file($ruta) || false === str_starts_with($ruta, $carpetaBase . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $nombre = basename(str_replace('\\', '/', (string)($fichero->filename ?: $ruta)));
            $adjuntos[] = ['ruta' => $ruta, 'nombre' => $nombre];
        }

        return $adjuntos;
    }

    /**
     * PDF del documento con el motor de exportación activo (respeta plantillas y estilo configurados).
     * El motor es estático: se genera un documento cada vez, en secuencia.
     */
    public static function pdfDocumento(BusinessDocument $doc): string
    {
        // Título interno del PDF: solo el código del documento (no el nombre de la pantalla, que quien lo recibe no necesita ver).
        $titulo = (string)$doc->codigo;
        $gestorExportacion = new ExportManager();
        $gestorExportacion->newDoc('PDF', $titulo, 0, '');
        $gestorExportacion->addBusinessDocPage($doc);

        return (string)$gestorExportacion->getDoc();
    }

    /**
     * Borra el ZIP y su carpeta de trabajo.
     */
    public static function limpiar(string $rutaZip): void
    {
        $carpeta = dirname($rutaZip);
        if (self::esCarpetaDeTrabajo($carpeta)) {
            self::borrarDirectorio($carpeta);
        }
    }

    /**
     * Elimina carpetas de trabajo de más de 24 h (descargas interrumpidas, errores).
     */
    public static function purgarAntiguos(int $caducidad = self::CADUCIDAD_SEGUNDOS, ?int $ahora = null): int
    {
        $raiz = FS_FOLDER . '/' . self::CARPETA_TMP;
        if (false === is_dir($raiz)) {
            return 0;
        }

        $ahora ??= time();
        $borradas = 0;
        foreach ((array)scandir($raiz) as $entrada) {
            $entrada = (string)$entrada;
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $ruta = $raiz . '/' . $entrada;
            $modificado = (int)filemtime($ruta);
            if ($ahora - $modificado < $caducidad) {
                continue;
            }

            if (is_dir($ruta)) {
                self::borrarDirectorio($ruta);
            } else {
                unlink($ruta);
            }
            ++$borradas;
        }

        return $borradas;
    }

    private function anadirDocumento(ZipArchive $zip, string $ambito, string $tipo, BusinessDocument $doc): void
    {
        $esCompra = $ambito === GestoriaCriterios::AMBITO_COMPRAS;
        $titular = $esCompra ? (string)($doc->nombre ?? '') : (string)($doc->nombrecliente ?? '');
        $fecha = self::fechaIso((string)$doc->fecha);
        $codigo = (string)$doc->codigo;
        $etiqueta = Tools::trans(GestoriaCriterios::TIPOS[$ambito][$tipo]['etiqueta']);

        $incluidos = [];
        $aviso = '';
        $modo = $esCompra ? $this->criterios->modoCompras : GestoriaCriterios::MODO_PDF;

        if ($modo !== GestoriaCriterios::MODO_PDF) {
            $adjuntos = self::adjuntosDe($doc);
            foreach ($adjuntos as $adjunto) {
                $extension = pathinfo($adjunto['nombre'], PATHINFO_EXTENSION);
                $nombre = $this->nombres->documento($ambito, $fecha, $codigo, $titular, $extension);
                if ($zip->addFile($adjunto['ruta'], $ambito . '/' . $nombre)) {
                    $incluidos[] = $nombre;
                }
            }

            if ($adjuntos === []) {
                $aviso = Tools::trans('auralis-gestoria-sin-adjunto');
                $this->sinAdjunto[] = [
                    'codigo' => $codigo,
                    'nombre' => $titular,
                    'url' => $doc->url('edit'),
                ];
            }
        }

        $necesitaPdf = $modo === GestoriaCriterios::MODO_PDF
            || ($modo === GestoriaCriterios::MODO_ADJUNTOS_PDF && $incluidos === []);
        if ($necesitaPdf) {
            $nombre = $this->nombrePdf($ambito, $doc, $fecha, $codigo, $titular);
            $rutaPdf = $this->directorio . '/' . bin2hex(random_bytes(8)) . '.pdf';
            $contenido = ($this->generadorPdf)($doc);
            if ($contenido !== '' && false !== file_put_contents($rutaPdf, $contenido)) {
                $this->temporales[] = $rutaPdf;
                if ($zip->addFile($rutaPdf, $ambito . '/' . $nombre)) {
                    $incluidos[] = $nombre;
                }
            }
        }

        $this->ficheros += count($incluidos);
        $this->csv->anadir([
            'fecha' => $fecha,
            'tipo' => $etiqueta,
            'codigo' => $codigo,
            'numproveedor' => $esCompra ? (string)($doc->numproveedor ?? '') : '',
            'cifnif' => (string)($doc->cifnif ?? ''),
            'nombre' => $titular,
            'neto' => (float)$doc->neto,
            'iva' => (float)$doc->totaliva,
            'total' => (float)$doc->total,
            'fichero' => $incluidos === [] ? '' : $ambito . '/' . implode(', ' . $ambito . '/', $incluidos),
            'aviso' => $aviso,
        ]);
    }

    /**
     * Nombre del PDF dentro de su carpeta. Con «Nombres de PDF» activo y un patrón para ese lado, el del patrón
     * (con su longitud máxima y saneado); si no, «fecha_codigo_nombre» como siempre. Los adjuntos no pasan por aquí.
     */
    private function nombrePdf(string $ambito, BusinessDocument $doc, string $fecha, string $codigo, string $titular): string
    {
        $base = PdfNombreArchivo::construir($doc);
        if (null !== $base) {
            return $this->nombres->unico($ambito, $base, 'pdf', PdfNombreArchivo::maxLongitud());
        }

        return $this->nombres->documento($ambito, $fecha, $codigo, $titular, 'pdf');
    }

    private function borrarTemporales(): void
    {
        foreach ($this->temporales as $ruta) {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
        $this->temporales = [];
    }

    private static function crearDirectorio(): string
    {
        $directorio = FS_FOLDER . '/' . self::CARPETA_TMP . '/' . bin2hex(random_bytes(12));
        if (false === Tools::folderCheckOrCreate($directorio)) {
            throw new RuntimeException('auralis-gestoria-error-zip');
        }

        return $directorio;
    }

    private static function esCarpetaDeTrabajo(string $carpeta): bool
    {
        $raiz = realpath(FS_FOLDER . '/' . self::CARPETA_TMP);
        $real = realpath($carpeta);

        return $raiz !== false && $real !== false && str_starts_with($real, $raiz . DIRECTORY_SEPARATOR);
    }

    private static function borrarDirectorio(string $carpeta): void
    {
        if (false === self::esCarpetaDeTrabajo($carpeta)) {
            return;
        }

        foreach ((array)scandir($carpeta) as $entrada) {
            $entrada = (string)$entrada;
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $ruta = $carpeta . '/' . $entrada;
            if (is_dir($ruta)) {
                self::borrarDirectorio($ruta);
            } elseif (is_file($ruta)) {
                unlink($ruta);
            }
        }

        rmdir($carpeta);
    }

    private static function fechaIso(string $fecha): string
    {
        $marca = strtotime($fecha);

        return false === $marca ? $fecha : date('Y-m-d', $marca);
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Informe «Documentos para la gestoría»: comprobación, ZIP de ventas y compras del periodo y envío por email.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use DateTimeImmutable;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\DataSrc\Series;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\MultiRequestProtection;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaCriterios;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaEnvio;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaPaquete;
use FacturaScripts\Plugins\Auralis\Lib\Gestoria\GestoriaSelector;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use RuntimeException;
use Throwable;

class DocumentosGestoriaAuralis extends Controller
{
    public const ACCION_COMPROBAR = 'gestoria-comprobar';
    public const ACCION_DESCARGAR = 'gestoria-descargar';
    public const ACCION_ENVIAR = 'gestoria-enviar';
    public const ACCION_BUSCAR = 'gestoria-buscar';

    public const ACCIONES_POST = [self::ACCION_COMPROBAR, self::ACCION_DESCARGAR, self::ACCION_ENVIAR];

    /** Cabecera con el token CSRF nuevo cuando la respuesta es el ZIP (no JSON). */
    public const CABECERA_TOKEN = 'X-Auralis-Token';

    /** Segundos de ejecución pedidos para paquetes grandes. */
    private const LIMITE_TIEMPO = 600;

    /** @var array<string, array{desde: string, hasta: string}> */
    public array $atajos = [];

    public string $desdeDefecto = '';
    public string $hastaDefecto = '';

    /** @var array<int, string> idempresa → nombre (vacío si no procede elegir empresa) */
    public array $empresasLista = [];

    /** @var array<string, string> codserie → descripción */
    public array $seriesLista = [];

    /** @var array<string, array<string, array{etiqueta: string, disponible: bool}>> */
    public array $tiposPantalla = [];

    public string $emailGestoria = '';
    public bool $puedeExportar = false;
    public bool $puedeGuardarEmail = false;
    public int $maxDocumentos = GestoriaEnvio::DEFECTO_MAX_DOCUMENTOS;
    public int $maxMb = GestoriaEnvio::DEFECTO_MAX_MB;

    /** Enlace a la pestaña de ajustes de la gestoría (vacío si el usuario no tiene acceso). */
    public string $urlAjustes = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['submenu'] = 'dashboards-auralis';
        $data['title'] = 'auralis-gestoria-titulo';
        $data['icon'] = 'fa-solid fa-file-zipper';
        return $data;
    }

    public function run(): void
    {
        parent::run();

        if ((string)$this->request()->query('action', '') === self::ACCION_BUSCAR) {
            $this->accionBuscar();
            return;
        }

        if ($this->ejecutarAccion((string)$this->request()->input('action', ''))) {
            return;
        }

        $this->cargarPantalla();
        $this->view('@PluginAuralis/DocumentosGestoriaAuralis.html.twig');
    }

    /**
     * Acciones POST: exigen token CSRF y permiso de exportación de la página.
     * Devuelve true si la petición queda respondida.
     */
    protected function ejecutarAccion(string $accion): bool
    {
        if (false === in_array($accion, self::ACCIONES_POST, true)) {
            return false;
        }

        if (false === $this->validateFormToken()) {
            $this->responderError('invalid-request', Response::HTTP_FORBIDDEN);
            return true;
        }

        if (null === $this->permissions || false === $this->permissions->allowExport) {
            $this->responderError('auralis-gestoria-error-permiso', Response::HTTP_FORBIDDEN);
            return true;
        }

        $criterios = GestoriaCriterios::desdeEntrada($this->request()->request->all(), $this->user);
        if ($criterios->error !== '') {
            $this->responderError($criterios->error, Response::HTTP_BAD_REQUEST);
            return true;
        }

        $selector = new GestoriaSelector($criterios, $this->user);
        if ($selector->tiposAccesibles(GestoriaCriterios::AMBITO_VENTAS) === []
            && $selector->tiposAccesibles(GestoriaCriterios::AMBITO_COMPRAS) === []
        ) {
            $this->responderError('auralis-gestoria-error-permiso-tipos', Response::HTTP_FORBIDDEN);
            return true;
        }

        try {
            switch ($accion) {
                case self::ACCION_COMPROBAR:
                    $this->accionComprobar($criterios, $selector);
                    break;

                case self::ACCION_DESCARGAR:
                    $this->accionDescargar($criterios, $selector);
                    break;

                default:
                    $this->accionEnviar($criterios, $selector);
                    break;
            }
        } catch (RuntimeException $excepcion) {
            $this->responderError($excepcion->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (Throwable $excepcion) {
            Tools::log('auralis')->error('auralis-gestoria-log-error', ['%error%' => $excepcion->getMessage()]);
            $this->responderError('auralis-gestoria-error-zip', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return true;
    }

    private function accionComprobar(GestoriaCriterios $criterios, GestoriaSelector $selector): void
    {
        $maximo = GestoriaEnvio::maxDocumentos();
        $resumen = [];
        $totalDocumentos = 0;
        foreach ($selector->resumen() as $linea) {
            $totalDocumentos += $linea['documentos'];
            $resumen[] = [
                'ambito' => $linea['ambito'],
                'etiqueta' => Tools::trans($linea['etiqueta']),
                'documentos' => $linea['documentos'],
                'total' => Tools::money($linea['total']),
            ];
        }

        $sinAdjunto = [];
        if ($criterios->tiposDe(GestoriaCriterios::AMBITO_COMPRAS) !== []) {
            foreach ($selector->documentos($maximo, GestoriaCriterios::AMBITO_COMPRAS) as $entrada) {
                $doc = $entrada['doc'];
                if (GestoriaPaquete::adjuntosDe($doc) === []) {
                    $sinAdjunto[] = [
                        'codigo' => (string)$doc->codigo,
                        'fecha' => Tools::date((string)$doc->fecha),
                        'nombre' => (string)($doc->nombre ?? ''),
                        'url' => $doc->url('edit'),
                    ];
                }
            }
        }

        $denegados = [];
        foreach ($selector->tiposDenegados() as $etiqueta) {
            $denegados[] = Tools::trans($etiqueta);
        }

        $this->responderJson([
            'ok' => true,
            'resumen' => $resumen,
            'total_documentos' => $totalDocumentos,
            'maximo' => $maximo,
            'excede' => $totalDocumentos > $maximo,
            'tiempo_estimado' => Tools::trans('auralis-gestoria-tiempo-estimado', [
                '%segundos%' => (string)GestoriaEnvio::segundosEstimados(min($totalDocumentos, $maximo)),
            ]),
            'modo_compras' => $criterios->modoCompras,
            'sin_adjunto' => $sinAdjunto,
            'denegados' => $denegados,
        ]);
    }

    private function accionDescargar(GestoriaCriterios $criterios, GestoriaSelector $selector): void
    {
        $paquete = $this->construirPaquete($criterios, $selector);
        if (null === $paquete) {
            return;
        }

        $this->registrarLog('auralis-gestoria-log-descarga', $criterios, $paquete);

        $this->response()->header(self::CABECERA_TOKEN, (new MultiRequestProtection())->newToken());
        $this->response()->download($paquete['ruta'], $paquete['nombre']);
        $this->response()->disableSend();

        GestoriaPaquete::limpiar($paquete['ruta']);
    }

    private function accionEnviar(GestoriaCriterios $criterios, GestoriaSelector $selector): void
    {
        $email = GestoriaEnvio::normalizarEmail((string)$this->request()->input('email', ''));
        if ($email === '') {
            $this->responderError('auralis-gestoria-error-email', Response::HTTP_BAD_REQUEST);
            return;
        }

        if (false === GestoriaEnvio::correoConfigurado()) {
            $this->responderError('email-not-configured', Response::HTTP_BAD_REQUEST);
            return;
        }

        $paquete = $this->construirPaquete($criterios, $selector);
        if (null === $paquete) {
            return;
        }

        $maxMb = GestoriaEnvio::maxMb();
        if (GestoriaEnvio::superaTamano($paquete['ruta'], $maxMb)) {
            GestoriaPaquete::limpiar($paquete['ruta']);
            $this->responderError('auralis-gestoria-error-tamano', Response::HTTP_BAD_REQUEST, ['%mb%' => (string)$maxMb]);
            return;
        }

        try {
            $enviado = GestoriaEnvio::enviar(
                $email,
                $paquete['ruta'],
                $paquete['nombre'],
                $criterios->desde,
                $criterios->hasta,
                $paquete['documentos']
            );
        } finally {
            GestoriaPaquete::limpiar($paquete['ruta']);
        }

        if (false === $enviado) {
            $this->responderError('auralis-gestoria-error-envio', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $this->registrarLog('auralis-gestoria-log-envio', $criterios, $paquete);

        $guardado = false;
        if ($this->permissions->allowUpdate
            && (string)$this->request()->input('guardar_email', '') === '1'
            && $email !== GestoriaEnvio::emailPredeterminado()
        ) {
            $guardado = GestoriaEnvio::guardarEmailPredeterminado($email);
        }

        $this->responderJson([
            'ok' => true,
            'message' => Tools::trans('auralis-gestoria-enviado', ['%documentos%' => (string)$paquete['documentos']]),
            'email_guardado' => $guardado,
        ]);
    }

    /**
     * Comprueba límites, selecciona documentos y genera el ZIP. Responde el error y devuelve null si no procede.
     *
     * @return array{ruta: string, nombre: string, ficheros: int, documentos: int, sin_adjunto: list<array{codigo: string, nombre: string, url: string}>}|null
     */
    private function construirPaquete(GestoriaCriterios $criterios, GestoriaSelector $selector): ?array
    {
        if (false === GestoriaPaquete::zipDisponible()) {
            $this->responderError('auralis-gestoria-error-zip-extension', Response::HTTP_INTERNAL_SERVER_ERROR);
            return null;
        }

        $maximo = GestoriaEnvio::maxDocumentos();
        $total = $selector->contarTotal();
        if ($total === 0) {
            $this->responderError('auralis-gestoria-error-vacio', Response::HTTP_BAD_REQUEST);
            return null;
        }
        if ($total > $maximo) {
            $this->responderError('auralis-gestoria-error-maximo', Response::HTTP_BAD_REQUEST, [
                '%documentos%' => (string)$total,
                '%maximo%' => (string)$maximo,
            ]);
            return null;
        }

        self::ampliarTiempo();

        return (new GestoriaPaquete($criterios))->construir($selector->documentos($maximo));
    }

    /**
     * Autocompletar de cliente o proveedor (GET). Token validado sin consumirlo: se dispara al teclear.
     */
    private function accionBuscar(): void
    {
        $token = (string)$this->request()->query('multireqtoken', '');
        if ($token === '' || false === (new MultiRequestProtection())->validate($token)) {
            $this->responderError('invalid-request', Response::HTTP_FORBIDDEN);
            return;
        }

        $tipo = (string)$this->request()->query('tipo', '');
        $texto = mb_substr(trim((string)$this->request()->query('term', '')), 0, 50);
        $esCliente = $tipo === 'cliente';
        $pagina = $esCliente ? 'EditCliente' : 'EditProveedor';
        if (false === in_array($tipo, ['cliente', 'proveedor'], true)
            || null === $this->permissions
            || false === $this->permissions->allowExport
            || null === $this->user
            || false === $this->user->can($pagina)
        ) {
            $this->responderError('auralis-gestoria-error-permiso', Response::HTTP_FORBIDDEN);
            return;
        }

        $resultados = [];
        if (mb_strlen($texto) >= 1) {
            $campoCodigo = $esCliente ? 'codcliente' : 'codproveedor';
            $filtros = [Where::xlike($campoCodigo . '|nombre|razonsocial|cifnif', $texto)];
            if ($esCliente && $this->user->can($pagina, 'only-owner-data') && false === empty($this->user->codagente)) {
                $filtros[] = Where::eq('codagente', $this->user->codagente);
            }

            $registros = $esCliente
                ? Cliente::all($filtros, ['nombre' => 'ASC'], 0, 20)
                : Proveedor::all($filtros, ['nombre' => 'ASC'], 0, 20);
            foreach ($registros as $registro) {
                $resultados[] = [
                    'codigo' => (string)$registro->{$campoCodigo},
                    'nombre' => (string)$registro->nombre,
                ];
            }
        }

        $this->response()->json(['ok' => true, 'resultados' => $resultados]);
    }

    private function cargarPantalla(): void
    {
        $hoy = new DateTimeImmutable('today');
        $this->atajos = GestoriaCriterios::atajos($hoy);
        $this->desdeDefecto = $this->atajos['mes_anterior']['desde'];
        $this->hastaDefecto = $this->atajos['mes_anterior']['hasta'];

        $this->puedeExportar = null !== $this->permissions && $this->permissions->allowExport;
        $this->puedeGuardarEmail = null !== $this->permissions && $this->permissions->allowUpdate;
        $this->emailGestoria = GestoriaEnvio::emailPredeterminado();
        $this->maxDocumentos = GestoriaEnvio::maxDocumentos();
        $this->maxMb = GestoriaEnvio::maxMb();
        $this->urlAjustes = null !== $this->user && $this->user->can('ConfigAjustesAuralis')
            ? 'ConfigAjustesAuralis?activetab=ConfigAuralisGestoria'
            : '';

        $this->empresasLista = [];
        $empresas = Empresas::all();
        if (count($empresas) > 1 && false === AuralisSecurity::isEmpresaLocked($this->user)) {
            foreach ($empresas as $empresa) {
                $this->empresasLista[(int)$empresa->idempresa] = (string)$empresa->nombrecorto;
            }
        }

        $this->seriesLista = [];
        foreach (Series::all() as $serie) {
            $this->seriesLista[(string)$serie->codserie] = (string)$serie->descripcion;
        }

        $this->tiposPantalla = [];
        foreach (GestoriaCriterios::TIPOS as $ambito => $tipos) {
            foreach ($tipos as $tipo => $definicion) {
                $this->tiposPantalla[$ambito][$tipo] = [
                    'etiqueta' => $definicion['etiqueta'],
                    'disponible' => null !== $this->user && $this->user->can($definicion['edit']),
                ];
            }
        }
    }

    /**
     * Log de auditoría: solo periodo y recuentos (sin datos personales).
     *
     * @param array{ficheros: int, documentos: int} $paquete
     */
    private function registrarLog(string $clave, GestoriaCriterios $criterios, array $paquete): void
    {
        Tools::log('auralis')->notice($clave, [
            '%desde%' => $criterios->desde,
            '%hasta%' => $criterios->hasta,
            '%documentos%' => (string)$paquete['documentos'],
            '%ficheros%' => (string)$paquete['ficheros'],
        ]);
    }

    /**
     * @param array<string, string> $parametros
     */
    private function responderError(string $clave, int $codigo, array $parametros = []): void
    {
        $this->responderJson(['ok' => false, 'message' => Tools::trans($clave, $parametros)], $codigo);
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function responderJson(array $datos, int $codigo = Response::HTTP_OK): void
    {
        $datos['newToken'] = (new MultiRequestProtection())->newToken();
        $this->response()->setHttpCode($codigo)->json($datos);
    }

    private static function ampliarTiempo(): void
    {
        $desactivadas = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (function_exists('set_time_limit') && false === in_array('set_time_limit', $desactivadas, true)) {
            set_time_limit(self::LIMITE_TIEMPO);
        }
    }
}

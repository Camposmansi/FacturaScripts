<?php

declare(strict_types=1);

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Tablero Kanban de Auralis: página y AJAX (cargar tarjetas, moverlas y detalle opcional en modal), con los permisos de las páginas del Core.
 */

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Lib\CalendarAjaxResponse;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanAcceso;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanDetalle;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanDetalleInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanFiltrosInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanOrdenableInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanProviderInterface;
use Throwable;

class KanbanAuralis extends Controller
{
    /** Tablero que se abre si la URL no indica ninguno. */
    private const TABLERO_POR_DEFECTO = 'presupuestos-cliente';

    private const ACCION_TARJETAS = 'kanban-cards';
    private const ACCION_MOVER = 'kanban-move';
    private const ACCION_DETALLE = 'kanban-detail';
    private const ACCION_GUARDAR_DETALLE = 'kanban-detail-save';
    private const ACCION_TARJETA = 'kanban-card';

    /** Buscador de la barra de filtros: GET de solo lectura, con los permisos de lectura del tablero. */
    private const ACCION_BUSCAR_FILTRO = 'kanban-filter-search';

    /** Acciones de la carcasa de detalle: solo para tableros con KanbanDetalleInterface. */
    private const ACCIONES_DETALLE = [self::ACCION_DETALLE, self::ACCION_GUARDAR_DETALLE, self::ACCION_TARJETA];

    /** Carcasa Twig del cuerpo del modal de detalle. */
    private const PLANTILLA_DETALLE = '@PluginAuralis/Master/_kanban_detalle.html.twig';

    private const LIMITE_POR_DEFECTO = 25;
    /** El proveedor sirve como máximo 50 tarjetas: se pide una de más para saber si quedan, de ahí 49. */
    private const LIMITE_MAXIMO = 49;

    /** Tablero resuelto; null si no está disponible (ajuste apagado, no existe o sin acceso). */
    public ?KanbanProviderInterface $provider = null;

    /** El usuario puede mover tarjetas (permiso de actualizar de la página de edición). */
    public bool $puedeMover = false;

    /** Filtros saneados de la URL, que el JS reenvía en cada petición. */
    public array $filtros = [];

    /** Modo efectivo del detalle de la tarjeta: 'page' (abrir la ficha) o 'modal'. */
    public string $modoDetalle = KanbanDetalleInterface::DETALLE_PAGINA;

    /** Modo pedido en la URL (?detalle=page|modal), para conservarlo en los enlaces; '' si no viene. */
    public string $detallePedido = '';

    /** Enlace «Tablero» a esta misma vista (tablero, filtros y modo de detalle pedido). */
    public string $urlTablero = '';

    /**
     * Assets propios del detalle del proveedor, ya validados (solo Dinamic/Assets/JS|CSS, sin '..').
     * Solo se cargan en modo modal.
     *
     * @var array{js: string[], css: string[]}
     */
    public array $detalleAssets = ['js' => [], 'css' => []];

    /**
     * Controles de la barra de filtros (KanbanFiltrosInterface), con su valor actual; [] si el tablero no la ofrece.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $barraFiltros = [];

    /**
     * Campos POST para volver a «Lista» con los mismos filtros; [] si el tablero no los ofrece.
     *
     * @var array<string, string>
     */
    public array $camposLista = [];

    /**
     * Filtros que vienen en la URL pero no tienen control en la barra (p. ej. la empresa):
     * van ocultos en el formulario para no perderlos.
     *
     * @var array<string, string>
     */
    public array $filtrosOcultos = [];

    /**
     * Criterios del selector «Ordenar por» (KanbanOrdenableInterface), con el texto ya traducido;
     * [] si el tablero no ofrece más de uno (entonces no se pinta el selector).
     *
     * @var array<int, array{clave: string, texto: string, dir: string}>
     */
    public array $opcionesOrden = [];

    /** Criterio y dirección actuales, de la lista cerrada; '' si no hay selector. */
    public string $orden = '';
    public string $dirOrden = '';

    /** El criterio vino en la URL: manda sobre el recordado en el navegador. */
    public bool $ordenEnUrl = false;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'auralis-kanban-tablero';
        $data['icon'] = 'fa-solid fa-table-columns';
        $data['showonmenu'] = false;
        return $data;
    }

    /**
     * Los permisos son los de las páginas del Core que el tablero declara
     * (lectura y edición), no los de KanbanAuralis. Si el tablero no está disponible
     * la página solo pinta el aviso y el AJAX responde 403: no se carga ningún dato.
     */
    public function privateCore(&$response, $user, $permissions)
    {
        $this->provider = KanbanAcceso::tablero($this->claveTablero(), $user);

        if ($this->provider === null) {
            // Acceso solo para poder mostrar el aviso «no disponible»; ninguna ruta lee datos sin tablero.
            $permisosPagina = new ControllerPermissions();
            $permisosPagina->set(true, 1, false, false);
        } else {
            $permisosPagina = new ControllerPermissions($user, $this->provider->readPermission());
        }

        parent::privateCore($response, $user, $permisosPagina);

        // Título de la pestaña del navegador con el nombre del tablero («Tablero» a secas es el Dashboard).
        if ($this->provider !== null) {
            $this->title = $this->provider->title();
        }

        // buscador de la barra de filtros (GET, solo lectura): no consume el token del tablero
        if ($this->request->isMethod('GET') && $this->request->query('action', '') === self::ACCION_BUSCAR_FILTRO) {
            $this->setTemplate(false);
            $this->ajaxBuscarFiltro();
            return;
        }

        $accion = (string) $this->request->input('action', '');
        $acciones = array_merge([self::ACCION_TARJETAS, self::ACCION_MOVER], self::ACCIONES_DETALLE);
        $esAjax = $this->request->isMethod('POST') && in_array($accion, $acciones, true);

        if ($esAjax) {
            $this->setTemplate(false);
            $this->atenderAjax($accion);
            return;
        }

        if ($this->provider !== null) {
            $this->puedeMover = KanbanAcceso::puedeMover($this->provider, $user);
            $this->filtros = KanbanAcceso::filtros($this->provider, $this->request->query->all());
            $this->prepararOrden($this->provider, $this->request->query->all());
            $this->prepararDetalle($this->provider);
            $this->prepararFiltros($this->provider, $user);
        }
    }

    /**
     * Selector «Ordenar por»: solo si el tablero ofrece más de un criterio. El criterio de la URL
     * pasa por la lista cerrada (KanbanAcceso::orden()); si no viene, el JS usa el recordado.
     *
     * @param array<string, mixed> $consulta
     */
    private function prepararOrden(KanbanProviderInterface $proveedor, array $consulta): void
    {
        $opciones = KanbanAcceso::opcionesOrden($proveedor);
        if (count($opciones) < 2) {
            return;
        }

        foreach ($opciones as $opcion) {
            $this->opcionesOrden[] = [
                'clave' => $opcion['clave'],
                'texto' => Tools::trans($opcion['etiqueta']),
                'dir' => $opcion['dir'],
            ];
        }

        $elegido = KanbanAcceso::orden($proveedor, $consulta);
        $this->orden = (string) ($elegido[KanbanOrdenableInterface::PARAM_ORDEN] ?? '');
        $this->dirOrden = (string) ($elegido[KanbanOrdenableInterface::PARAM_DIR] ?? '');
        $pedido = $consulta[KanbanOrdenableInterface::PARAM_ORDEN] ?? null;
        $this->ordenEnUrl = is_string($pedido) && trim($pedido) === $this->orden;
    }

    /** Barra de filtros, filtros ocultos y campos para volver a «Lista» con los mismos filtros. */
    private function prepararFiltros(KanbanProviderInterface $proveedor, $user): void
    {
        if (false === $proveedor instanceof KanbanFiltrosInterface) {
            return;
        }

        $permitidos = $proveedor->allowedFilters();
        foreach ($proveedor->barraFiltros($this->filtros, $user) as $control) {
            $nombre = (string) ($control['nombre'] ?? '');
            $tipo = (string) ($control['tipo'] ?? '');
            $tipos = [KanbanFiltrosInterface::FILTRO_SELECT, KanbanFiltrosInterface::FILTRO_BUSCAR, KanbanFiltrosInterface::FILTRO_FECHA];
            if (false === isset($permitidos[$nombre]) || false === in_array($tipo, $tipos, true)) {
                continue;
            }

            $control['valor'] = (string) ($this->filtros[$nombre] ?? '');
            $control['opciones'] = $tipo === KanbanFiltrosInterface::FILTRO_SELECT ? (array) ($control['opciones'] ?? []) : [];
            $control['texto'] = (string) ($control['texto'] ?? '');
            $this->barraFiltros[] = $control;
        }

        $conControl = array_column($this->barraFiltros, 'nombre');
        foreach ($this->filtros as $nombre => $valor) {
            if (false === in_array($nombre, $conControl, true)) {
                $this->filtrosOcultos[$nombre] = $valor;
            }
        }

        $this->camposLista = $proveedor->filtrosListado($this->filtros);
    }

    /** Opciones de un control «buscar» de la barra (JSON, solo lectura). */
    private function ajaxBuscarFiltro(): void
    {
        $proveedor = $this->provider;
        if (false === $proveedor instanceof KanbanFiltrosInterface) {
            $this->response()->setHttpCode(403);
            $this->response()->json(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-disponible')]);
            return;
        }

        $nombre = (string) $this->request->query('filtro', '');
        $texto = mb_substr(trim((string) $this->request->query('q', '')), 0, 60);

        try {
            $opciones = $proveedor->buscarFiltro($nombre, $texto, $this->user);
        } catch (Throwable $excepcion) {
            Tools::log('Auralis')->error('Kanban: buscar filtro: ' . $excepcion->getMessage());
            $opciones = [];
        }

        $this->response()->json(['status' => 'ok', 'opciones' => $opciones]);
    }

    /** Modo de detalle, assets del proveedor y enlace «Tablero» para la página. */
    private function prepararDetalle(KanbanProviderInterface $proveedor): void
    {
        $pedido = KanbanDetalle::modoValido((string) $this->request->query('detalle', ''));
        $this->detallePedido = $pedido ?? '';
        $this->modoDetalle = KanbanDetalle::modo($proveedor, $pedido);

        if ($this->modoDetalle === KanbanDetalleInterface::DETALLE_MODAL) {
            $this->detalleAssets = KanbanDetalle::assets($proveedor);
        }

        $parametros = ['board' => $proveedor->key()] + $this->filtros;
        if ($this->ordenEnUrl) {
            $parametros[KanbanOrdenableInterface::PARAM_ORDEN] = $this->orden;
            $parametros[KanbanOrdenableInterface::PARAM_DIR] = $this->dirOrden;
        }
        if ($this->detallePedido !== '') {
            $parametros['detalle'] = $this->detallePedido;
        }

        $this->urlTablero = $this->url() . '?' . http_build_query($parametros);
    }

    /** Clave del tablero pedido, solo [a-z0-9-]. */
    private function claveTablero(): string
    {
        $clave = (string) $this->request->inputOrQuery('board', self::TABLERO_POR_DEFECTO);
        $clave = mb_strtolower(trim($clave));

        return preg_match('/^[a-z0-9-]{1,60}$/', $clave) === 1 ? $clave : self::TABLERO_POR_DEFECTO;
    }

    private function atenderAjax(string $accion): void
    {
        if ($this->provider === null) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-disponible')], 403);
            return;
        }

        if (false === $this->validateFormToken()) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-token-caducado')], 403);
            return;
        }

        // la carcasa de detalle solo existe en los tableros que la implementan
        $proveedorDetalle = $this->provider instanceof KanbanDetalleInterface ? $this->provider : null;
        if ($proveedorDetalle === null && in_array($accion, self::ACCIONES_DETALLE, true)) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-disponible')], 403);
            return;
        }

        try {
            switch ($accion) {
                case self::ACCION_TARJETAS:
                    $this->ajaxTarjetas($this->provider);
                    return;

                case self::ACCION_DETALLE:
                    $this->ajaxDetalle($proveedorDetalle);
                    return;

                case self::ACCION_GUARDAR_DETALLE:
                    $this->ajaxGuardarDetalle($proveedorDetalle);
                    return;

                case self::ACCION_TARJETA:
                    $this->ajaxTarjeta($proveedorDetalle);
                    return;
            }

            $this->ajaxMover($this->provider);
        } catch (Throwable $excepcion) {
            Tools::log('Auralis')->error('Kanban: ' . $accion . ': ' . $excepcion->getMessage());
            $this->responder(['status' => 'error', 'message' => Tools::trans(self::mensajeErrorAccion($accion))], 500);
        }
    }

    /** Mensaje genérico (sin detalle técnico) para una excepción en cada acción. */
    private static function mensajeErrorAccion(string $accion): string
    {
        switch ($accion) {
            case self::ACCION_TARJETAS:
            case self::ACCION_TARJETA:
                return 'auralis-kanban-error-carga';

            case self::ACCION_DETALLE:
                return 'auralis-kanban-detalle-error';

            case self::ACCION_GUARDAR_DETALLE:
                return 'record-save-error';
        }

        return 'auralis-kanban-error-guardar';
    }

    /** Cuerpo del modal de detalle (Twig de la carcasa) y su cabecera. */
    private function ajaxDetalle(KanbanDetalleInterface $proveedor): void
    {
        $id = $this->idPedido();
        $detalle = $id === '' ? null : KanbanDetalle::normalizar($proveedor->detalle($id, $this->user));
        if ($detalle === null) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-encontrado')]);
            return;
        }

        $puedeEditar = KanbanAcceso::puedeMover($proveedor, $this->user);

        try {
            $html = Html::render(self::PLANTILLA_DETALLE, ['detalle' => $detalle, 'puedeEditar' => $puedeEditar]);
        } catch (Throwable $excepcion) {
            // p. ej. la plantilla del consumidor no existe o falla
            Tools::log('Auralis')->error('Kanban: detalle de ' . $proveedor->key() . ': ' . $excepcion->getMessage());
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-detalle-error')]);
            return;
        }

        $this->responder([
            'status' => 'ok',
            'titulo' => $detalle['titulo'],
            'subtitulo' => $detalle['subtitulo'],
            'url' => $detalle['url'],
            'tamano' => $detalle['tamano'],
            'editable' => $puedeEditar && KanbanDetalle::hayEditables($detalle),
            'html' => $html,
        ]);
    }

    /**
     * Guarda el detalle. La lista de campos permitidos se recalcula aquí con detalle():
     * nunca se usan los nombres que mande el cliente por sí solos.
     */
    private function ajaxGuardarDetalle(KanbanDetalleInterface $proveedor): void
    {
        if (false === KanbanAcceso::puedeMover($proveedor, $this->user)) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-sin-permiso')]);
            return;
        }

        $id = $this->idPedido();
        $detalle = $id === '' ? null : KanbanDetalle::normalizar($proveedor->detalle($id, $this->user));
        if ($detalle === null) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-encontrado')]);
            return;
        }

        // los campos llegan agrupados en campos[nombre] (los filtros van sueltos en el POST)
        $campos = $this->request->request->all()['campos'] ?? [];
        [$valores, $errores] = KanbanDetalle::filtrarEntrada($detalle, is_array($campos) ? $campos : []);
        if (false === empty($errores)) {
            $this->responder([
                'status' => 'error',
                'message' => implode(' ', $errores),
                'errores' => (object) $errores,
            ]);
            return;
        }

        $resultado = $proveedor->guardarDetalle($id, $valores, $this->user);
        if (false === $resultado->isOk()) {
            $this->responder(['status' => 'error', 'message' => $resultado->message]);
            return;
        }

        $this->responder($this->conRecuentos($proveedor, [
            'status' => 'ok',
            'message' => $resultado->message === '' ? Tools::trans('auralis-kanban-guardado') : $resultado->message,
            'card' => $resultado->card,
        ]));
    }

    /** Una tarjeta para repintar (p. ej. tras guardar el consumidor por su cuenta). */
    private function ajaxTarjeta(KanbanDetalleInterface $proveedor): void
    {
        $id = $this->idPedido();
        $tarjeta = $id === '' ? null : $proveedor->tarjeta($id, $this->user);
        if ($tarjeta === null) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-no-encontrado')]);
            return;
        }

        $this->responder($this->conRecuentos($proveedor, ['status' => 'ok', 'card' => $tarjeta]));
    }

    /** Id de tarjeta del POST, recortado. */
    private function idPedido(): string
    {
        return mb_substr(trim((string) $this->request->input('id', '')), 0, 64);
    }

    /**
     * Añade los recuentos con los filtros del POST. La operación ya está hecha:
     * si fallan los recuentos se responde ok sin ellos.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function conRecuentos(KanbanProviderInterface $proveedor, array $payload): array
    {
        try {
            $filtros = KanbanAcceso::filtros($proveedor, $this->request->request->all());
            $payload['counts'] = $this->recuentos($proveedor, $filtros);
        } catch (Throwable $excepcion) {
            Tools::log('Auralis')->error('Kanban: recuentos: ' . $excepcion->getMessage());
        }

        return $payload;
    }

    /** Carga inicial (column vacío) o siguiente página de una columna. */
    private function ajaxTarjetas(KanbanProviderInterface $proveedor): void
    {
        // filtros + criterio de orden, los dos de lista cerrada ('orden' y 'dir' son nombres reservados)
        $entrada = $this->request->request->all();
        $filtros = array_merge(KanbanAcceso::filtros($proveedor, $entrada), KanbanAcceso::orden($proveedor, $entrada));
        $columna = trim((string) $this->request->input('column', ''));
        $offset = max(0, (int) $this->request->input('offset', 0));
        $limite = (int) $this->request->input('limit', self::LIMITE_POR_DEFECTO);
        $limite = $limite < 1 ? self::LIMITE_POR_DEFECTO : min($limite, self::LIMITE_MAXIMO);

        $columnas = $proveedor->columns($this->user);

        if ($columna !== '') {
            if (false === in_array($columna, array_column($columnas, 'id'), true)) {
                $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-error-carga')], 400);
                return;
            }

            [$tarjetas, $hayMas] = $this->paginaTarjetas($proveedor, $columna, $filtros, $offset, $limite);
            $this->responder([
                'status' => 'ok',
                'column' => $columna,
                'cards' => $tarjetas,
                'hasMore' => $hayMas,
            ]);
            return;
        }

        $totales = $proveedor->countByColumn($filtros, $this->user);
        $salida = [];
        foreach ($columnas as $datos) {
            $total = $totales[$datos['id']] ?? ['count' => 0, 'total' => 0.0];
            $cantidad = (int) ($total['count'] ?? 0);

            // Las columnas inactivas solo se muestran si todavía contienen documentos.
            if (empty($datos['active']) && $cantidad === 0) {
                continue;
            }

            $tarjetas = [];
            $hayMas = false;
            if ($cantidad > 0) {
                [$tarjetas, $hayMas] = $this->paginaTarjetas($proveedor, (string) $datos['id'], $filtros, 0, $limite);
            }

            $salida[] = $datos + [
                'count' => $cantidad,
                'total' => (float) ($total['total'] ?? 0.0),
                'totalLabel' => Tools::money((float) ($total['total'] ?? 0.0)),
                'lockedCount' => (int) ($total['locked'] ?? 0),
                'cards' => $tarjetas,
                'hasMore' => $hayMas,
            ];
        }

        $this->responder([
            'status' => 'ok',
            'board' => [
                'key' => $proveedor->key(),
                'title' => $proveedor->title(),
                'listUrl' => $proveedor->listUrl(),
                'canMove' => KanbanAcceso::puedeMover($proveedor, $this->user),
            ],
            'columns' => $salida,
        ]);
    }

    private function ajaxMover(KanbanProviderInterface $proveedor): void
    {
        if (false === KanbanAcceso::puedeMover($proveedor, $this->user)) {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-sin-permiso')]);
            return;
        }

        $filtros = KanbanAcceso::filtros($proveedor, $this->request->request->all());
        $id = mb_substr(trim((string) $this->request->input('id', '')), 0, 64);
        $destino = mb_substr(trim((string) $this->request->input('to', '')), 0, 64);
        $posicion = (int) $this->request->input('position', 0);
        $confirmado = filter_var($this->request->input('confirm', '0'), FILTER_VALIDATE_BOOLEAN);

        if ($id === '' || $destino === '') {
            $this->responder(['status' => 'error', 'message' => Tools::trans('auralis-kanban-destino-no-valido')]);
            return;
        }

        $resultado = $proveedor->move($id, $destino, $posicion, $this->user, $confirmado);
        $payload = $resultado->toArray();

        // el movimiento ya está guardado: si fallan los recuentos se responde ok sin ellos
        if ($resultado->isOk()) {
            try {
                $payload['counts'] = $this->recuentos($proveedor, $filtros);
            } catch (Throwable $excepcion) {
                Tools::log('Auralis')->error('Kanban: recuentos tras mover: ' . $excepcion->getMessage());
            }
        }

        $this->responder($payload);
    }

    /**
     * Una página de tarjetas pidiendo una de más para saber si quedan.
     *
     * @param array<string, string> $filtros
     * @return array{0: array<int, array>, 1: bool}
     */
    private function paginaTarjetas(KanbanProviderInterface $proveedor, string $columna, array $filtros, int $offset, int $limite): array
    {
        $tarjetas = $proveedor->cards($columna, $filtros, $offset, $limite + 1, $this->user);
        $hayMas = count($tarjetas) > $limite;

        return [array_slice($tarjetas, 0, $limite), $hayMas];
    }

    /**
     * Recuento e importe de todas las columnas, con claves de texto para que el JSON sea siempre un objeto.
     *
     * @param array<string, string> $filtros
     */
    private function recuentos(KanbanProviderInterface $proveedor, array $filtros): object
    {
        $recuentos = [];
        foreach ($proveedor->countByColumn($filtros, $this->user) as $id => $datos) {
            $importe = (float) ($datos['total'] ?? 0.0);
            $recuentos[(string) $id] = [
                'count' => (int) ($datos['count'] ?? 0),
                'total' => $importe,
                'totalLabel' => Tools::money($importe),
                'lockedCount' => (int) ($datos['locked'] ?? 0),
            ];
        }

        return (object) $recuentos;
    }

    /**
     * Respuesta JSON con el token renovado. Response::json() envía en el acto,
     * así que el código HTTP se fija antes.
     *
     * @param array<string, mixed> $payload
     */
    private function responder(array $payload, int $estado = 200): void
    {
        $this->response()->setHttpCode($estado);
        CalendarAjaxResponse::json($this->response(), $payload, $estado);
    }
}

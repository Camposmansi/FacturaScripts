<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Proveedor Kanban de los 4 tableros de documentos del Core (presupuestos y pedidos de cliente y de proveedor): una columna por estado y cambio de estado con las reglas del Core.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban\Providers;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\DataSrc\Agentes;
use FacturaScripts\Core\DataSrc\EstadosDocumentos;
use FacturaScripts\Core\DataSrc\Series;
use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Lib\ControllerPermissions;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanAcceso;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanCriterioOrdenInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanDetalleInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanEtiquetaRegistry;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanFiltrosInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanOrdenableInterface;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanOrdenOrigen;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanOrdenRegistry;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanPropiedad;
use FacturaScripts\Plugins\Auralis\Lib\Kanban\KanbanResultadoMover;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use InvalidArgumentException;
use Throwable;

/**
 * Un objeto por tablero de DocumentoKanbanTipos (presupuestos y pedidos de cliente y de proveedor).
 * El constructor no consulta la BD: se instancia en cada petición desde Init::init(). Ofrece el
 * detalle en modal (KanbanDetalleInterface), pero por defecto abre la ficha, la barra de filtros
 * con vuelta al listado del Core (KanbanFiltrosInterface) y varios criterios de orden
 * (KanbanOrdenableInterface: fecha, importe y, en ventas, validez, más los que otros plugins
 * registran en KanbanOrdenRegistry). Otros plugins también añaden etiquetas a las tarjetas
 * (KanbanEtiquetaRegistry), pedidas en lote solo para las tarjetas ya visibles.
 *
 * Ventas: cliente, agente y numero2. Compras: proveedor, nombre y numproveedor, sin agente (y
 * «solo sus datos» por nick, como el Core). Sin reglas de facturas: no hay tablero de facturas.
 * Un estado que genera otro documento no es destino; un documento no editable con documentos hijos
 * sale con candado. El cambio es el mismo que hace la ficha del Core (idestado + save() en transacción).
 */
final class DocumentoKanbanProvider implements KanbanDetalleInterface, KanbanFiltrosInterface, KanbanOrdenableInterface
{
    /** Criterios de orden propios (KanbanOrdenableInterface). */
    public const ORDEN_FECHA = 'fecha';
    public const ORDEN_IMPORTE = 'importe';
    public const ORDEN_VALIDEZ = 'validez';

    /** Alias de la columna auxiliar «sin valor» (nulos al final en cualquier dirección). */
    private const ALIAS_NULO = 'zk_orden_nulo';

    /** Alias de la columna auxiliar con el valor de un criterio de otro plugin. */
    private const ALIAS_VALOR = 'zk_orden_valor';

    /** Longitud máxima del texto que un criterio de otro plugin pinta en la tarjeta. */
    private const MAX_TEXTO_ORDEN = 60;

    /**
     * Etiquetas como mucho por tarjeta en la respuesta (las de otros plugins y la serie). La tarjeta
     * pinta 2 y deja el resto en el tooltip de «+N»; el tope solo acota el tamaño del JSON.
     */
    private const MAX_ETIQUETAS_TARJETA = 8;

    /** Tamaño máximo de página de tarjetas. */
    private const LIMITE_MAXIMO = 50;

    /** Longitud de numero2 (ventas) y numproveedor (compras): character varying(50). */
    private const MAX_REFERENCIA = 50;

    /** Tope que la carcasa admite en un textarea; observaciones es de tipo text. */
    private const MAX_OBSERVACIONES = 16000;

    /** Niveles de MiniLog que se devuelven al usuario si save() falla. */
    private const NIVELES_AVISO = ['warning', 'error', 'critical'];

    /** Máximo de opciones del buscador de terceros de la barra de filtros. */
    private const MAX_OPCIONES_BUSCAR = 15;

    /** Días antes de la fecha límite en los que la tarjeta avisa (ámbar). */
    private const DIAS_AVISO_VALIDEZ = 7;

    /**
     * Tipo de columna por palabras del nombre del estado (en este orden), para el color
     * por defecto y para saber qué columnas están «abiertas». Si ninguna encaja, decide la estructura.
     */
    private const TIPOS_POR_NOMBRE = [
        'facturar' => ['factur'],
        'pedido' => ['pedido'],
        'caducado' => ['caduc', 'vencid', 'expir'],
        'rechazado' => ['rechaz', 'anulad', 'cancelad', 'perdid'],
        'abierto' => ['abiert', 'pendient', 'borrador', 'nuevo'],
    ];

    /** Clave del tablero (DocumentoKanbanTipos). */
    private string $clave;

    /** @var array<string, mixed> definición del tablero */
    private array $tipo;

    /** Nombre corto del modelo: tipodoc de los estados y model1 de doctransformations. */
    private string $tipoDoc;

    /** @var class-string<TransformerDocument> clase Dinamic del documento */
    private string $clase;

    /** @var array<string, string>|null codagente => nombre (carga perezosa, una vez) */
    private ?array $agentes = null;

    /** @var array<string, string>|null codserie => descripción (carga perezosa, una vez) */
    private ?array $series = null;

    /** @var array<string, array>|null criterios de orden de otros plugins, validados (carga perezosa, una vez) */
    private ?array $criteriosExternos = null;

    public function __construct(string $clave)
    {
        $tipo = DocumentoKanbanTipos::definicion($clave);
        if ($tipo === null) {
            throw new InvalidArgumentException('Tablero de documentos desconocido: ' . $clave);
        }

        $this->clave = $clave;
        $this->tipo = $tipo;
        $this->tipoDoc = DocumentoKanbanTipos::tipoDoc($clave);
        $this->clase = $tipo['modelo'];
    }

    /**
     * Un proveedor por cada tablero de DocumentoKanbanTipos (sin BD).
     *
     * @return self[]
     */
    public static function todos(): array
    {
        $proveedores = [];
        foreach (DocumentoKanbanTipos::claves() as $clave) {
            $proveedores[] = new self($clave);
        }

        return $proveedores;
    }

    public function key(): string
    {
        return $this->clave;
    }

    public function title(): string
    {
        return Tools::trans($this->tipo['titulo']);
    }

    public function readPermission(): string
    {
        return 'List' . $this->tipoDoc;
    }

    public function editPermission(): string
    {
        return 'Edit' . $this->tipoDoc;
    }

    public function listUrl(): string
    {
        return 'List' . $this->tipoDoc;
    }

    /** Ventas: cliente y agente. Compras: proveedor (los documentos de compra no tienen agente). */
    public function allowedFilters(): array
    {
        $filtros = ['idempresa' => 'int', 'codserie' => 'string', $this->campoTercero() => 'string'];
        if ($this->esVenta()) {
            $filtros['codagente'] = 'string';
        }

        return $filtros + ['desde' => 'date', 'hasta' => 'date'];
    }

    public function columns(User $user): array
    {
        $columnas = [];
        foreach (EstadosDocumentos::byTipoDoc($this->tipoDoc) as $estado) {
            $genera = false === empty($estado->generadoc);
            $columnas[] = [
                'id' => (string) $estado->idestado,
                'title' => (string) Tools::fixHtml((string) $estado->nombre),
                'color' => (string) ($estado->color ?? ''),
                'icon' => $estado->icon(),
                // ningún destino pide confirmación (el tablero nunca genera documentos)
                'confirm' => false,
                // un estado que genera otro documento nunca es destino: el documento se crea desde la ficha
                'allowDrop' => (bool) $estado->activo && false === $genera,
                'generatesDoc' => $genera,
                'active' => (bool) $estado->activo,
                'tipo' => $this->tipoEstado($estado),
            ];
        }

        return $columnas;
    }

    public function cards(string $columnId, array $filters, int $offset, int $limit, User $user): array
    {
        $estado = $this->estado($columnId);
        if ($estado === null) {
            return [];
        }

        $where = $this->whereLectura($filters, $user);
        if ($where === null) {
            return [];
        }

        $where[] = Where::eq('idestado', (int) $estado->idestado);
        $offset = max(0, $offset);
        $limit = min(self::LIMITE_MAXIMO, max(1, $limit));

        // criterio de la lista cerrada (lo que no esté en opcionesOrden() cae al de por defecto)
        $orden = KanbanAcceso::orden($this, $filters);
        $clave = $orden[KanbanOrdenableInterface::PARAM_ORDEN] ?? self::ORDEN_FECHA;
        $dir = $orden[KanbanOrdenableInterface::PARAM_DIR] ?? KanbanOrdenableInterface::DESC;
        try {
            $docs = $this->documentosOrdenados($where, $clave, $dir, $offset, $limit);
        } catch (Throwable $e) {
            // un criterio de otro plugin no rompe el tablero: queda en el log y se usa el orden por defecto
            Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': orden ' . $clave . ': ' . $e->getMessage());
            $docs = $this->documentosOrdenados($where, self::ORDEN_FECHA, KanbanOrdenableInterface::DESC, $offset, $limit);
        }
        $bloqueados = $this->bloqueados($docs);

        $tarjetas = [];
        foreach ($docs as $doc) {
            $tarjetas[] = $this->cardData($doc, $bloqueados[(int) $doc->id()] ?? false);
        }

        return $this->conDatosExternos($tarjetas, $docs);
    }

    /**
     * Fecha y importe en todos; validez solo si el tipo tiene fecha límite (ventas).
     * Fecha e importe, de mayor a menor; validez, la más próxima primero (sin fecha, al final).
     * Después, los criterios que otros plugins registran para este tablero (KanbanOrdenRegistry).
     */
    public function opcionesOrden(): array
    {
        $opciones = [
            ['clave' => self::ORDEN_FECHA, 'etiqueta' => 'auralis-kanban-orden-fecha', 'dir' => KanbanOrdenableInterface::DESC],
            ['clave' => self::ORDEN_IMPORTE, 'etiqueta' => 'auralis-kanban-orden-importe', 'dir' => KanbanOrdenableInterface::DESC],
        ];
        if ($this->tipo['fechaLimite'] !== null) {
            $opciones[] = ['clave' => self::ORDEN_VALIDEZ, 'etiqueta' => 'auralis-kanban-orden-validez', 'dir' => KanbanOrdenableInterface::ASC];
        }

        foreach ($this->criteriosExternos() as $clave => $externo) {
            $opciones[] = ['clave' => $clave, 'etiqueta' => $externo['etiqueta'], 'dir' => $externo['dir']];
        }

        return $opciones;
    }

    public function countByColumn(array $filters, User $user): array
    {
        $where = $this->whereLectura($filters, $user);
        if ($where === null) {
            return [];
        }

        $resultado = [];
        foreach (EstadosDocumentos::byTipoDoc($this->tipoDoc) as $estado) {
            $resultado[(string) $estado->idestado] = ['count' => 0, 'total' => 0.0, 'locked' => 0];
        }

        // Recuento, importe y bloqueados de todas las columnas en UNA consulta agrupada.
        // El SQL es fijo (tabla, clave y tipo salen de la tabla de tipos): los valores de usuario van solo en $where.
        $clase = $this->clase;
        $filas = DbQuery::table($clase::tableName())
            ->selectRaw('idestado, COUNT(*) AS num, SUM(total) AS suma, '
                . 'SUM(CASE WHEN ' . $this->sqlBloqueado() . ' THEN 1 ELSE 0 END) AS bloqueados')
            ->where($where)
            ->groupBy('idestado')
            ->get();

        foreach ($filas as $fila) {
            $clave = (string) (int) $fila['idestado'];
            if (false === isset($resultado[$clave])) {
                continue;
            }

            $resultado[$clave] = [
                'count' => (int) $fila['num'],
                'total' => round((float) $fila['suma'], 2),
                'locked' => (int) $fila['bloqueados'],
            ];
        }

        return $resultado;
    }

    public function cardData(ModelClass $model, bool $locked = false): array
    {
        if (false === $model instanceof $this->clase) {
            return [];
        }

        $id = (int) $model->id();
        $total = (float) $model->total;
        $editable = (bool) $model->editable;

        $fechaIso = null;
        $fechaEtiqueta = null;
        $campoLimite = $this->tipo['fechaLimite'];
        if ($campoLimite !== null && false === empty($model->{$campoLimite})) {
            $marca = strtotime((string) $model->{$campoLimite});
            if ($marca !== false) {
                $fechaIso = date('Y-m-d', $marca);
                $fechaEtiqueta = Tools::date($fechaIso);
            }
        }

        // fecha del documento (ISO): criterio de orden por defecto (fecha desc, id desc)
        $marcaDoc = empty($model->fecha) ? false : strtotime((string) $model->fecha);
        $fechaDoc = $marcaDoc === false ? null : date('Y-m-d', $marcaDoc);

        // valor de cada criterio de orden, igual que lo ordena el SQL: número sin redondear y fechas ISO
        $valoresOrden = [self::ORDEN_FECHA => $fechaDoc, self::ORDEN_IMPORTE => $total];
        if ($campoLimite !== null) {
            $valoresOrden[self::ORDEN_VALIDEZ] = $fechaIso;
        }

        $referencia = trim((string) Tools::fixHtml((string) ($model->{$this->campoReferencia()} ?? '')));
        $titulo = trim((string) Tools::fixHtml((string) ($model->{$this->campoNombreTercero()} ?? '')));
        $propietario = $this->nombreAgente((string) ($model->codagente ?? ''));

        return [
            'id' => (string) $id,
            'code' => (string) Tools::fixHtml((string) $model->codigo),
            'title' => $titulo === '' ? null : $titulo,
            'subtitle' => $referencia === ''
                ? null
                : Tools::trans('auralis-kanban-su-referencia', ['%referencia%' => $referencia]),
            'keyValue' => Tools::money($total, (string) ($model->coddivisa ?? '')),
            'keyValueRaw' => round($total, 2),
            'fecha' => $fechaDoc,
            'date' => $fechaIso,
            'dateLabel' => $fechaEtiqueta,
            'overdue' => $fechaIso !== null && $fechaIso < date('Y-m-d') && $editable,
            // fecha límite en menos de DIAS_AVISO_VALIDEZ días (aún no vencida)
            'dueSoon' => $fechaIso !== null && $editable && $fechaIso >= date('Y-m-d')
                && $fechaIso < date('Y-m-d', strtotime('+' . self::DIAS_AVISO_VALIDEZ . ' days')),
            'owner' => $propietario,
            'ownerInitials' => $propietario === null ? null : self::iniciales($propietario),
            'tags' => $this->etiquetas($model),
            'url' => $this->editPermission() . '?code=' . $id,
            'locked' => $locked,
            'priority' => null,
            'column' => (string) (int) $model->idestado,
            'sortValues' => $valoresOrden,
        ];
    }

    /** $confirmed no cambia nada: ningún destino pide confirmación y confirm=1 no abre los estados que generan documento. */
    public function move(string $id, string $toColumn, int $position, User $user, bool $confirmed = false): KanbanResultadoMover
    {
        try {
            return $this->mover($id, $toColumn, $user);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': error al mover ' . mb_substr($id, 0, 20) . ': ' . $e->getMessage());
            return KanbanResultadoMover::error(Tools::trans('auralis-kanban-error-guardar'));
        }
    }

    public function modoDetallePorDefecto(): string
    {
        return KanbanDetalleInterface::DETALLE_PAGINA;
    }

    public function detalle(string $id, User $user): ?array
    {
        $doc = $this->documentoLegible($id, $user);
        if ($doc === null) {
            return null;
        }

        // editable en el documento Y el usuario puede editarlo (allowUpdate, «solo sus datos» y empresa de la página de edición)
        $editable = (bool) $doc->editable && $this->puedeEditarDocumento($doc, $user);
        $observaciones = (string) Tools::fixHtml((string) ($doc->observaciones ?? ''));
        // un valor guardado más largo que el tope de la carcasa se recortaría al guardar: no se deja editar
        $observacionesEditable = $editable && mb_strlen($observaciones) <= self::MAX_OBSERVACIONES;
        $fecha = self::fechaIso($doc->fecha);
        $estado = $this->estado((string) (int) $doc->idestado);
        $codigo = (string) Tools::fixHtml((string) $doc->codigo);
        $tercero = trim((string) Tools::fixHtml((string) ($doc->{$this->campoNombreTercero()} ?? '')));

        $campos = [
            [
                'nombre' => $this->campoNombreTercero(),
                'etiqueta' => Tools::trans($this->esVenta() ? 'customer' : 'supplier'),
                'tipo' => 'solo-lectura',
                'valor' => $tercero,
                'ancho' => 6,
            ],
            [
                'nombre' => 'fecha',
                'etiqueta' => Tools::trans('date'),
                'tipo' => 'solo-lectura',
                'valor' => $fecha === null ? '' : Tools::date($fecha),
                'ancho' => 6,
            ],
            [
                'nombre' => 'total',
                'etiqueta' => Tools::trans('total'),
                'tipo' => 'solo-lectura',
                'valor' => Tools::money((float) $doc->total, (string) ($doc->coddivisa ?? '')),
                'ancho' => 6,
            ],
        ];

        // los documentos de compra no tienen agente
        if ($this->esVenta()) {
            $campos[] = [
                'nombre' => 'codagente',
                'etiqueta' => Tools::trans('agent'),
                'tipo' => 'solo-lectura',
                'valor' => $this->nombreAgente((string) ($doc->codagente ?? '')) ?? '',
                'ancho' => 6,
            ];
        }

        $campos[] = [
            'nombre' => $this->campoReferencia(),
            'etiqueta' => Tools::trans($this->esVenta() ? 'number2' : 'numsupplier'),
            'tipo' => 'texto',
            'valor' => (string) Tools::fixHtml((string) ($doc->{$this->campoReferencia()} ?? '')),
            'editable' => $editable,
            'maxlength' => self::MAX_REFERENCIA,
            'ancho' => 6,
        ];

        $campoLimite = $this->tipo['fechaLimite'];
        if ($campoLimite !== null) {
            $campos[] = [
                'nombre' => $campoLimite,
                'etiqueta' => Tools::trans('expiration'),
                'tipo' => 'fecha',
                'valor' => self::fechaIso($doc->{$campoLimite}) ?? '',
                'editable' => $editable,
                'ancho' => 6,
            ];
        }

        $campos[] = [
            'nombre' => 'observaciones',
            'etiqueta' => Tools::trans('observations'),
            'tipo' => 'textarea',
            'valor' => $observaciones,
            'editable' => $observacionesEditable,
            'maxlength' => self::MAX_OBSERVACIONES,
            'ancho' => 12,
        ];

        return [
            'titulo' => $tercero === '' ? $codigo : $codigo . ' · ' . $tercero,
            'subtitulo' => $estado === null ? null : (string) Tools::fixHtml((string) $estado->nombre),
            'url' => $this->editPermission() . '?code=' . (int) $doc->id(),
            'tamano' => 'lg',
            'campos' => $campos,
        ];
    }

    public function guardarDetalle(string $id, array $valores, User $user): KanbanResultadoMover
    {
        try {
            return $this->guardarCampos($id, $valores, $user);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': error al guardar el detalle de ' . mb_substr($id, 0, 20) . ': ' . $e->getMessage());
            return KanbanResultadoMover::error(Tools::trans('record-save-error'));
        }
    }

    public function tarjeta(string $id, User $user): ?array
    {
        $doc = $this->documentoLegible($id, $user);

        return $doc === null ? null : $this->tarjetaDe($doc);
    }

    public function detalleAssets(): array
    {
        return [];
    }

    public function barraFiltros(array $filtros, User $user): array
    {
        $campoTercero = $this->campoTercero();
        $controles = [[
            'nombre' => $campoTercero,
            'etiqueta' => Tools::trans($this->esVenta() ? 'customer' : 'supplier'),
            'tipo' => KanbanFiltrosInterface::FILTRO_BUSCAR,
            'texto' => $this->nombreTerceroVisible((string) ($filtros[$campoTercero] ?? ''), $user),
        ]];

        // Como el listado del Core: sin filtro de agente si el usuario solo ve sus datos
        // o si no hay más de un agente (y nunca en compras); sin filtro de serie si solo hay una.
        if ($this->esVenta()) {
            $permisos = new ControllerPermissions($user, $this->readPermission());
            $agentes = [];
            foreach (Agentes::all() as $agente) {
                $agentes[(string) $agente->codagente] = trim((string) Tools::fixHtml((string) $agente->nombre));
            }
            if (false === $permisos->onlyOwnerData && count($agentes) > 1) {
                $controles[] = [
                    'nombre' => 'codagente',
                    'etiqueta' => Tools::trans('agent'),
                    'tipo' => KanbanFiltrosInterface::FILTRO_SELECT,
                    'opciones' => $agentes,
                ];
            }
        }

        $series = [];
        foreach (Series::all() as $serie) {
            $series[(string) $serie->codserie] = trim((string) Tools::fixHtml((string) $serie->descripcion));
        }
        if (count($series) > 1) {
            $controles[] = [
                'nombre' => 'codserie',
                'etiqueta' => Tools::trans('series'),
                'tipo' => KanbanFiltrosInterface::FILTRO_SELECT,
                'opciones' => $series,
            ];
        }

        $controles[] = ['nombre' => 'desde', 'etiqueta' => Tools::trans('from-date'), 'tipo' => KanbanFiltrosInterface::FILTRO_FECHA];
        $controles[] = ['nombre' => 'hasta', 'etiqueta' => Tools::trans('until-date'), 'tipo' => KanbanFiltrosInterface::FILTRO_FECHA];

        return $controles;
    }

    public function buscarFiltro(string $nombre, string $texto, User $user): array
    {
        $campoTercero = $this->campoTercero();
        if ($nombre !== $campoTercero) {
            return [];
        }

        // Solo terceros de documentos que el usuario puede ver (empresa y «solo sus datos»).
        $where = $this->whereLectura([], $user);
        if ($where === null) {
            return [];
        }

        $campoNombre = $this->campoNombreTercero();

        // sin comodines del usuario: Where::like() ya envuelve con %...%
        $texto = trim(str_replace(['%', '_'], ' ', mb_substr($texto, 0, 60)));
        if ($texto !== '') {
            $where[] = Where::sub([
                Where::like($campoNombre, $texto),
                Where::orLike($campoTercero, $texto),
                Where::orLike('cifnif', $texto),
            ]);
        }

        $clase = $this->clase;
        $filas = DbQuery::table($clase::tableName())
            ->select($campoTercero . ', ' . $campoNombre)
            ->where($where)
            ->groupBy($campoTercero . ', ' . $campoNombre)
            ->orderBy($campoNombre)
            ->limit(self::MAX_OPCIONES_BUSCAR * 2)
            ->get();

        // un tercero puede tener varios nombres en sus documentos: el primero por orden alfabético
        $opciones = [];
        foreach ($filas as $fila) {
            $codigo = (string) $fila[$campoTercero];
            if ($codigo === '' || isset($opciones[$codigo])) {
                continue;
            }

            $opciones[$codigo] = ['value' => $codigo, 'text' => trim((string) Tools::fixHtml((string) $fila[$campoNombre]))];
            if (count($opciones) >= self::MAX_OPCIONES_BUSCAR) {
                break;
            }
        }

        return array_values($opciones);
    }

    public function filtrosListado(array $filtros): array
    {
        // nombres de los filtros de ListBusinessDocument: 'filter' . clave; el periodo usa startdate/enddate en d-m-Y
        $campos = [];
        foreach ($this->filtrosDirectos() as $clave) {
            if (isset($filtros[$clave]) && $filtros[$clave] !== '') {
                $campos['filter' . $clave] = (string) $filtros[$clave];
            }
        }

        foreach (['desde' => 'filterstartdate', 'hasta' => 'filterenddate'] as $clave => $campo) {
            $marca = isset($filtros[$clave]) ? strtotime((string) $filtros[$clave]) : false;
            if ($marca !== false) {
                $campos[$campo] = date('d-m-Y', $marca);
            }
        }

        return $campos;
    }

    private function esVenta(): bool
    {
        return (bool) $this->tipo['ventas'];
    }

    /** Código del tercero: codcliente en ventas, codproveedor en compras. */
    private function campoTercero(): string
    {
        return $this->esVenta() ? 'codcliente' : 'codproveedor';
    }

    /** Nombre del tercero guardado en el documento: nombrecliente en ventas, nombre en compras. */
    private function campoNombreTercero(): string
    {
        return $this->esVenta() ? 'nombrecliente' : 'nombre';
    }

    /** Referencia del tercero: numero2 en ventas, numproveedor en compras. */
    private function campoReferencia(): string
    {
        return $this->esVenta() ? 'numero2' : 'numproveedor';
    }

    /**
     * Campos que el detalle deja editar (los únicos que guardarDetalle() asigna): referencia,
     * fecha límite si el tipo la tiene y observaciones.
     *
     * @return array<string, string> campo => 'texto' | 'fecha'
     */
    private function camposEditables(): array
    {
        $campos = [$this->campoReferencia() => 'texto'];
        if ($this->tipo['fechaLimite'] !== null) {
            $campos[$this->tipo['fechaLimite']] = 'fecha';
        }

        return $campos + ['observaciones' => 'texto'];
    }

    /** Filtros que se aplican con igualdad sobre su columna (además de empresa y fechas). */
    private function filtrosDirectos(): array
    {
        return array_values(array_diff(array_keys($this->allowedFilters()), ['desde', 'hasta']));
    }

    /** Nombre del tercero filtrado, tomado de un documento visible para el usuario; '' si no hay. */
    private function nombreTerceroVisible(string $codigo, User $user): string
    {
        if ($codigo === '') {
            return '';
        }

        $where = $this->whereLectura([], $user);
        if ($where === null) {
            return '';
        }

        $campoNombre = $this->campoNombreTercero();
        $where[] = Where::eq($this->campoTercero(), $codigo);
        $clase = $this->clase;
        $fila = DbQuery::table($clase::tableName())
            ->select($campoNombre)
            ->where($where)
            ->orderBy($campoNombre)
            ->first();

        return trim((string) Tools::fixHtml((string) ($fila[$campoNombre] ?? '')));
    }

    /**
     * Tipo de la columna (abierto, pedido, facturar, caducado, rechazado u otro): primero por el
     * nombre del estado; si no encaja, por lo que genera y por si es editable.
     */
    private function tipoEstado(EstadoDocumento $estado): string
    {
        $nombre = mb_strtolower((string) Tools::fixHtml((string) $estado->nombre));
        foreach (self::TIPOS_POR_NOMBRE as $tipo => $palabras) {
            foreach ($palabras as $palabra) {
                if (str_contains($nombre, $palabra)) {
                    return $tipo;
                }
            }
        }

        $genera = (string) ($estado->generadoc ?? '');
        if (str_starts_with($genera, 'Pedido')) {
            return 'pedido';
        }
        if (str_starts_with($genera, 'Albaran') || str_starts_with($genera, 'Factura')) {
            return 'facturar';
        }
        if ($genera === '' && (bool) $estado->editable) {
            return 'abierto';
        }
        if ($genera === '' && false === (bool) $estado->editable) {
            return 'rechazado';
        }

        return 'otro';
    }

    private function guardarCampos(string $id, array $valores, User $user): KanbanResultadoMover
    {
        // pasos 1-4 de move(): documento, permiso de edición, «solo sus datos» y empresa
        $doc = $this->documentoParaEditar($id, $user);
        if ($doc instanceof KanbanResultadoMover) {
            return $doc;
        }

        // solo los campos del detalle de este tipo, y solo si llegan
        // (ya filtrados y con la longitud validada por KanbanDetalle::filtrarEntrada)
        $hayCambios = false;
        foreach ($this->camposEditables() as $campo => $tipo) {
            if (false === array_key_exists($campo, $valores)) {
                continue;
            }

            if ($tipo === 'fecha') {
                $nuevo = $valores[$campo] === null ? null : (string) $valores[$campo];
                if ($nuevo !== self::fechaIso($doc->{$campo})) {
                    $doc->{$campo} = $nuevo;
                    $hayCambios = true;
                }
                continue;
            }

            $nuevo = (string) $valores[$campo];
            if (self::textoDistinto($nuevo, (string) ($doc->{$campo} ?? ''))) {
                $doc->{$campo} = $nuevo;
                $hayCambios = true;
            }
        }

        // nada que guardar: no es un «guardado» ni se llama a save()
        if (false === $hayCambios) {
            return KanbanResultadoMover::ok($this->tarjetaDe($doc), Tools::trans('auralis-kanban-sin-cambios'));
        }

        $error = $this->guardarEnTransaccion($doc, 'record-save-error');
        if ($error !== null) {
            return KanbanResultadoMover::error($error);
        }

        $clase = $this->clase;
        $recargado = $clase::find((int) $doc->id()) ?? $doc;

        return KanbanResultadoMover::ok($this->tarjetaDe($recargado), Tools::trans('auralis-kanban-guardado'));
    }

    private function mover(string $id, string $toColumn, User $user): KanbanResultadoMover
    {
        // 1-4. documento, permiso de edición, «solo sus datos» y empresa
        $doc = $this->documentoParaEditar($id, $user);
        if ($doc instanceof KanbanResultadoMover) {
            return $doc;
        }

        $idDoc = (int) $doc->id();

        // 5. destino: estado activo de este tipo de documento
        $destino = $this->estado($toColumn);
        if ($destino === null || false === (bool) $destino->activo) {
            return KanbanResultadoMover::error(Tools::trans('auralis-kanban-destino-no-valido'));
        }

        // 5b. un estado que genera otro documento no se alcanza desde el tablero, tampoco con confirm=1:
        // se rechaza sin llamar a save() y se devuelve la ficha para hacerlo desde ella
        if (false === empty($destino->generadoc)) {
            return KanbanResultadoMover::error(
                Tools::trans('auralis-kanban-genera-desde-ficha'),
                $this->editPermission() . '?code=' . $idDoc
            );
        }

        $nombreDestino = (string) Tools::fixHtml((string) $destino->nombre);
        $bloqueado = $this->bloqueados([$doc])[$idDoc] ?? false;

        // 6. mismo estado: nada que guardar
        if ((int) $destino->idestado === (int) $doc->idestado) {
            return KanbanResultadoMover::ok(
                $this->tarjetaDe($doc, $bloqueado),
                Tools::trans('auralis-kanban-movido', ['%estado%' => $nombreDestino])
            );
        }

        // 7. bloqueado: no editable con hijos (CommonSalesPurchases::idestado())
        if ($bloqueado) {
            return KanbanResultadoMover::error(Tools::trans('auralis-kanban-bloqueado'));
        }

        // 8. cambio de estado por el ORM (el destino nunca genera documentos: ya se rechazó en 5b)
        $doc->idestado = (int) $destino->idestado;

        // 9. fallo: avisos que dejó el Core durante el guardado
        $error = $this->guardarEnTransaccion($doc, 'auralis-kanban-error-guardar');
        if ($error !== null) {
            return KanbanResultadoMover::error($error);
        }

        // 10. tarjeta recargada con su bloqueo recalculado
        $clase = $this->clase;
        $recargado = $clase::find($idDoc) ?? $doc;

        return KanbanResultadoMover::ok(
            $this->tarjetaDe($recargado),
            Tools::trans('auralis-kanban-movido', ['%estado%' => $nombreDestino])
        );
    }

    /**
     * Pasos 1-4 comunes a mover y guardar el detalle: el documento existe, el usuario puede
     * editar (allowUpdate), es suyo si tiene «solo sus datos» y su empresa es accesible.
     * Devuelve el documento o el error para el usuario (el mismo si no existe que si es ajeno).
     */
    private function documentoParaEditar(string $id, User $user): TransformerDocument|KanbanResultadoMover
    {
        // 1. documento: inexistente y ajeno dan el MISMO mensaje, para no revelar si el id existe
        $doc = $this->documento($id);
        if ($doc === null || false === $this->puedeEditarDocumento($doc, $user)) {
            return KanbanResultadoMover::error(Tools::trans('auralis-kanban-sin-permiso'));
        }

        return $doc;
    }

    /** Documento de este tipo por su id, o null (también si el id no es un número). */
    private function documento(string $id): ?TransformerDocument
    {
        $idDoc = self::idDocumento($id);
        if ($idDoc <= 0) {
            return null;
        }

        $clase = $this->clase;

        return $clase::find($idDoc);
    }

    /**
     * El usuario puede editar ESTE documento: allowUpdate de la página de edición, «solo sus datos»
     * de esa página y empresa. No mira si el documento es editable (eso lo decide su estado).
     */
    private function puedeEditarDocumento(TransformerDocument $doc, User $user): bool
    {
        $permisos = new ControllerPermissions($user, $this->editPermission());
        if (false === $permisos->allowUpdate) {
            return false;
        }

        if ($permisos->onlyOwnerData && false === KanbanPropiedad::esPropietario($doc, $user)) {
            return false;
        }

        return AuralisSecurity::canAccessEmpresa($user, $doc->idempresa);
    }

    /**
     * Documento que el usuario puede VER, con los mismos filtros que cards(): acceso a la
     * página de lectura, «solo sus datos» de esa página y empresa. Null si no.
     */
    private function documentoLegible(string $id, User $user): ?TransformerDocument
    {
        $permisos = new ControllerPermissions($user, $this->readPermission());
        if (false === $permisos->allowAccess) {
            return null;
        }

        $doc = $this->documento($id);
        if ($doc === null) {
            return null;
        }

        if ($permisos->onlyOwnerData && false === KanbanPropiedad::esPropietario($doc, $user)) {
            return null;
        }

        return AuralisSecurity::canAccessEmpresa($user, $doc->idempresa) ? $doc : null;
    }

    /**
     * Tarjeta de un documento con su bloqueo calculado (formato de cardData(), con 'column') y los
     * valores de orden de otros plugins.
     */
    private function tarjetaDe(TransformerDocument $doc, ?bool $bloqueado = null): array
    {
        $bloqueado ??= $this->bloqueados([$doc])[(int) $doc->id()] ?? false;

        return $this->conDatosExternos([$this->cardData($doc, $bloqueado)], [$doc])[0];
    }

    /**
     * save() del modelo en transacción, como SalesController::saveStatusAction().
     * Null si guarda; si no, el texto para el usuario con los avisos que dejó el Core
     * (MiniLog, ya traducidos) o el genérico ($claveError). Una excepción se relanza tras el rollback.
     */
    private function guardarEnTransaccion(TransformerDocument $doc, string $claveError): ?string
    {
        $huella = self::huellaLog();
        $db = new DataBase();
        $transaccion = false === $db->inTransaction() && $db->beginTransaction();

        try {
            $guardado = $doc->save();
        } catch (Throwable $e) {
            if ($transaccion) {
                $db->rollback();
            }
            throw $e;
        }

        if (false === $guardado) {
            if ($transaccion) {
                $db->rollback();
            }

            $avisos = self::avisosNuevos($huella);
            return $avisos === '' ? Tools::trans($claveError) : $avisos;
        }

        if ($transaccion) {
            $db->commit();
        }

        return null;
    }

    /**
     * El texto nuevo difiere del guardado. Se compara como lo ve el formulario (Tools::fixHtml)
     * y con saltos de línea normalizados a LF (los navegadores envían CRLF en los textarea).
     */
    private static function textoDistinto(string $nuevo, string $guardado): bool
    {
        $normalizar = static fn (string $texto): string => str_replace("\r\n", "\n", trim($texto));

        return $normalizar($nuevo) !== $normalizar((string) Tools::fixHtml($guardado));
    }

    /** Id numérico del documento (0 si el texto no lo es). */
    private static function idDocumento(string $id): int
    {
        $id = trim($id);

        return preg_match('/^\d{1,10}\z/', $id) === 1 ? (int) $id : 0;
    }

    /** Fecha en Y-m-d de un valor del modelo, o null si está vacío o no es una fecha. */
    private static function fechaIso($valor): ?string
    {
        if (empty($valor)) {
            return null;
        }

        $marca = strtotime((string) $valor);

        return $marca === false ? null : date('Y-m-d', $marca);
    }

    /**
     * Filtros comunes de lectura (empresa, filtros saneados y «solo sus datos»).
     * Null si el usuario no tiene acceso a la página de lectura.
     *
     * @param array<string, mixed> $filters
     * @return Where[]|null
     */
    private function whereLectura(array $filters, User $user): ?array
    {
        $permisos = new ControllerPermissions($user, $this->readPermission());
        if (false === $permisos->allowAccess) {
            return null;
        }

        $filters = KanbanAcceso::filtros($this, $filters);
        $clase = $this->clase;
        $where = $permisos->onlyOwnerData ? KanbanPropiedad::filtro(new $clase(), $user) : [];

        $empresa = AuralisSecurity::resolveCodEmpresa($user, $filters['idempresa'] ?? '');
        if ($empresa !== '') {
            $where[] = Where::eq('idempresa', (int) $empresa);
        }

        foreach ($this->filtrosDirectos() as $campo) {
            if ($campo !== 'idempresa' && isset($filters[$campo]) && $filters[$campo] !== '') {
                $where[] = Where::eq($campo, $filters[$campo]);
            }
        }

        if (isset($filters['desde'])) {
            $where[] = Where::gte('fecha', $filters['desde']);
        }

        if (isset($filters['hasta'])) {
            $where[] = Where::lte('fecha', $filters['hasta']);
        }

        return $where;
    }

    /** Estado de este tipo de documento por su id (columna), o null. */
    private function estado(string $columnId): ?EstadoDocumento
    {
        $columnId = trim($columnId);
        if (preg_match('/^\d{1,10}\z/', $columnId) !== 1) {
            return null;
        }

        foreach (EstadosDocumentos::byTipoDoc($this->tipoDoc) as $estado) {
            if ((int) $estado->idestado === (int) $columnId) {
                return $estado;
            }
        }

        return null;
    }

    /**
     * Una página de documentos de la columna con el criterio ya validado (KanbanAcceso::orden()).
     * El SQL es fijo: columnas de la tabla de tipos, dirección 'ASC' | 'DESC' y alias propios;
     * los valores del usuario van solo en $where. Los documentos sin valor van al final en las
     * dos direcciones, y el desempate es siempre la clave primaria DESC (paginación estable).
     *
     * @param Where[] $where
     * @return TransformerDocument[]
     */
    private function documentosOrdenados(array $where, string $orden, string $dir, int $offset, int $limit): array
    {
        $clase = $this->clase;
        $tabla = $clase::tableName();
        $direccion = $dir === KanbanOrdenableInterface::ASC ? 'ASC' : 'DESC';
        $consulta = DbQuery::table($tabla)->where($where);
        $campoLimite = $this->tipo['fechaLimite'];

        $externos = $this->criteriosExternos();

        if ($orden === self::ORDEN_IMPORTE) {
            // total nunca es nulo (por defecto 0)
            $consulta->orderBy('total', $direccion);
        } elseif (isset($externos[$orden])) {
            // criterio de otro plugin: expresión montada por KanbanOrdenOrigen (sin SQL de fuera, sin JOIN)
            $db = new DataBase();
            $expresion = KanbanOrdenOrigen::expresion($externos[$orden]['origen'], $tabla, $clase::primaryColumn());
            $consulta->selectRaw($db->escapeColumn($tabla) . '.*, ' . $expresion . ' AS ' . self::ALIAS_VALOR
                . ', CASE WHEN ' . $expresion . ' IS NULL THEN 1 ELSE 0 END AS ' . self::ALIAS_NULO)
                ->orderBy(self::ALIAS_NULO, 'ASC')
                ->orderBy(self::ALIAS_VALOR, $direccion);
        } elseif ($orden === self::ORDEN_VALIDEZ && $campoLimite !== null) {
            $db = new DataBase();
            $consulta->selectRaw($db->escapeColumn($tabla) . '.*, CASE WHEN ' . $db->escapeColumn($campoLimite)
                . ' IS NULL THEN 1 ELSE 0 END AS ' . self::ALIAS_NULO)
                ->orderBy(self::ALIAS_NULO, 'ASC')
                ->orderBy($campoLimite, $direccion);
        } else {
            // fecha es NOT NULL en los documentos del Core
            $consulta->orderBy('fecha', $direccion);
        }

        $filas = $consulta->orderBy($clase::primaryColumn(), 'DESC')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $docs = [];
        foreach ($filas as $fila) {
            unset($fila[self::ALIAS_NULO], $fila[self::ALIAS_VALOR]);
            $docs[] = new $clase($fila);
        }

        return $docs;
    }

    /**
     * Criterios de otros plugins para este tablero, ya validados (una vez por petición). Se descarta
     * (con aviso en el log) el que use una clave propia, dé una dirección no válida, declare un origen
     * que no cumple KanbanOrdenOrigen::validar() o lance una excepción.
     *
     * @return array<string, array{criterio: KanbanCriterioOrdenInterface, origen: array, etiqueta: string, dir: string}>
     */
    private function criteriosExternos(): array
    {
        if ($this->criteriosExternos !== null) {
            return $this->criteriosExternos;
        }

        $this->criteriosExternos = [];
        $clase = $this->clase;
        foreach (KanbanOrdenRegistry::paraTablero($this->clave) as $clave => $criterio) {
            if (in_array($clave, [self::ORDEN_FECHA, self::ORDEN_IMPORTE, self::ORDEN_VALIDEZ], true)) {
                Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': el criterio de orden «' . $clave . '» usa una clave reservada');
                continue;
            }

            try {
                $dir = strtolower(trim($criterio->direccionPorDefecto()));
                if (false === in_array($dir, [KanbanOrdenableInterface::ASC, KanbanOrdenableInterface::DESC], true)) {
                    throw new InvalidArgumentException('dirección no válida');
                }

                $this->criteriosExternos[$clave] = [
                    'criterio' => $criterio,
                    'origen' => KanbanOrdenOrigen::validar($criterio->origen($this->clave), $clase::tableName()),
                    'etiqueta' => $criterio->etiqueta(),
                    'dir' => $dir,
                ];
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': criterio de orden «' . $clave . '» descartado: ' . $e->getMessage());
            }
        }

        return $this->criteriosExternos;
    }

    /**
     * Añade a las tarjetas las etiquetas de otros plugins (un lote por página) y el valor de cada
     * criterio de otro plugin (sortValues, una consulta por criterio y página) con su texto opcional
     * (sortLabels). Si un criterio falla, sus valores quedan en null (aviso en el log) y la tarjeta
     * sale igual.
     *
     * @param array<int, array> $tarjetas en el mismo orden que $docs
     * @param TransformerDocument[] $docs
     * @return array<int, array>
     */
    private function conDatosExternos(array $tarjetas, array $docs): array
    {
        $externos = $this->criteriosExternos();
        foreach ($tarjetas as $i => $tarjeta) {
            $tarjetas[$i]['sortLabels'] = [];
        }
        $tarjetas = $this->conEtiquetasExternas($tarjetas);
        if (empty($externos) || empty($tarjetas)) {
            return $tarjetas;
        }

        foreach ($externos as $clave => $externo) {
            try {
                $valores = KanbanOrdenOrigen::valores($externo['origen'], $docs);
            } catch (Throwable $e) {
                Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': valores del orden «' . $clave . '»: ' . $e->getMessage());
                $valores = [];
            }

            foreach ($tarjetas as $i => $tarjeta) {
                $valor = $valores[(int) $tarjeta['id']] ?? null;
                $tarjetas[$i]['sortValues'][$clave] = $valor;
                $texto = $this->textoExterno($externo['criterio'], $clave, $valor);
                if ($texto !== null) {
                    $tarjetas[$i]['sortLabels'][$clave] = $texto;
                }
            }
        }

        return $tarjetas;
    }

    /**
     * Etiquetas de otros plugins (KanbanEtiquetaRegistry) delante de la propia (la serie), en UNA
     * petición por proveedor para todas las tarjetas. Las tarjetas ya pasaron los filtros de lectura
     * (o de edición, al mover): el lote solo lleva ids que el usuario puede ver.
     *
     * @param array<int, array> $tarjetas
     * @return array<int, array>
     */
    private function conEtiquetasExternas(array $tarjetas): array
    {
        $ids = [];
        foreach ($tarjetas as $tarjeta) {
            if (isset($tarjeta['id'])) {
                $ids[] = (int) $tarjeta['id'];
            }
        }

        $externas = empty($ids) ? [] : KanbanEtiquetaRegistry::etiquetas($this->clave, $ids);
        if (empty($externas)) {
            return $tarjetas;
        }

        foreach ($tarjetas as $i => $tarjeta) {
            $deOtrosPlugins = $externas[(int) ($tarjeta['id'] ?? 0)] ?? [];
            if (false === empty($deOtrosPlugins)) {
                $tarjetas[$i]['tags'] = array_slice(array_merge($deOtrosPlugins, $tarjeta['tags'] ?? []), 0, self::MAX_ETIQUETAS_TARJETA);
            }
        }

        return $tarjetas;
    }

    /**
     * Texto de la tarjeta que da el criterio, como texto plano corto (el JS lo pinta con textContent).
     *
     * @param float|string|null $valor
     */
    private function textoExterno(KanbanCriterioOrdenInterface $criterio, string $clave, $valor): ?string
    {
        try {
            $texto = $criterio->textoTarjeta($this->clave, $valor);
        } catch (Throwable $e) {
            Tools::log('Auralis')->warning('Kanban ' . $this->clave . ': texto del orden «' . $clave . '»: ' . $e->getMessage());
            return null;
        }

        $texto = $texto === null ? '' : trim(strip_tags($texto));

        return $texto === '' ? null : mb_substr($texto, 0, self::MAX_TEXTO_ORDEN);
    }

    /**
     * Condición SQL de «bloqueado» para el recuento agrupado (misma regla que bloqueados()).
     * Solo constantes de la tabla de tipos: ningún valor de usuario.
     */
    private function sqlBloqueado(): string
    {
        $clase = $this->clase;
        $tabla = $clase::tableName();

        return "editable = false AND EXISTS (SELECT 1 FROM doctransformations dt WHERE dt.model1 = '"
            . $this->tipoDoc . "' AND dt.iddoc1 = " . $tabla . '.' . $clase::primaryColumn() . ')';
    }

    /**
     * Bloqueo en lote con UNA consulta como mucho: no editable Y con documentos hijos (doctransformations).
     *
     * @param TransformerDocument[] $docs
     * @return array<int, bool> id => bloqueado
     */
    private function bloqueados(array $docs): array
    {
        $resultado = [];
        $noEditables = [];
        foreach ($docs as $doc) {
            $idDoc = (int) $doc->id();
            $resultado[$idDoc] = false;
            if (false === (bool) $doc->editable) {
                $noEditables[] = $idDoc;
            }
        }

        if (empty($noEditables)) {
            return $resultado;
        }

        $filas = DbQuery::table('doctransformations')
            ->select('iddoc1')
            ->whereEq('model1', $this->tipoDoc)
            ->whereIn('iddoc1', $noEditables)
            ->groupBy('iddoc1')
            ->get();

        foreach ($filas as $fila) {
            $resultado[(int) $fila['iddoc1']] = true;
        }

        return $resultado;
    }

    /** Nombre del agente desde la DataSrc (sin consulta por tarjeta), o null. */
    private function nombreAgente(string $codagente): ?string
    {
        if ($codagente === '') {
            return null;
        }

        if ($this->agentes === null) {
            $this->agentes = [];
            foreach (Agentes::all() as $agente) {
                $this->agentes[(string) $agente->codagente] = trim((string) Tools::fixHtml((string) $agente->nombre));
            }
        }

        $nombre = $this->agentes[$codagente] ?? '';

        return $nombre === '' ? null : $nombre;
    }

    /**
     * Etiqueta propia de la tarjeta: la serie, solo si no es la serie por defecto. Las de otros
     * plugins se añaden delante en conEtiquetasExternas().
     *
     * @return array<int, array{label: string, color: string}>
     */
    private function etiquetas(ModelClass $doc): array
    {
        $codserie = (string) ($doc->codserie ?? '');
        if ($codserie === '' || $codserie === (string) Tools::settings('default', 'codserie', '')) {
            return [];
        }

        if ($this->series === null) {
            $this->series = [];
            foreach (Series::all() as $serie) {
                $this->series[(string) $serie->codserie] = trim((string) Tools::fixHtml((string) $serie->descripcion));
            }
        }

        $etiqueta = $this->series[$codserie] ?? '';

        return [['label' => $etiqueta === '' ? $codserie : $etiqueta, 'color' => '']];
    }

    /** Iniciales en mayúscula: primera letra de la primera y de la última palabra. */
    private static function iniciales(string $nombre): string
    {
        $palabras = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($palabras)) {
            return '';
        }

        if (count($palabras) === 1) {
            return mb_strtoupper(mb_substr($palabras[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($palabras[0], 0, 1) . mb_substr($palabras[count($palabras) - 1], 0, 1));
    }

    /**
     * Recuento de avisos del canal por defecto antes de guardar. MiniLog agrupa los
     * mensajes repetidos subiendo su contador, así que se compara por contador.
     *
     * @return array<string, int> nivel|mensaje => veces
     */
    private static function huellaLog(): array
    {
        $huella = [];
        foreach (MiniLog::read(MiniLog::DEFAULT_CHANNEL, self::NIVELES_AVISO) as $item) {
            $clave = $item['level'] . '|' . $item['message'];
            $huella[$clave] = ($huella[$clave] ?? 0) + (int) $item['count'];
        }

        return $huella;
    }

    /**
     * Mensajes (ya traducidos) que aparecieron durante el guardado.
     *
     * @param array<string, int> $antes
     */
    private static function avisosNuevos(array $antes): string
    {
        $mensajes = [];
        foreach (self::huellaLog() as $clave => $veces) {
            if ($veces <= ($antes[$clave] ?? 0)) {
                continue;
            }

            $mensaje = trim(explode('|', $clave, 2)[1] ?? '');
            if ($mensaje !== '' && false === in_array($mensaje, $mensajes, true)) {
                $mensajes[] = $mensaje;
            }
        }

        return mb_substr(implode(' ', $mensajes), 0, 500);
    }
}

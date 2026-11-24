<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Controlador para la pantalla del Dashboard de Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use DateTime;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\Html;
use FacturaScripts\Dinamic\Model\Agente;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\Auralis\Lib\CalendarMonthGrid;
use FacturaScripts\Plugins\Auralis\Lib\ReceiptsDiffFilter;
use FacturaScripts\Plugins\Auralis\Lib\SaldoCajaBancos;
use FacturaScripts\Plugins\Auralis\Lib\AuralisSecurity;
use Throwable;

class DashBoardAuralis extends Controller
{
    // Properties accessible from Twig
    public string $rango = 'this-month';
    public string $desde = '';
    public string $hasta = '';
    public bool $vsAnterior = false;
    public string $evolutionDocument = 'facturas';
    public string $evolutionGranularity = 'auto';
    public string $evolutionMetric = 'neto';
    public string $evolutionChart = 'line';

    // KPI Formatted Values
    public string $ventasFormated = '0,00 €';
    public string $gastosFormated = '0,00 €';
    public string $margenFormated = '0,00 €';
    public string $liquidezFormated = '0,00 €';
    public string $impuestosFormated = '0,00 €';
    public string $runway30Formated = '0,00 €';
    public string $liquidezPeriodoFormated = '0,00 €';
    public string $liquidezFuturaFormated = '0,00 €';
    public string $liquidezVencidaFormated = '0,00 €';
    public string $rangoAnteriorFormated = '';

    // KPI raw values
    public float $ventas = 0.0;
    public float $gastos = 0.0;
    public float $margen = 0.0;
    public float $liquidez = 0.0;
    public float $impuestos = 0.0;
    public float $runway30 = 0.0;
    public float $margenPct = 0.0;
    public float $liquidezPeriodo = 0.0;
    public float $liquidezFutura = 0.0;
    public float $liquidezVencida = 0.0;

    // KPI trends (vs anterior)
    public float $ventasTrendPct = 0.0;
    public float $gastosTrendPct = 0.0;
    public float $margenTrendPct = 0.0;
    public string $ventasTrendClass = 'auralis-trend-neutral';
    public string $gastosTrendClass = 'auralis-trend-neutral';
    public string $margenTrendClass = 'auralis-trend-neutral';
    public string $ventasTrendSign = '';
    public string $gastosTrendSign = '';
    public string $margenTrendSign = '';

    public bool $hasPurchases = false;
    public bool $hasClientRiskAlert = false;
    public array $topClients = [];
    public array $upcomingReceipts = [];

    // RECIBOS VENCIDOS Y PRÓXIMOS
    public array $recibosVencidosVentas = [];
    public array $recibosProximosVentas = [];
    public array $recibosVencidosCompras = [];
    public array $recibosProximosCompras = [];

    // RESUMEN KPI RECIBOS
    public float $recibosVentasVencidosTotal = 0.0;
    public string $recibosVentasVencidosTotalFormatted = '0,00 €';
    public int $recibosVentasVencidosCount = 0;

    public float $recibosVentasProximosTotal = 0.0;
    public string $recibosVentasProximosTotalFormatted = '0,00 €';
    public int $recibosVentasProximosCount = 0;

    public float $recibosComprasVencidosTotal = 0.0;
    public string $recibosComprasVencidosTotalFormatted = '0,00 €';
    public int $recibosComprasVencidosCount = 0;

    public float $recibosComprasProximosTotal = 0.0;
    public string $recibosComprasProximosTotalFormatted = '0,00 €';
    public int $recibosComprasProximosCount = 0;

    /** @var array<int, array<string, mixed>> Facturas de venta con descuadre recibos vs total */
    public array $facturasDescuadreVentas = [];

    /** @var array<int, array<string, mixed>> Facturas de compra con descuadre recibos vs total */
    public array $facturasDescuadreCompras = [];

    public int $facturasDescuadreCount = 0;
    public bool $hasReceiptsDiffAlert = false;

    // FILTRO DE MULTIEMPRESA
    public array $empresas = [];
    public string $codEmpresa = '';
    public bool $hasMultiEmpresa = false;

    // LISTADO DE USUARIOS PARA ASIGNACIÓN EN CALENDARIO
    public array $usersList = [];

    // LISTADOS PARA AGENTE / SALA / CATÁLOGOS EN CALENDARIO
    public array $agentesList = [];
    public array $salasList = [];
    public array $eventTypesList = [];
    public array $eventStatusesList = [];
    public array $almacenesList = [];

    // EMBEDDED DATA FOR JS
    public string $dashDataJson = '';

    /** @var array<string, mixed> Widget zAnticipos (si el plugin está activo) */
    public array $anticiposWidget = [];

    // KPIs ciclo cobro/pago
    public ?float $kpiCicloCobroVentas = null;
    public string $kpiCicloCobroVentasFormatted = '—';
    public string $kpiCicloCobroVentasCmpFormatted = '';
    public ?float $kpiRetrasoCobroVentas = null;
    public string $kpiRetrasoCobroVentasFormatted = '—';
    public string $kpiRetrasoCobroVentasCmpFormatted = '';
    public string $kpiRetrasoCobroVentasPctAtrasoFormatted = '';
    public ?float $kpiRatioCobradoVentas = null;
    public string $kpiRatioCobradoVentasFormatted = '—';
    public string $kpiRatioCobradoVentasCmpFormatted = '';
    public string $kpiRatioCobradoVentasImportesFormatted = '';
    public ?float $kpiCicloPagoCompras = null;
    public string $kpiCicloPagoComprasFormatted = '—';
    public string $kpiCicloPagoComprasCmpFormatted = '';
    public ?float $kpiRetrasoPagoCompras = null;
    public string $kpiRetrasoPagoComprasFormatted = '—';
    public string $kpiRetrasoPagoComprasCmpFormatted = '';
    public string $kpiRetrasoPagoComprasPctAtrasoFormatted = '';
    public ?float $kpiRatioPagadoCompras = null;
    public string $kpiRatioPagadoComprasFormatted = '—';
    public string $kpiRatioPagadoComprasCmpFormatted = '';
    public string $kpiRatioPagadoComprasImportesFormatted = '';

    /** @var array<int, array<string, mixed>> Ranking clientes (cobros) */
    public array $rankingPagadoresClientes = [];

    /** @var array<int, array<string, mixed>> Ranking proveedores (pagos) */
    public array $rankingPagadoresProveedores = [];

    /** Rango efectivo usado en KPIs de ciclo (puede ampliarse a YTD si el periodo no tiene muestra). */
    public string $kpiCicloDesde = '';
    public string $kpiCicloHasta = '';
    public bool $kpiCicloRangoAmpliado = false;
    public bool $kpiCmpIsPriorYear = false;
    public string $kpiCmpLabel = '';

    /** @var list<callable(string, string, DataBase): string> */
    private static array $treasuryCycleInvoiceExcludes = [];

    /**
     * Registra un filtro SQL opcional sobre facturas en KPIs de ciclo (p. ej. zAnticipos).
     * El callable recibe ($facturaAlias, $facturasTable, $db) y debe devolver '' o un fragmento " AND ...".
     */
    public static function addTreasuryCycleInvoiceExclude(callable $fn): void
    {
        self::$treasuryCycleInvoiceExcludes[] = $fn;
    }

    /** @var list<callable(): array> */
    private static array $treasuryReceiptPaymentExcludes = [];

    /**
     * Registra formas de pago cuyos recibos no son cobros ni pagos reales (p. ej. compensación de zAnticipos).
     * El callable no recibe argumentos y devuelve una lista de codpago. Se excluyen de los KPI de ciclo,
     * ratio y ranking, y de los recibos vencidos / próximos.
     */
    public static function addTreasuryReceiptPaymentExclude(callable $fn): void
    {
        self::$treasuryReceiptPaymentExcludes[] = $fn;
    }

    /**
     * @inheritDoc
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['submenu'] = 'dashboards-auralis';
        $data['title'] = 'general-dashboard';
        $data['icon'] = 'fa-solid fa-chart-line';
        $data['showonmenu'] = true;
        return $data;
    }

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        parent::run();

        // Página de inicio (patrón nativo Base\Controller::privateCore)
        $this->handleDefaultPage();

        // 1. Check AJAX / GET / POST action
        $action = $this->request()->query('action') ?: (string)$this->request()->input('action');
        if ($action === 'receipts') {
            $this->ajaxReceipts();
            return;
        }

        // 2. Load MultiEmpresa (sin listas de usuarios/agentes/salas: el dashboard es receipts, no crea eventos)
        $this->loadEmpresas();

        // 3. Parse Date Range
        $this->parseDateRange();

        // 4. Load Dashboard Data & Receipts
        $this->loadDashboardData();
        $this->loadReceiptsLists();
        $this->loadReceiptsDiffAlert();
        $this->loadTreasuryCycleKpis();
        $this->loadTreasuryPayerRanking();
        $this->loadAnticiposWidget();

        // 5. Render Template
        $this->view('@PluginAuralis/DashBoardAuralis.html.twig');
    }

    /**
     * Widget resumen de anticipos (plugin zAnticipos opcional).
     */
    private function loadAnticiposWidget(): void
    {
        $this->anticiposWidget = [];
        if (false === Plugins::isEnabled('zAnticipos')) {
            return;
        }

        // El nombre pertenece a la API histórica del plugin opcional zAnticipos.
        $widgetClass = 'FacturaScripts\\Plugins\\zAnticipos\\Lib\\' . 'Zol' . 'tekaDashboardWidget';
        if (!class_exists($widgetClass)) {
            return;
        }

        $this->anticiposWidget = $widgetClass::getSummary();
    }

    /**
     * Marca o desmarca esta página como homepage del usuario (patrón Core Base\Controller).
     */
    private function handleDefaultPage(): void
    {
        if (null === $this->user) {
            return;
        }

        $defaultPage = (string) $this->request()->query('defaultPage', '');
        if ($defaultPage !== 'TRUE' && $defaultPage !== 'FALSE') {
            return;
        }

        $cookiesExpire = time() + (int) Tools::config('cookies_expire');
        if ($defaultPage === 'TRUE') {
            $this->user->homepage = $this->getClassName();
        } else {
            $this->user->homepage = null;
        }

        $this->response()->cookie('fsHomepage', $this->user->homepage, $cookiesExpire);
        $this->user->save();
    }

    private function parseDateRange(): void
    {
        $this->rango = $this->request()->query('rango') ?: 'this-month';
        $this->vsAnterior = $this->request()->query('vs_anterior') === '1';
        $this->evolutionDocument = in_array($this->request()->query('evo_doc'), ['facturas', 'albaranes'], true)
            ? (string)$this->request()->query('evo_doc') : 'facturas';
        $this->evolutionGranularity = in_array($this->request()->query('evo_granularity'), ['auto', 'day', 'week', 'month', 'year'], true)
            ? (string)$this->request()->query('evo_granularity') : 'auto';
        $this->evolutionMetric = in_array($this->request()->query('evo_metric'), ['neto', 'total'], true)
            ? (string)$this->request()->query('evo_metric') : 'neto';
        $this->evolutionChart = in_array($this->request()->query('evo_chart'), ['line', 'bar'], true)
            ? (string)$this->request()->query('evo_chart') : 'line';

        switch ($this->rango) {
            case 'last-7-days':
                $this->desde = date('Y-m-d', strtotime('-6 days'));
                $this->hasta = date('Y-m-d');
                break;
            case 'last-30-days':
                $this->desde = date('Y-m-d', strtotime('-29 days'));
                $this->hasta = date('Y-m-d');
                break;
            case 'last-6-months':
                $this->desde = date('Y-m-01', strtotime('-5 months'));
                $this->hasta = date('Y-m-d');
                break;
            case 'last-12-months':
                $this->desde = date('Y-m-01', strtotime('-11 months'));
                $this->hasta = date('Y-m-d');
                break;
            case 'last-3-years':
                $this->desde = ((int)date('Y') - 2) . '-01-01';
                $this->hasta = date('Y-m-d');
                break;
            case 'this-quarter':
                $quarter = (int)ceil((int)date('n') / 3);
                $this->desde = date('Y-m-d', mktime(0, 0, 0, ($quarter - 1) * 3 + 1, 1, (int)date('Y')));
                $this->hasta = date('Y-m-d', mktime(0, 0, 0, $quarter * 3, (int)date('t', mktime(0, 0, 0, $quarter * 3, 1, (int)date('Y'))), (int)date('Y')));
                break;
            case 'this-year':
                $this->desde = date('Y') . '-01-01';
                $this->hasta = date('Y') . '-12-31';
                break;
            case 'custom':
                $rawDesde = (string)$this->request()->query('desde', '');
                $rawHasta = (string)$this->request()->query('hasta', '');
                $this->desde = AuralisSecurity::sanitizeIsoDate($rawDesde) ?? date('Y-m-01');
                $this->hasta = AuralisSecurity::sanitizeIsoDate($rawHasta) ?? date('Y-m-t');
                if ($this->hasta < $this->desde) {
                    $this->hasta = $this->desde;
                }
                break;
            case 'this-month':
            default:
                $this->rango = 'this-month';
                $this->desde = date('Y-m-01');
                $this->hasta = date('Y-m-t');
                break;
        }
    }

    private function loadDashboardData(): void
    {
        // 1. Calculate Previous Period dates
        $start = new DateTime($this->desde);
        $end = new DateTime($this->hasta);
        $interval = $start->diff($end);
        $days = $interval->days + 1;

        $prevStartObj = (clone $start)->modify("-{$days} days");
        $prevEndObj = (clone $end)->modify("-{$days} days");
        $prevStart = $prevStartObj->format('Y-m-d');
        $prevEnd = $prevEndObj->format('Y-m-d');
        $this->rangoAnteriorFormated = $prevStartObj->format('d/m/Y') . ' - ' . $prevEndObj->format('d/m/Y');

        // 2. Fetch Sales Invoices KPIs
        $this->ventas = 0.0;
        $prevVentas = 0.0;
        $ivaRepercutido = 0.0;
        $irpfVentas = 0.0;

        try {
            if ($this->db()->tableExists('facturascli')) {
                // Current sales
                $qSales = DbQuery::table('facturascli')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qSales->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $this->ventas = (float)$qSales->sum('neto');

                // Taxes repercutidos
                $qIva = DbQuery::table('facturascli')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qIva->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $ivaRepercutido = (float)$qIva->sum('totaliva');

                $qIrpf = DbQuery::table('facturascli')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qIrpf->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $irpfVentas = (float)$qIrpf->sum('totalirpf');

                if ($this->vsAnterior) {
                    $qPrevSales = DbQuery::table('facturascli')->whereBetween('fecha', $prevStart, $prevEnd);
                    if (!empty($this->codEmpresa)) {
                        $qPrevSales->whereEq('idempresa', (int)$this->codEmpresa);
                    }
                    $prevVentas = (float)$qPrevSales->sum('neto');
                }
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando facturascli: ' . $e->getMessage());
        }

        $this->ventasFormated = Tools::money($this->ventas);

        // 3. Fetch Purchase Invoices KPIs
        $this->gastos = 0.0;
        $prevGastos = 0.0;
        $ivaSoportado = 0.0;
        $irpfCompras = 0.0;
        $this->hasPurchases = false;

        try {
            if ($this->db()->tableExists('facturasprov')) {
                // Check if any purchase invoice exists ever for this company
                $qCheckPurchases = DbQuery::table('facturasprov');
                if (!empty($this->codEmpresa)) {
                    $qCheckPurchases->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $this->hasPurchases = $qCheckPurchases->count() > 0;

                // Current purchases
                $qExpenses = DbQuery::table('facturasprov')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qExpenses->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $this->gastos = (float)$qExpenses->sum('neto');

                // Taxes soportados
                $qIvaProv = DbQuery::table('facturasprov')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qIvaProv->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $ivaSoportado = (float)$qIvaProv->sum('totaliva');

                $qIrpfProv = DbQuery::table('facturasprov')->whereBetween('fecha', $this->desde, $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qIrpfProv->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $irpfCompras = (float)$qIrpfProv->sum('totalirpf');

                if ($this->vsAnterior) {
                    $qPrevExpenses = DbQuery::table('facturasprov')->whereBetween('fecha', $prevStart, $prevEnd);
                    if (!empty($this->codEmpresa)) {
                        $qPrevExpenses->whereEq('idempresa', (int)$this->codEmpresa);
                    }
                    $prevGastos = (float)$qPrevExpenses->sum('neto');
                }
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando facturasprov: ' . $e->getMessage());
        }

        $this->gastosFormated = Tools::money($this->gastos);

        // 4. Net Margin KPIs
        $this->margen = $this->ventas - $this->gastos;
        $this->margenFormated = Tools::money($this->margen);
        $this->margenPct = $this->ventas > 0 ? ($this->margen / $this->ventas) * 100 : 0.0;

        $prevMargen = $prevVentas - $prevGastos;

        // 5. Trend Percentages
        if ($this->vsAnterior) {
            $this->ventasTrendPct = $this->calculatePercentageChange($this->ventas, $prevVentas);
            $this->ventasTrendSign = $this->ventasTrendPct >= 0 ? '+' : '';
            $this->ventasTrendClass = $this->ventasTrendPct >= 0 ? 'auralis-trend-up' : 'auralis-trend-down';

            // For expenses, decrease is good/green, increase is bad/red
            $this->gastosTrendPct = $this->calculatePercentageChange($this->gastos, $prevGastos);
            $this->gastosTrendSign = $this->gastosTrendPct >= 0 ? '+' : '';
            $this->gastosTrendClass = $this->gastosTrendPct <= 0 ? 'auralis-trend-up' : 'auralis-trend-down';

            $this->margenTrendPct = $this->calculatePercentageChange($this->margen, $prevMargen);
            $this->margenTrendSign = $this->margenTrendPct >= 0 ? '+' : '';
            $this->margenTrendClass = $this->margenTrendPct >= 0 ? 'auralis-trend-up' : 'auralis-trend-down';
        }

        // 6. Tax Estimation KPI
        $this->impuestos = ($ivaRepercutido - $ivaSoportado) + ($irpfCompras - $irpfVentas);
        $this->impuestosFormated = Tools::money($this->impuestos);

        // 7. Liquidez Real KPI (Bank balance + pending customer receipts - pending provider receipts)
        // Partidas 57 sin asientos de apertura ni cierre: cada movimiento cuenta una vez (ver Lib)
        $bankBalance = SaldoCajaBancos::calcular((int)$this->codEmpresa);

        // Pending receipts
        $pendingCustomer = 0.0;
        $pendingProvider = 0.0;

        try {
            if ($this->db()->tableExists('recibospagoscli')) {
                $qPendingCust = DbQuery::table('recibospagoscli')->whereEq('pagado', false);
                if (!empty($this->codEmpresa)) {
                    $qPendingCust->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $pendingCustomer = (float)$qPendingCust->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando recibospagoscli: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov')) {
                $qPendingProv = DbQuery::table('recibospagosprov')->whereEq('pagado', false);
                if (!empty($this->codEmpresa)) {
                    $qPendingProv->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $pendingProvider = (float)$qPendingProv->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando recibospagosprov: ' . $e->getMessage());
        }

        $today = date('Y-m-d');

        // 1. Previsto futuro en el periodo (vencimiento >= HOY y <= hasta)
        $cobrosFuturos = 0.0;
        $pagosFuturos = 0.0;
        $desdeFuturo = max($today, $this->desde);

        try {
            if ($this->db()->tableExists('recibospagoscli')) {
                $qCobrosFut = DbQuery::table('recibospagoscli')
                    ->whereEq('pagado', false)
                    ->whereGte('vencimiento', $desdeFuturo)
                    ->whereLte('vencimiento', $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qCobrosFut->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $cobrosFuturos = (float)$qCobrosFut->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando cobros futuros: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov')) {
                $qPagosFut = DbQuery::table('recibospagosprov')
                    ->whereEq('pagado', false)
                    ->whereGte('vencimiento', $desdeFuturo)
                    ->whereLte('vencimiento', $this->hasta);
                if (!empty($this->codEmpresa)) {
                    $qPagosFut->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $pagosFuturos = (float)$qPagosFut->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando pagos futuros: ' . $e->getMessage());
        }

        $this->liquidezFutura = $cobrosFuturos - $pagosFuturos;
        $this->liquidezFuturaFormated = ($this->liquidezFutura >= 0 ? '+' : '') . Tools::money($this->liquidezFutura);

        // 2. Vencido pendiente (impagados acumulados pasados: vencimiento < HOY)
        $cobrosVencidos = 0.0;
        $pagosVencidos = 0.0;

        try {
            if ($this->db()->tableExists('recibospagoscli')) {
                $qCobrosVenc = $this->applyReceiptExcludes(DbQuery::table('recibospagoscli')
                    ->whereEq('pagado', false)
                    ->whereLt('vencimiento', $today));
                if (!empty($this->codEmpresa)) {
                    $qCobrosVenc->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $cobrosVencidos = (float)$qCobrosVenc->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando cobros vencidos: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov')) {
                $qPagosVenc = $this->applyReceiptExcludes(DbQuery::table('recibospagosprov')
                    ->whereEq('pagado', false)
                    ->whereLt('vencimiento', $today));
                if (!empty($this->codEmpresa)) {
                    $qPagosVenc->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $pagosVencidos = (float)$qPagosVenc->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando pagos vencidos: ' . $e->getMessage());
        }

        $this->liquidezVencida = $cobrosVencidos - $pagosVencidos;
        $this->liquidezVencidaFormated = ($this->liquidezVencida >= 0 ? '+' : '') . Tools::money($this->liquidezVencida);

        // La liquidez real es exactamente el saldo disponible cobrado/pagado en caja y bancos (subcuentas del grupo 57)
        $this->liquidez = $bankBalance;
        $this->liquidezFormated = Tools::money($this->liquidez);

        // 8. 30-day Runway & Runway warning
        $cobros30 = 0.0;
        $pagos30 = 0.0;
        $endDate30 = date('Y-m-d', strtotime('+30 days'));

        try {
            if ($this->db()->tableExists('recibospagoscli')) {
                $qCobros30 = DbQuery::table('recibospagoscli')
                    ->whereEq('pagado', false)
                    ->whereLte('vencimiento', $endDate30);
                if (!empty($this->codEmpresa)) {
                    $qCobros30->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $cobros30 = (float)$qCobros30->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando cobros30: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov')) {
                $qPagos30 = DbQuery::table('recibospagosprov')
                    ->whereEq('pagado', false)
                    ->whereLte('vencimiento', $endDate30);
                if (!empty($this->codEmpresa)) {
                    $qPagos30->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $pagos30 = (float)$qPagos30->sum('importe');
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando pagos30: ' . $e->getMessage());
        }

        $this->runway30 = $this->liquidez + $cobros30 - $pagos30;
        $this->runway30Formated = Tools::money($this->runway30);

        // 9. Top 5 Clientes
        $this->topClients = [];
        $this->hasClientRiskAlert = false;

        try {
            if ($this->db()->tableExists('facturascli') && $this->ventas > 0) {
                $qTop = DbQuery::table('facturascli')
                    ->selectRaw('codcliente, nombrecliente, SUM(neto) AS total_neto')
                    ->whereGte('fecha', $this->desde)
                    ->whereLte('fecha', $this->hasta)
                    ->groupBy('codcliente, nombrecliente')
                    ->orderBy('total_neto', 'DESC');
                if (!empty($this->codEmpresa)) {
                    $qTop->whereEq('idempresa', (int)$this->codEmpresa);
                }
                $rawTop = $qTop->get();
                $sliced = array_slice($rawTop, 0, 5);

                $totalPositivo = 0.0;
                foreach ($rawTop as $row) {
                    $val = (float)$row['total_neto'];
                    if ($val > 0.0) {
                        $totalPositivo += $val;
                    }
                }

                foreach ($sliced as $row) {
                    $totalNeto = (float)$row['total_neto'];
                    $pct = $totalPositivo > 0.0 ? ($totalNeto / $totalPositivo) * 100 : 0.0;
                    if ($pct > 35) {
                        $this->hasClientRiskAlert = true;
                    }
                    $nombreClienteRaw = $row['nombrecliente'] ? Tools::noHtml((string)$row['nombrecliente']) : '(Cliente sin nombre)';
                    $this->topClients[] = [
                        'codcliente' => $row['codcliente'],
                        'nombrecliente' => $nombreClienteRaw,
                        'total_neto' => $totalNeto,
                        'total_neto_formatted' => Tools::money($totalNeto),
                        'percentage' => $pct
                    ];
                }
            }
        } catch (Throwable $e) {
            Tools::log()->warning('Error consultando Top Clientes: ' . $e->getMessage());
        }

        // 10. Evolution Chart Data (Ventas vs Gastos)
        $evolution = $this->getEvolutionChartData();

        // 11. Prepare Embedded JSON Data
        $this->dashDataJson = json_encode([
            'currencyCode' => Tools::settings('default', 'coddivisa'),
            'fechaDesde' => $this->desde,
            'fechaHasta' => $this->hasta,
            'calendarCenterDate' => date('Y-m-d'),
            'calendarView' => 'week',
            'evolution' => $evolution
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function calculatePercentageChange(float $current, float $prev): float
    {
        if ($prev === 0.0) {
            return $current > 0.0 ? 100.0 : ($current < 0.0 ? -100.0 : 0.0);
        }
        return (($current - $prev) / abs($prev)) * 100;
    }

    private function getEvolutionChartData(): array
    {
        $start = new DateTime($this->desde);
        $end = new DateTime($this->hasta);
        $daysCount = (int)$start->diff($end)->days + 1;
        $granularity = $this->resolveEvolutionGranularity($daysCount);
        $tables = $this->evolutionDocument === 'albaranes'
            ? ['albaranescli', 'albaranesprov']
            : ['facturascli', 'facturasprov'];

        $salesList = $this->safeEvolutionQuery($tables[0], $this->desde, $this->hasta);
        $expensesList = $this->safeEvolutionQuery($tables[1], $this->desde, $this->hasta);
        $buckets = $this->evolutionBuckets($start, $end, $granularity);
        $salesData = $this->aggregateEvolution($salesList, $buckets, $granularity);
        $expensesData = $this->aggregateEvolution($expensesList, $buckets, $granularity);

        $previousSales = [];
        $previousExpenses = [];
        if ($this->vsAnterior) {
            $previousStart = (clone $start)->modify('-' . $daysCount . ' days');
            $previousEnd = (clone $end)->modify('-' . $daysCount . ' days');
            $previousBuckets = $this->evolutionBuckets($previousStart, $previousEnd, $granularity);
            $previousSales = array_values($this->aggregateEvolution(
                $this->safeEvolutionQuery($tables[0], $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')),
                $previousBuckets,
                $granularity
            ));
            $previousExpenses = array_values($this->aggregateEvolution(
                $this->safeEvolutionQuery($tables[1], $previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')),
                $previousBuckets,
                $granularity
            ));
            $previousSales = array_slice(array_pad($previousSales, count($buckets), 0.0), 0, count($buckets));
            $previousExpenses = array_slice(array_pad($previousExpenses, count($buckets), 0.0), 0, count($buckets));
        }

        $documentLabel = $this->evolutionDocument === 'albaranes' ? 'Albaranes' : 'Facturas';
        return [
            'labels' => array_values(array_column($buckets, 'label')),
            'ventas' => array_values($salesData),
            'gastos' => array_values($expensesData),
            'ventasAnterior' => $previousSales,
            'gastosAnterior' => $previousExpenses,
            'salesLabel' => $documentLabel . ' de clientes',
            'expensesLabel' => $documentLabel . ' de proveedores',
            'document' => $this->evolutionDocument,
            'granularity' => $granularity,
            'metric' => $this->evolutionMetric,
            'chartType' => $this->evolutionChart,
        ];
    }

    private function resolveEvolutionGranularity(int $daysCount): string
    {
        if ($this->evolutionGranularity !== 'auto') {
            return $this->evolutionGranularity;
        }
        if ($daysCount <= 45) {
            return 'day';
        }
        if ($daysCount <= 180) {
            return 'week';
        }
        if ($daysCount <= 1095) {
            return 'month';
        }
        return 'year';
    }

    private function evolutionBuckets(DateTime $start, DateTime $end, string $granularity): array
    {
        $buckets = [];
        $cursor = clone $start;
        if ($granularity === 'week') {
            $cursor->modify('monday this week');
        } elseif ($granularity === 'month') {
            $cursor->modify('first day of this month');
        } elseif ($granularity === 'year') {
            $cursor->setDate((int)$cursor->format('Y'), 1, 1);
        }

        while ($cursor <= $end) {
            $key = $this->evolutionBucketKey($cursor->format('Y-m-d'), $granularity);
            $buckets[$key] = [
                'label' => match ($granularity) {
                    'day' => $cursor->format('d M'),
                    'week' => 'Sem ' . $cursor->format('W') . ' · ' . $cursor->format('Y'),
                    'year' => $cursor->format('Y'),
                    default => $cursor->format('M Y'),
                },
                'value' => 0.0,
            ];
            $cursor->modify(match ($granularity) {
                'day' => '+1 day',
                'week' => '+1 week',
                'year' => '+1 year',
                default => '+1 month',
            });
        }
        return $buckets;
    }

    private function aggregateEvolution(array $dailyData, array $buckets, string $granularity): array
    {
        $result = array_fill_keys(array_keys($buckets), 0.0);
        foreach ($dailyData as $date => $value) {
            $key = $this->evolutionBucketKey((string)$date, $granularity);
            if (array_key_exists($key, $result)) {
                $result[$key] += (float)$value;
            }
        }
        return $result;
    }

    private function evolutionBucketKey(string $date, string $granularity): string
    {
        $value = new DateTime(substr($date, 0, 10));
        return match ($granularity) {
            'day' => $value->format('Y-m-d'),
            'week' => $value->format('o-\\WW'),
            'year' => $value->format('Y'),
            default => $value->format('Y-m'),
        };
    }

    private function safeEvolutionQuery(string $table, string $from, string $to): array
    {
        try {
            return $this->db()->tableExists($table)
                ? $this->sumEvolutionByDate($table, $this->evolutionMetric, $from, $to)
                : [];
        } catch (Throwable $e) {
            Tools::log()->warning('Error cargando evolución ' . $table . ': ' . $e->getMessage());
            return [];
        }
    }

    private function sumEvolutionByDate(string $table, string $field, string $from, string $to): array
    {
        $allowedTables = ['facturascli', 'facturasprov', 'albaranescli', 'albaranesprov'];
        $allowedFields = ['neto', 'total'];
        if (!in_array($table, $allowedTables, true) || !in_array($field, $allowedFields, true)) {
            return [];
        }

        $query = DbQuery::table($table)->whereBetween('fecha', $from, $to);
        if (!empty($this->codEmpresa)) {
            $query->whereEq('idempresa', (int)$this->codEmpresa);
        }

        $result = [];
        foreach ($query->sumArray($field, 'fecha') as $fecha => $total) {
            $fecha = (string)$fecha;
            if (1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha)) {
                $clave = substr($fecha, 0, 10);
            } else {
                $timestamp = strtotime($fecha);
                $clave = false === $timestamp ? $fecha : date('Y-m-d', $timestamp);
            }
            $result[$clave] = ($result[$clave] ?? 0.0) + (float)$total;
        }

        return $result;
    }

    /**
     * AJAX de tesorería (recibos cobros/pagos). Solo vista base; sin timegrid de eventos,
     * sin allow_create, sin lazy de recurrencia ni formularios de serie.
     */
    private function ajaxReceipts(): void
    {
        $desde = (string) $this->request()->query('desde', date('Y-m-d'));
        $hasta = (string) $this->request()->query('hasta', date('Y-m-d'));
        [$desde, $hasta] = AuralisSecurity::sanitizeDateRange($desde, $hasta);
        $viewType = $this->request()->query('view') ?: 'week'; // '1day', '3days', 'week', 'month'
        $showCobros = $this->request()->query('cobros') === '1';
        $showPagos = $this->request()->query('pagos') === '1';
        $codEmpresa = AuralisSecurity::resolveCodEmpresa(
            $this->user,
            (string) $this->request()->query('idempresa', '')
        );
        $whereEmp = $codEmpresa !== '' ? " AND r.idempresa = " . (int) $codEmpresa : "";

        // Calculate how many columns the calendar grid should have
        $startDate = new \DateTime($desde);
        $endDate   = new \DateTime($hasta);
        if ($viewType === 'month') {
            // Vista mensual: siempre 7 columnas (Lun-Dom), multi-fila
            $colCount = 7;
        } else {
            $colCount = max(1, (int)$startDate->diff($endDate)->days + 1);
        }

        $receipts = [];
        $totalCobros = 0.0;
        $totalPagos = 0.0;

        // Fetch Cobros
        if ($showCobros && $this->db()->tableExists('recibospagoscli')) {
            try {
                $sql = "SELECT r.*, c.nombre AS nombre_cliente
                        FROM " . $this->db()->escapeColumn('recibospagoscli') . " r
                        LEFT JOIN " . $this->db()->escapeColumn('clientes') . " c ON r.codcliente = c.codcliente
                        WHERE r.vencimiento >= " . $this->db()->var2str($desde) . " 
                          AND r.vencimiento <= " . $this->db()->var2str($hasta) . $whereEmp;
                $rawCobros = $this->db()->select($sql);
                foreach ($rawCobros as $r) {
                    $val = (float)$r['importe'];
                    $totalCobros += $val;
                    $receipts[] = [
                        'type' => 'cobro',
                        'vencimiento' => $r['vencimiento'],
                        'importe' => $val,
                        'pagado' => (bool)$r['pagado'],
                        'codigofactura' => $r['codigofactura'],
                        'idfactura' => $r['idfactura'],
                        'name' => $r['nombre_cliente'] ?: $r['codigofactura'] ?: 'Cliente s/n'
                    ];
                }
            } catch (\Throwable $e) {
                Tools::log()->warning('Error AJAX cobros: ' . $e->getMessage());
            }
        }

        // Fetch Pagos
        if ($showPagos && $this->db()->tableExists('recibospagosprov')) {
            try {
                $sql = "SELECT r.*, p.nombre AS nombre_proveedor
                        FROM " . $this->db()->escapeColumn('recibospagosprov') . " r
                        LEFT JOIN " . $this->db()->escapeColumn('proveedores') . " p ON r.codproveedor = p.codproveedor
                        WHERE r.vencimiento >= " . $this->db()->var2str($desde) . " 
                          AND r.vencimiento <= " . $this->db()->var2str($hasta) . $whereEmp;
                $rawPagos = $this->db()->select($sql);
                foreach ($rawPagos as $r) {
                    $val = (float)$r['importe'];
                    $totalPagos += $val;
                    $receipts[] = [
                        'type' => 'pago',
                        'vencimiento' => $r['vencimiento'],
                        'importe' => $val,
                        'pagado' => (bool)$r['pagado'],
                        'codigofactura' => $r['codigofactura'],
                        'idfactura' => $r['idfactura'],
                        'name' => $r['nombre_proveedor'] ?: $r['codigofactura'] ?: 'Proveedor s/n'
                    ];
                }
            } catch (\Throwable $e) {
                Tools::log()->warning('Error AJAX pagos: ' . $e->getMessage());
            }
        }

        // Group by Date
        $grouped = [];
        foreach ($receipts as $r) {
            $grouped[$r['vencimiento']][] = $r;
        }

        // Generate Days Array
        $days = [];
        $start = new \DateTime($desde);
        $end = new \DateTime($hasta);

        $dayOfWeekNamesFull = array_map(
            static fn(string $clave): string => Tools::trans($clave),
            ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
        );

        $current = clone $start;
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $dayOfWeek = (int)$current->format('w');

            $dayCobros = 0.0;
            $dayPagos = 0.0;
            $cards = [];

            if (isset($grouped[$dateStr])) {
                foreach ($grouped[$dateStr] as $r) {
                    $isCobro = $r['type'] === 'cobro';
                    if ($isCobro) {
                        $dayCobros += $r['importe'];
                    } else {
                        $dayPagos += $r['importe'];
                    }

                    $isOverdue = !$r['pagado'] && $r['vencimiento'] < date('Y-m-d');
                    if ($r['pagado']) {
                        $badgeText = $isCobro ? 'Cobrado' : 'Pagado';
                        $badgeColor = $isCobro ? 'green' : 'blue';
                    } elseif ($isOverdue) {
                        $badgeText = 'Vencido';
                        $badgeColor = 'red';
                    } else {
                        $badgeText = 'Pendiente';
                        $badgeColor = 'yellow';
                    }

                    $docRef = $r['codigofactura'] ? ' (' . $r['codigofactura'] . ')' : '';
                    $subtitle = Tools::money($r['importe']) . $docRef;

                    $url = '#';
                    if ($r['idfactura']) {
                        $url = $isCobro ?
                            'EditFacturaCliente?code=' . $r['idfactura'] :
                            'EditFacturaProveedor?code=' . $r['idfactura'];
                    }

                    $cards[] = [
                        'title' => $r['name'],
                        'subtitle' => $subtitle,
                        'badge_text' => $badgeText,
                        'badge_color' => $badgeColor,
                        'url' => $url,
                        'icon' => $isCobro ? ($isOverdue ? 'fa-solid fa-arrow-down text-danger' : 'fa-solid fa-arrow-down text-success') : 'fa-solid fa-arrow-up text-danger'
                    ];
                }
            }

            $netAmountVal = $dayCobros - $dayPagos;
            $netAmountSign = $netAmountVal > 0 ? '+' : '';
            $netAmount = $netAmountSign . Tools::money($netAmountVal);

            $netClass = 'text-muted';
            if ($netAmountVal > 0.01) {
                $netClass = 'text-success';
            } elseif ($netAmountVal < -0.01) {
                $netClass = 'text-danger';
            }

            $days[] = [
                'day_name' => $dayOfWeekNamesFull[$dayOfWeek],
                'day_num' => $current->format('d'),
                'label' => $dayOfWeekNamesFull[$dayOfWeek] . ' ' . $current->format('d'),
                'date' => $dateStr,
                'is_today' => $dateStr === date('Y-m-d'),
                'net_amount' => $netAmount,
                'net_class' => $netClass,
                'cards' => $cards
            ];

            $current->modify('+1 day');
        }

        if ($viewType === 'month') {
            $days = CalendarMonthGrid::prependFillers($days, $desde, $hasta);
        }

        // Receipts: siempre _calendar_base, nunca timegrid interactivo ni creación de eventos
        $html = Html::render('@PluginAuralis/Master/_calendar_base.html.twig', [
            'calendar_data' => ['days' => $days],
            'col_count'     => $colCount,
            'view_type'     => $viewType,
            'cal_mode'      => 'receipts',
            'allow_create'  => false,
        ]);

        $this->response()->json([
            'html' => $html,
            'totalCobrosFormatted' => Tools::money($totalCobros),
            'totalPagosFormatted' => Tools::money($totalPagos)
        ]);
    }

    private function loadEmpresas(): void
    {
        $this->empresas = [];
        $this->hasMultiEmpresa = false;
        $this->codEmpresa = AuralisSecurity::resolveCodEmpresa(
            $this->user,
            (string) $this->request()->query('idempresa', '')
        );

        if (AuralisSecurity::isEmpresaLocked($this->user)) {
            return;
        }

        try {
            if ($this->db()->tableExists('empresas')) {
                $rawEmpresas = DbQuery::table('empresas')
                    ->select('idempresa, nombrecorto, nombre')
                    ->get();
                if (count($rawEmpresas) > 1) {
                    $this->hasMultiEmpresa = true;
                    foreach ($rawEmpresas as $emp) {
                        $this->empresas[] = [
                            'id' => (string)$emp['idempresa'],
                            'nombre' => $emp['nombrecorto'] ?: ($emp['nombre'] ?: 'Empresa ' . $emp['idempresa'])
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error cargando empresas: ' . $e->getMessage());
        }
    }

    /**
     * Carga las listas de recibos pendientes (cobros y pagos) del modal de tesorería.
     *
     * Los totales y contadores son REALES (consulta agregada sin LIMIT, con los mismos filtros
     * que las listas); las listas se limitan a 100 filas por grupo (vencidos / próximos).
     */
    private function loadReceiptsLists(): void
    {
        $this->recibosVencidosVentas = [];
        $this->recibosProximosVentas = [];
        $this->recibosVencidosCompras = [];
        $this->recibosProximosCompras = [];

        $this->recibosVentasVencidosTotal = 0.0;
        $this->recibosVentasVencidosCount = 0;
        $this->recibosVentasProximosTotal = 0.0;
        $this->recibosVentasProximosCount = 0;

        $this->recibosComprasVencidosTotal = 0.0;
        $this->recibosComprasVencidosCount = 0;
        $this->recibosComprasProximosTotal = 0.0;
        $this->recibosComprasProximosCount = 0;

        $today = date('Y-m-d');
        $rawSort = (string)$this->request()->query('sort_recibos', 'dias-desc');
        $allowedSorts = ['dias-desc', 'dias-asc', 'importe-desc', 'importe-asc'];
        $sortOrder = in_array($rawSort, $allowedSorts, true) ? $rawSort : 'dias-desc';

        switch ($sortOrder) {
            case 'dias-asc':
                $orderBy = "ORDER BY r.vencimiento DESC";
                break;
            case 'importe-desc':
                $orderBy = "ORDER BY r.importe DESC";
                break;
            case 'importe-asc':
                $orderBy = "ORDER BY r.importe ASC";
                break;
            case 'dias-desc':
            default:
                $orderBy = "ORDER BY r.vencimiento ASC";
                break;
        }

        $whereEmp = !empty($this->codEmpresa) ? " AND r.idempresa = " . (int)$this->codEmpresa : "";

        // 1. Recibos de Ventas (Cobros)
        if ($this->db()->tableExists('recibospagoscli')) {
            try {
                $res = $this->queryPendingReceipts(true, $today, $orderBy, $whereEmp);
                $this->recibosVencidosVentas = $res['vencidos'];
                $this->recibosProximosVentas = $res['proximos'];
                $this->recibosVentasVencidosTotal = $res['vencidosTotal'];
                $this->recibosVentasVencidosCount = $res['vencidosCount'];
                $this->recibosVentasProximosTotal = $res['proximosTotal'];
                $this->recibosVentasProximosCount = $res['proximosCount'];
            } catch (Throwable $e) {
                Tools::log()->warning('Error cargando recibos de ventas: ' . $e->getMessage());
            }
        }

        // 2. Recibos de Compras (Pagos)
        if ($this->db()->tableExists('recibospagosprov')) {
            try {
                $res = $this->queryPendingReceipts(false, $today, $orderBy, $whereEmp);
                $this->recibosVencidosCompras = $res['vencidos'];
                $this->recibosProximosCompras = $res['proximos'];
                $this->recibosComprasVencidosTotal = $res['vencidosTotal'];
                $this->recibosComprasVencidosCount = $res['vencidosCount'];
                $this->recibosComprasProximosTotal = $res['proximosTotal'];
                $this->recibosComprasProximosCount = $res['proximosCount'];
            } catch (Throwable $e) {
                Tools::log()->warning('Error cargando recibos de compras: ' . $e->getMessage());
            }
        }

        $this->recibosVentasVencidosTotalFormatted = Tools::money($this->recibosVentasVencidosTotal);
        $this->recibosVentasProximosTotalFormatted = Tools::money($this->recibosVentasProximosTotal);
        $this->recibosComprasVencidosTotalFormatted = Tools::money($this->recibosComprasVencidosTotal);
        $this->recibosComprasProximosTotalFormatted = Tools::money($this->recibosComprasProximosTotal);
    }

    /**
     * Recibos pendientes (pagado = false, vencimiento <= hasta) de ventas o compras.
     *
     * Hace 3 consultas con los mismos filtros (empresa y exclusiones de tesorería incluidas):
     * un agregado (COUNT + SUM por vencidos / próximos, sin LIMIT) y dos listas de máx. 100 filas
     * (vencidos: vencimiento < hoy; próximos: vencimiento >= hoy) con el orden elegido.
     *
     * @param bool   $isSales  true = recibos de clientes (cobros), false = de proveedores (pagos).
     * @param string $today    Fecha de hoy (Y-m-d).
     * @param string $orderBy  Cláusula ORDER BY ya validada contra lista blanca.
     * @param string $whereEmp Fragmento " AND r.idempresa = N" o cadena vacía.
     *
     * @return array{vencidos: array, proximos: array, vencidosTotal: float, vencidosCount: int, proximosTotal: float, proximosCount: int}
     */
    private function queryPendingReceipts(bool $isSales, string $today, string $orderBy, string $whereEmp): array
    {
        $db = $this->db();
        $table = $isSales ? 'recibospagoscli' : 'recibospagosprov';
        $thirdTable = $isSales ? 'clientes' : 'proveedores';
        $thirdCode = $isSales ? 'codcliente' : 'codproveedor';

        $baseWhere = "r.pagado = false AND r.vencimiento <= " . $db->var2str($this->hasta)
            . $whereEmp . $this->sqlReceiptExcludes('r');
        $todayStr = $db->var2str($today);

        // Totales y contadores reales (mismos filtros, sin LIMIT)
        $sqlAgg = "SELECT
                COUNT(CASE WHEN r.vencimiento < " . $todayStr . " THEN 1 END) AS v_count,
                COALESCE(SUM(CASE WHEN r.vencimiento < " . $todayStr . " THEN r.importe ELSE 0 END), 0) AS v_total,
                COUNT(CASE WHEN r.vencimiento >= " . $todayStr . " THEN 1 END) AS p_count,
                COALESCE(SUM(CASE WHEN r.vencimiento >= " . $todayStr . " THEN r.importe ELSE 0 END), 0) AS p_total
            FROM " . $db->escapeColumn($table) . " r
            WHERE " . $baseWhere;
        $agg = $db->select($sqlAgg);
        $row = $agg[0] ?? [];

        $result = [
            'vencidos' => [],
            'proximos' => [],
            'vencidosCount' => (int)($row['v_count'] ?? 0),
            'vencidosTotal' => (float)($row['v_total'] ?? 0),
            'proximosCount' => (int)($row['p_count'] ?? 0),
            'proximosTotal' => (float)($row['p_total'] ?? 0),
        ];

        // El % de cada fila se calcula sobre el total pendiente real (vencidos + próximos)
        $totalAmount = $result['vencidosTotal'] + $result['proximosTotal'];

        $groups = [
            'vencidos' => " AND r.vencimiento < " . $todayStr,
            'proximos' => " AND r.vencimiento >= " . $todayStr,
        ];

        foreach ($groups as $key => $groupWhere) {
            // Si el agregado dice que no hay filas, nos ahorramos la consulta de la lista
            if ($result[$key . 'Count'] === 0) {
                continue;
            }

            $sqlList = "SELECT r.*, t.nombre AS nombre_tercero
                    FROM " . $db->escapeColumn($table) . " r
                    LEFT JOIN " . $db->escapeColumn($thirdTable) . " t ON r." . $thirdCode . " = t." . $thirdCode . "
                    WHERE " . $baseWhere . $groupWhere . "
                    " . $orderBy . " LIMIT 100";

            foreach ($db->select($sqlList) as $r) {
                $val = (float)$r['importe'];
                $isOverdue = !$r['pagado'] && $r['vencimiento'] < $today;
                $diffDays = (int)(new DateTime($today))->diff(new DateTime($r['vencimiento']))->format('%r%a');
                $pct = $totalAmount > 0 ? ($val / $totalAmount) * 100 : 0.0;

                if ($isSales) {
                    $item = [
                        'id' => $r['idrecibo'] ?? '',
                        'nombre' => $r['nombre_tercero'] ?: ($r['codigofactura'] ?: 'Cliente s/n'),
                        'codcliente' => $r['codcliente'] ?? '',
                    ];
                    $url = !empty($r['idfactura'])
                        ? 'EditFacturaCliente?code=' . $r['idfactura']
                        : (!empty($r['codcliente']) ? 'EditCliente?code=' . $r['codcliente'] : '#');
                } else {
                    $item = [
                        'id' => $r['idrecibo'] ?? '',
                        'nombre' => $r['nombre_tercero'] ?: ($r['codigofactura'] ?: 'Proveedor s/n'),
                        'codproveedor' => $r['codproveedor'] ?? '',
                    ];
                    $url = !empty($r['idfactura'])
                        ? 'EditFacturaProveedor?code=' . $r['idfactura']
                        : (!empty($r['codproveedor']) ? 'EditProveedor?code=' . $r['codproveedor'] : '#');
                }

                $item += [
                    'codigofactura' => $r['codigofactura'] ?? '',
                    'idfactura' => $r['idfactura'] ?? '',
                    'importe' => $val,
                    'importe_formatted' => Tools::money($val),
                    'percentage' => round($pct, 1),
                    'vencimiento' => $r['vencimiento'],
                    'vencimiento_formatted' => date('d/m/Y', strtotime($r['vencimiento'])),
                    'is_overdue' => $isOverdue,
                    'pagado' => (bool)$r['pagado'],
                    'dias' => abs($diffDays),
                    'dias_diff' => $diffDays,
                    'url' => $url,
                ];

                $result[$key][] = $item;
            }
        }

        return $result;
    }

    /**
     * Detecta facturas venta/compra con diferencia entre total y suma de recibos
     * (misma tolerancia que Core: abs(diff) > 0.01). Alcance: empresa activa, sin filtro de periodo.
     * Utiliza caché (TTL 10 min) para optimizar el rendimiento y evitar sobrecarga en bases de datos grandes.
     */
    private function loadReceiptsDiffAlert(): void
    {
        $this->facturasDescuadreVentas = [];
        $this->facturasDescuadreCompras = [];
        $this->facturasDescuadreCount = 0;
        $this->hasReceiptsDiffAlert = false;

        $cacheKey = 'auralis_receipts_diff_v3_' . ($this->codEmpresa !== '' ? $this->codEmpresa : 'all');
        $cached = Cache::get($cacheKey);
        if (is_array($cached)
            && isset($cached['expires_at'], $cached['ventas'], $cached['compras'], $cached['count'], $cached['hasAlert'])
            && $cached['expires_at'] >= time()
        ) {
            $this->facturasDescuadreVentas = $cached['ventas'];
            $this->facturasDescuadreCompras = $cached['compras'];
            $this->facturasDescuadreCount = (int) $cached['count'];
            $this->hasReceiptsDiffAlert = (bool) $cached['hasAlert'];
            return;
        }

        $today = date('Y-m-d');
        $whereEmpF = !empty($this->codEmpresa) ? ' AND f.idempresa = ' . (int) $this->codEmpresa : '';

        // Ventas
        if ($this->db()->tableExists('facturascli') && $this->db()->tableExists('recibospagoscli')) {
            try {
                $sql = 'SELECT f.idfactura, f.idfacturarect, f.codigo, f.nombrecliente, f.fecha, f.total,'
                    . ' COALESCE(SUM(r.importe), 0) AS suma_recibos,'
                    . ' (f.total - COALESCE(SUM(r.importe), 0)) AS diff'
                    . ' FROM ' . $this->db()->escapeColumn('facturascli') . ' f'
                    . ' LEFT JOIN ' . $this->db()->escapeColumn('recibospagoscli') . ' r ON r.idfactura = f.idfactura'
                    . ' WHERE 1=1' . $whereEmpF
                    . ' GROUP BY f.idfactura, f.idfacturarect, f.codigo, f.nombrecliente, f.fecha, f.total'
                    . ' HAVING ABS(f.total - COALESCE(SUM(r.importe), 0)) > 0.01'
                    . ' ORDER BY ABS(f.total - COALESCE(SUM(r.importe), 0)) DESC'
                    . ' LIMIT 200';
                $rows = $this->db()->select($sql);
                foreach ($rows as $row) {
                    $this->facturasDescuadreVentas[] = $this->buildReceiptsDiffItem(
                        $row,
                        (string) ($row['nombrecliente'] ?? ''),
                        'EditFacturaCliente?code=' . (int) $row['idfactura'],
                        $today
                    );
                }
                $this->facturasDescuadreVentas = ReceiptsDiffFilter::filterCompensatedRectificativePairs(
                    $this->facturasDescuadreVentas
                );
            } catch (\Throwable $e) {
                Tools::log()->warning('Error detectando descuadre recibos ventas: ' . $e->getMessage());
            }
        }

        // Compras
        if ($this->db()->tableExists('facturasprov') && $this->db()->tableExists('recibospagosprov')) {
            try {
                $sql = 'SELECT f.idfactura, f.idfacturarect, f.codigo, f.nombre, f.fecha, f.total,'
                    . ' COALESCE(SUM(r.importe), 0) AS suma_recibos,'
                    . ' (f.total - COALESCE(SUM(r.importe), 0)) AS diff'
                    . ' FROM ' . $this->db()->escapeColumn('facturasprov') . ' f'
                    . ' LEFT JOIN ' . $this->db()->escapeColumn('recibospagosprov') . ' r ON r.idfactura = f.idfactura'
                    . ' WHERE 1=1' . $whereEmpF
                    . ' GROUP BY f.idfactura, f.idfacturarect, f.codigo, f.nombre, f.fecha, f.total'
                    . ' HAVING ABS(f.total - COALESCE(SUM(r.importe), 0)) > 0.01'
                    . ' ORDER BY ABS(f.total - COALESCE(SUM(r.importe), 0)) DESC'
                    . ' LIMIT 200';
                $rows = $this->db()->select($sql);
                foreach ($rows as $row) {
                    $this->facturasDescuadreCompras[] = $this->buildReceiptsDiffItem(
                        $row,
                        (string) ($row['nombre'] ?? ''),
                        'EditFacturaProveedor?code=' . (int) $row['idfactura'],
                        $today
                    );
                }
                $this->facturasDescuadreCompras = ReceiptsDiffFilter::filterCompensatedRectificativePairs(
                    $this->facturasDescuadreCompras
                );
            } catch (\Throwable $e) {
                Tools::log()->warning('Error detectando descuadre recibos compras: ' . $e->getMessage());
            }
        }

        $this->facturasDescuadreCount = count($this->facturasDescuadreVentas) + count($this->facturasDescuadreCompras);
        $this->hasReceiptsDiffAlert = $this->facturasDescuadreCount > 0;

        Cache::set($cacheKey, [
            'expires_at' => time() + 600,
            'ventas' => $this->facturasDescuadreVentas,
            'compras' => $this->facturasDescuadreCompras,
            'count' => $this->facturasDescuadreCount,
            'hasAlert' => $this->hasReceiptsDiffAlert,
        ]);
    }

    /**
     * Construye un ítem de factura con descuadre de recibos (ordenación por importe/fecha en el modal).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildReceiptsDiffItem(array $row, string $tercero, string $url, string $today): array
    {
        $total = (float) $row['total'];
        $suma = (float) $row['suma_recibos'];
        $diff = (float) $row['diff'];
        $fecha = (string) ($row['fecha'] ?? $today);
        $diasDiff = 0;
        try {
            $diasDiff = (int) (new \DateTime($today))->diff(new \DateTime($fecha))->format('%r%a');
        } catch (\Throwable $e) {
            $diasDiff = 0;
        }

        return [
            'idfactura' => (int) $row['idfactura'],
            'idfacturarect' => !empty($row['idfacturarect']) ? (int) $row['idfacturarect'] : null,
            'codigo' => (string) ($row['codigo'] ?? ''),
            'tercero' => $tercero,
            'fecha' => $fecha,
            'fecha_formatted' => date('d/m/Y', strtotime($fecha) ?: time()),
            'dias' => abs($diasDiff),
            'dias_diff' => $diasDiff,
            'total' => $total,
            'total_formatted' => Tools::money($total),
            'suma_recibos' => $suma,
            'suma_recibos_formatted' => Tools::money($suma),
            'diff' => $diff,
            'diff_abs' => abs($diff),
            'diff_formatted' => Tools::money($diff),
            'url' => $url,
        ];
    }

    /**
     * KPIs de ciclo cobro/pago del periodo.
     * Si el periodo no tiene cobros/pagos liquidados, amplía a YTD hasta $hasta.
     */
    private function loadTreasuryCycleKpis(): void
    {
        $nd = Tools::trans('kpi-sin-datos');
        $this->kpiCicloCobroVentas = null;
        $this->kpiCicloCobroVentasFormatted = $nd;
        $this->kpiCicloCobroVentasCmpFormatted = '';
        $this->kpiRetrasoCobroVentas = null;
        $this->kpiRetrasoCobroVentasFormatted = $nd;
        $this->kpiRetrasoCobroVentasCmpFormatted = '';
        $this->kpiRetrasoCobroVentasPctAtrasoFormatted = '';
        $this->kpiRatioCobradoVentas = null;
        $this->kpiRatioCobradoVentasFormatted = $nd;
        $this->kpiRatioCobradoVentasCmpFormatted = '';
        $this->kpiRatioCobradoVentasImportesFormatted = '';
        $this->kpiCicloPagoCompras = null;
        $this->kpiCicloPagoComprasFormatted = $nd;
        $this->kpiCicloPagoComprasCmpFormatted = '';
        $this->kpiRetrasoPagoCompras = null;
        $this->kpiRetrasoPagoComprasFormatted = $nd;
        $this->kpiRetrasoPagoComprasCmpFormatted = '';
        $this->kpiRetrasoPagoComprasPctAtrasoFormatted = '';
        $this->kpiRatioPagadoCompras = null;
        $this->kpiRatioPagadoComprasFormatted = $nd;
        $this->kpiRatioPagadoComprasCmpFormatted = '';
        $this->kpiRatioPagadoComprasImportesFormatted = '';
        $this->kpiCicloRangoAmpliado = false;
        $this->kpiCmpIsPriorYear = false;
        $this->kpiCmpLabel = Tools::trans('kpi-cmp-ytd');
        $this->kpiCicloDesde = $this->desde;
        $this->kpiCicloHasta = $this->hasta;

        [$desde, $hasta, $ampliado] = $this->resolveTreasuryCycleDateRange();
        $this->kpiCicloDesde = $desde;
        $this->kpiCicloHasta = $hasta;
        $this->kpiCicloRangoAmpliado = $ampliado;

        [$cmpDesde, $cmpHasta, $isPriorYear] = $this->resolveTreasuryCycleCompareRange($desde, $hasta);
        $this->kpiCmpIsPriorYear = $isPriorYear;
        $this->kpiCmpLabel = $isPriorYear ? Tools::trans('kpi-cmp-vs-ant') : Tools::trans('kpi-cmp-ytd');

        try {
            if ($this->db()->tableExists('recibospagoscli') && $this->db()->tableExists('facturascli')) {
                $this->fillTreasurySideKpis(
                    'recibospagoscli',
                    'facturascli',
                    $desde,
                    $hasta,
                    $cmpDesde,
                    $cmpHasta,
                    true
                );
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error KPIs ciclo cobro ventas: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov') && $this->db()->tableExists('facturasprov')) {
                $this->fillTreasurySideKpis(
                    'recibospagosprov',
                    'facturasprov',
                    $desde,
                    $hasta,
                    $cmpDesde,
                    $cmpHasta,
                    false
                );
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error KPIs ciclo pago compras: ' . $e->getMessage());
        }
    }

    /**
     * Rellena KPIs de un lado (ventas o compras) + comparativa.
     */
    private function fillTreasurySideKpis(
        string $recibosTable,
        string $facturasTable,
        string $desde,
        string $hasta,
        string $cmpDesde,
        string $cmpHasta,
        bool $isVentas
    ): void {
        $ciclo = $this->queryWeightedCycleDays($recibosTable, $facturasTable, $desde, $hasta);
        $retraso = $this->queryWeightedDelayDays($recibosTable, $facturasTable, $desde, $hasta);
        $pctAtraso = $this->queryPctLateReceipts($recibosTable, $facturasTable, $desde, $hasta);
        $ratioData = $this->queryBilledCollectedRatio($recibosTable, $facturasTable, $desde, $hasta);

        $cicloCmp = $this->queryWeightedCycleDays($recibosTable, $facturasTable, $cmpDesde, $cmpHasta);
        $retrasoCmp = $this->queryWeightedDelayDays($recibosTable, $facturasTable, $cmpDesde, $cmpHasta);
        $ratioCmp = $this->queryBilledCollectedRatio($recibosTable, $facturasTable, $cmpDesde, $cmpHasta);

        if ($isVentas) {
            $this->kpiCicloCobroVentas = $ciclo;
            $this->kpiCicloCobroVentasFormatted = $this->formatDaysKpi($ciclo);
            $this->kpiCicloCobroVentasCmpFormatted = $this->formatCmpLine($cicloCmp, true);
            $this->kpiRetrasoCobroVentas = $retraso;
            $this->kpiRetrasoCobroVentasFormatted = $this->formatDaysKpi($retraso);
            $this->kpiRetrasoCobroVentasCmpFormatted = $this->formatCmpLine($retrasoCmp, true);
            $this->kpiRetrasoCobroVentasPctAtrasoFormatted = $this->formatPctAtrasoLine($pctAtraso);
            $this->kpiRatioCobradoVentas = $ratioData['ratio'];
            $this->kpiRatioCobradoVentasFormatted = $this->formatRatioKpi($ratioData['ratio']);
            $this->kpiRatioCobradoVentasCmpFormatted = $this->formatCmpLine($ratioCmp['ratio'], false);
            $this->kpiRatioCobradoVentasImportesFormatted = $this->formatRatioImportesLine(
                $ratioData['cobrado'],
                $ratioData['facturado'],
                $ratioData['ratio']
            );
            return;
        }

        $this->kpiCicloPagoCompras = $ciclo;
        $this->kpiCicloPagoComprasFormatted = $this->formatDaysKpi($ciclo);
        $this->kpiCicloPagoComprasCmpFormatted = $this->formatCmpLine($cicloCmp, true);
        $this->kpiRetrasoPagoCompras = $retraso;
        $this->kpiRetrasoPagoComprasFormatted = $this->formatDaysKpi($retraso);
        $this->kpiRetrasoPagoComprasCmpFormatted = $this->formatCmpLine($retrasoCmp, true);
        $this->kpiRetrasoPagoComprasPctAtrasoFormatted = $this->formatPctAtrasoLine($pctAtraso);
        $this->kpiRatioPagadoCompras = $ratioData['ratio'];
        $this->kpiRatioPagadoComprasFormatted = $this->formatRatioKpi($ratioData['ratio']);
        $this->kpiRatioPagadoComprasCmpFormatted = $this->formatCmpLine($ratioCmp['ratio'], false);
        $this->kpiRatioPagadoComprasImportesFormatted = $this->formatRatioImportesLine(
            $ratioData['cobrado'],
            $ratioData['facturado'],
            $ratioData['ratio']
        );
    }

    /**
     * Ranking por cliente/proveedor (máx. 100 por importe liquidado).
     */
    private function loadTreasuryPayerRanking(): void
    {
        $this->rankingPagadoresClientes = [];
        $this->rankingPagadoresProveedores = [];

        $desde = $this->kpiCicloDesde !== '' ? $this->kpiCicloDesde : $this->desde;
        $hasta = $this->kpiCicloHasta !== '' ? $this->kpiCicloHasta : $this->hasta;

        try {
            if ($this->db()->tableExists('recibospagoscli') && $this->db()->tableExists('facturascli')) {
                $this->rankingPagadoresClientes = $this->queryPayerRanking(
                    'recibospagoscli',
                    'facturascli',
                    'clientes',
                    'codcliente',
                    'EditCliente',
                    true,
                    $desde,
                    $hasta
                );
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error ranking pagadores clientes: ' . $e->getMessage());
        }

        try {
            if ($this->db()->tableExists('recibospagosprov') && $this->db()->tableExists('facturasprov')) {
                $this->rankingPagadoresProveedores = $this->queryPayerRanking(
                    'recibospagosprov',
                    'facturasprov',
                    'proveedores',
                    'codproveedor',
                    'EditProveedor',
                    false,
                    $desde,
                    $hasta
                );
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error ranking pagadores proveedores: ' . $e->getMessage());
        }
    }

    /**
     * @return array{0: string, 1: string, 2: bool} [desde, hasta, ampliado]
     */
    private function resolveTreasuryCycleDateRange(): array
    {
        $desde = $this->desde;
        $hasta = $this->hasta;

        if ($this->hasTreasuryCycleSample($desde, $hasta)) {
            return [$desde, $hasta, false];
        }

        $ytdDesde = substr($hasta, 0, 4) . '-01-01';
        if ($ytdDesde < $desde && $this->hasTreasuryCycleSample($ytdDesde, $hasta)) {
            return [$ytdDesde, $hasta, true];
        }

        $rollDesde = date('Y-m-d', strtotime($hasta . ' -365 days'));
        if ($rollDesde < $desde && $this->hasTreasuryCycleSample($rollDesde, $hasta)) {
            return [$rollDesde, $hasta, true];
        }

        return [$desde, $hasta, false];
    }

    /**
     * Comparativa: YTD si el principal no es ya YTD; si lo es, mismo tramo del año anterior.
     *
     * @return array{0: string, 1: string, 2: bool} [desde, hasta, isPriorYear]
     */
    private function resolveTreasuryCycleCompareRange(string $desde, string $hasta): array
    {
        $ytdDesde = substr($hasta, 0, 4) . '-01-01';
        if ($desde <= $ytdDesde) {
            $prevYear = ((int) substr($hasta, 0, 4)) - 1;
            $cmpDesde = $this->shiftDateYear($desde, $prevYear);
            $cmpHasta = $this->shiftDateYear($hasta, $prevYear);
            return [$cmpDesde, $cmpHasta, true];
        }

        return [$ytdDesde, $hasta, false];
    }

    private function shiftDateYear(string $isoDate, int $year): string
    {
        $parts = explode('-', $isoDate);
        if (count($parts) !== 3) {
            return $year . '-01-01';
        }
        $month = (int) $parts[1];
        $day = (int) $parts[2];
        $maxDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $day = min($day, $maxDay);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function hasTreasuryCycleSample(string $desde, string $hasta): bool
    {
        try {
            if ($this->db()->tableExists('recibospagoscli') && $this->db()->tableExists('facturascli')) {
                if ($this->countPaidReceiptsSample('recibospagoscli', 'facturascli', $desde, $hasta) > 0) {
                    return true;
                }
            }
            if ($this->db()->tableExists('recibospagosprov') && $this->db()->tableExists('facturasprov')) {
                if ($this->countPaidReceiptsSample('recibospagosprov', 'facturasprov', $desde, $hasta) > 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error comprobando muestra ciclo tesorería: ' . $e->getMessage());
        }

        return false;
    }

    private function countPaidReceiptsSample(string $recibosTable, string $facturasTable, string $desde, string $hasta): int
    {
        $exclude = $this->sqlInvoiceExcludes('f', $facturasTable);
        $sql = 'SELECT COUNT(*) AS n'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' WHERE r.pagado = true'
            . ' AND r.fechapago IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $exclude;

        $rows = $this->db()->select($sql);
        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * Aplica filtros SQL registrados por plugins (p. ej. zAnticipos).
     */
    private function sqlInvoiceExcludes(string $facturaAlias, string $facturasTable): string
    {
        $sql = '';
        foreach (self::$treasuryCycleInvoiceExcludes as $fn) {
            $fragment = (string) $fn($facturaAlias, $facturasTable, $this->db());
            if ($fragment !== '') {
                $sql .= $fragment;
            }
        }

        return $sql;
    }

    /**
     * @return list<string> codpago excluidos por plugins (sin duplicados ni vacíos)
     */
    private function treasuryExcludedPaymentCodes(): array
    {
        $codes = [];
        foreach (self::$treasuryReceiptPaymentExcludes as $fn) {
            foreach ((array) $fn() as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $codes[$code] = $code;
                }
            }
        }

        return array_values($codes);
    }

    /**
     * Fragmento " AND ..." que excluye los recibos de las formas de pago registradas.
     */
    private function sqlReceiptExcludes(string $reciboAlias = 'r'): string
    {
        $codes = $this->treasuryExcludedPaymentCodes();
        if (empty($codes)) {
            return '';
        }

        $values = array_map(fn(string $code): string => $this->db()->var2str($code), $codes);

        return ' AND (' . $reciboAlias . '.codpago IS NULL OR ' . $reciboAlias . '.codpago NOT IN (' . implode(',', $values) . '))';
    }

    /**
     * Aplica la exclusión de formas de pago a una consulta DbQuery sobre una tabla de recibos.
     */
    private function applyReceiptExcludes(DbQuery $query): DbQuery
    {
        $codes = $this->treasuryExcludedPaymentCodes();
        if (!empty($codes)) {
            $query->where([Where::sub([Where::isNull('codpago'), Where::orNotIn('codpago', $codes)])]);
        }

        return $query;
    }

    private function sqlDaysBetween(string $fechaFin, string $fechaInicio): string
    {
        if ($this->db()->type() === 'postgresql') {
            return '(' . $fechaFin . ' - ' . $fechaInicio . ')';
        }

        return 'DATEDIFF(' . $fechaFin . ', ' . $fechaInicio . ')';
    }

    private function sqlEmpresaReceiptAlias(string $alias = 'r'): string
    {
        return !empty($this->codEmpresa)
            ? ' AND ' . $alias . '.idempresa = ' . (int) $this->codEmpresa
            : '';
    }

    private function sqlEmpresaFacturaAlias(string $alias = 'f'): string
    {
        return !empty($this->codEmpresa)
            ? ' AND ' . $alias . '.idempresa = ' . (int) $this->codEmpresa
            : '';
    }

    /**
     * Media ponderada (por |importe|) de fechapago − fecha factura.
     */
    private function queryWeightedCycleDays(string $recibosTable, string $facturasTable, string $desde, string $hasta): ?float
    {
        $dias = $this->sqlDaysBetween('r.fechapago', 'f.fecha');
        $exclude = $this->sqlInvoiceExcludes('f', $facturasTable);
        $sql = 'SELECT SUM((' . $dias . ') * ABS(r.importe)) / NULLIF(SUM(ABS(r.importe)), 0) AS valor'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' WHERE r.pagado = true'
            . ' AND r.fechapago IS NOT NULL AND f.fecha IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $exclude;

        return $this->fetchNullableFloat($sql);
    }

    /**
     * Media ponderada de cartera: GREATEST(0, fechapago − vencimiento) sobre todos los liquidados.
     */
    private function queryWeightedDelayDays(string $recibosTable, string $facturasTable, string $desde, string $hasta): ?float
    {
        $dias = $this->sqlDaysBetween('r.fechapago', 'r.vencimiento');
        $exclude = $this->sqlInvoiceExcludes('f', $facturasTable);
        $delayExpr = 'GREATEST(0, ' . $dias . ')';
        $sql = 'SELECT SUM(CASE WHEN r.vencimiento IS NOT NULL THEN (' . $delayExpr . ') * ABS(r.importe) ELSE 0 END)'
            . ' / NULLIF(SUM(CASE WHEN r.vencimiento IS NOT NULL THEN ABS(r.importe) ELSE 0 END), 0) AS valor'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' WHERE r.pagado = true'
            . ' AND r.fechapago IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $exclude;

        return $this->fetchNullableFloat($sql);
    }

    /**
     * % de recibos liquidados con fechapago > vencimiento.
     */
    private function queryPctLateReceipts(string $recibosTable, string $facturasTable, string $desde, string $hasta): ?float
    {
        $exclude = $this->sqlInvoiceExcludes('f', $facturasTable);
        $sql = 'SELECT SUM(CASE WHEN r.vencimiento IS NOT NULL AND r.fechapago > r.vencimiento THEN 1 ELSE 0 END) * 100.0'
            . ' / NULLIF(SUM(CASE WHEN r.vencimiento IS NOT NULL THEN 1 ELSE 0 END), 0) AS valor'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' WHERE r.pagado = true'
            . ' AND r.fechapago IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $exclude;

        return $this->fetchNullableFloat($sql);
    }

    /**
     * @return array{ratio: ?float, cobrado: float, facturado: float}
     */
    private function queryBilledCollectedRatio(string $recibosTable, string $facturasTable, string $desde, string $hasta): array
    {
        $excludeRec = $this->sqlInvoiceExcludes('f', $facturasTable);
        $excludeFac = $this->sqlInvoiceExcludes('f', $facturasTable);

        $sqlCobrado = 'SELECT COALESCE(SUM(r.importe), 0) AS total'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' WHERE r.pagado = true AND r.fechapago IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $excludeRec;

        $sqlFacturado = 'SELECT COALESCE(SUM(f.total), 0) AS total'
            . ' FROM ' . $this->db()->escapeColumn($facturasTable) . ' f'
            . ' WHERE f.fecha >= ' . $this->db()->var2str($desde)
            . ' AND f.fecha <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaFacturaAlias('f')
            . $excludeFac;

        $cobradoRows = $this->db()->select($sqlCobrado);
        $facturadoRows = $this->db()->select($sqlFacturado);
        $cobrado = (float) ($cobradoRows[0]['total'] ?? 0);
        $facturado = (float) ($facturadoRows[0]['total'] ?? 0);
        if (abs($facturado) < 0.00001) {
            return ['ratio' => null, 'cobrado' => $cobrado, 'facturado' => $facturado];
        }

        return [
            'ratio' => round(($cobrado / $facturado) * 100, 1),
            'cobrado' => $cobrado,
            'facturado' => $facturado,
        ];
    }

    private function fetchNullableFloat(string $sql): ?float
    {
        $rows = $this->db()->select($sql);
        if (empty($rows) || !array_key_exists('valor', $rows[0]) || $rows[0]['valor'] === null || $rows[0]['valor'] === '') {
            return null;
        }

        return round((float) $rows[0]['valor'], 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryPayerRanking(
        string $recibosTable,
        string $facturasTable,
        string $tercerosTable,
        string $codeField,
        string $editController,
        bool $isCliente,
        string $desde,
        string $hasta
    ): array {
        $diasCiclo = $this->sqlDaysBetween('r.fechapago', 'f.fecha');
        $diasRetraso = $this->sqlDaysBetween('r.fechapago', 'r.vencimiento');
        $exclude = $this->sqlInvoiceExcludes('f', $facturasTable);
        $delayExpr = 'GREATEST(0, ' . $diasRetraso . ')';

        $sql = 'SELECT r.' . $codeField . ' AS codigo,'
            . ' COALESCE(t.nombre, r.' . $codeField . ') AS nombre,'
            . ' COUNT(*) AS num_recibos,'
            . ' SUM(r.importe) AS importe_total,'
            . ' SUM((' . $diasCiclo . ') * ABS(r.importe)) / NULLIF(SUM(ABS(r.importe)), 0) AS ciclo_medio,'
            . ' SUM(CASE WHEN r.vencimiento IS NOT NULL THEN (' . $delayExpr . ') * ABS(r.importe) ELSE 0 END)'
            . ' / NULLIF(SUM(CASE WHEN r.vencimiento IS NOT NULL THEN ABS(r.importe) ELSE 0 END), 0) AS retraso_medio'
            . ' FROM ' . $this->db()->escapeColumn($recibosTable) . ' r'
            . ' INNER JOIN ' . $this->db()->escapeColumn($facturasTable) . ' f ON f.idfactura = r.idfactura'
            . ' LEFT JOIN ' . $this->db()->escapeColumn($tercerosTable) . ' t ON t.' . $codeField . ' = r.' . $codeField
            . ' WHERE r.pagado = true'
            . ' AND r.fechapago IS NOT NULL AND f.fecha IS NOT NULL'
            . ' AND r.fechapago >= ' . $this->db()->var2str($desde)
            . ' AND r.fechapago <= ' . $this->db()->var2str($hasta)
            . $this->sqlEmpresaReceiptAlias('r')
            . $this->sqlReceiptExcludes('r')
            . $exclude
            . ' GROUP BY r.' . $codeField . ', t.nombre'
            . ' ORDER BY importe_total DESC'
            . ' LIMIT 100';

        $rows = $this->db()->select($sql);
        $items = [];
        foreach ($rows as $row) {
            $codigo = Tools::noHtml((string) ($row['codigo'] ?? ''));
            $ciclo = isset($row['ciclo_medio']) && $row['ciclo_medio'] !== null && $row['ciclo_medio'] !== ''
                ? round((float) $row['ciclo_medio'], 1)
                : null;
            $retraso = isset($row['retraso_medio']) && $row['retraso_medio'] !== null && $row['retraso_medio'] !== ''
                ? round((float) $row['retraso_medio'], 1)
                : null;
            $importe = (float) ($row['importe_total'] ?? 0);
            $items[] = [
                'codigo' => $codigo,
                'nombre' => Tools::noHtml((string) ($row['nombre'] ?? $codigo)),
                'num_recibos' => (int) ($row['num_recibos'] ?? 0),
                'importe' => $importe,
                'importe_formatted' => Tools::money($importe),
                'ciclo' => $ciclo,
                'ciclo_formatted' => $this->formatDaysKpi($ciclo),
                'retraso' => $retraso,
                'retraso_formatted' => $this->formatDaysKpi($retraso),
                'url' => $codigo !== '' ? $editController . '?code=' . rawurlencode($codigo) : '#',
                'is_cliente' => $isCliente,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $ca = $a['ciclo'] ?? -INF;
            $cb = $b['ciclo'] ?? -INF;
            if ($ca === $cb) {
                return ($b['importe'] <=> $a['importe']);
            }
            return $cb <=> $ca;
        });

        return $items;
    }

    private function formatDaysKpi(?float $value): string
    {
        if ($value === null) {
            return Tools::trans('kpi-sin-datos');
        }

        return number_format($value, 1, ',', '.') . ' d';
    }

    private function formatRatioKpi(?float $value): string
    {
        if ($value === null) {
            return Tools::trans('kpi-sin-datos');
        }

        return number_format($value, 1, ',', '.') . ' %';
    }

    private function formatCmpLine(?float $value, bool $isDays): string
    {
        if ($value === null) {
            return $this->kpiCmpLabel . ': ' . Tools::trans('kpi-sin-datos');
        }

        $formatted = $isDays
            ? number_format($value, 1, ',', '.') . ' d'
            : number_format($value, 1, ',', '.') . ' %';

        return $this->kpiCmpLabel . ': ' . $formatted;
    }

    private function formatPctAtrasoLine(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        return number_format($value, 0, ',', '.') . ' % ' . Tools::trans('kpi-en-atraso');
    }

    private function formatRatioImportesLine(float $cobrado, float $facturado, ?float $ratio): string
    {
        if ($ratio === null && abs($facturado) < 0.00001 && abs($cobrado) < 0.00001) {
            return '';
        }

        return Tools::money($cobrado) . ' / ' . Tools::money($facturado);
    }

    private function loadUsersList(): void
    {
        $this->usersList = [];
        try {
            if ($this->db()->tableExists('users')) {
                $users = User::all([], ['nick' => 'ASC']);
                foreach ($users as $u) {
                    $this->usersList[] = [
                        'nick' => $u->nick,
                        'nombre' => $u->nombre ?: $u->nick
                    ];
                }
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error cargando usuarios: ' . $e->getMessage());
        }
    }

    private function loadAgentesList(): void
    {
        $this->agentesList = [];
        try {
            if (!$this->db()->tableExists('agentes')) {
                return;
            }
            $agentes = Agente::all([Where::eq('debaja', false)], ['nombre' => 'ASC']);
            foreach ($agentes as $ag) {
                if (false === $this->isAgenteActivoParaAgenda($ag)) {
                    continue;
                }
                $this->agentesList[] = [
                    'codagente' => $ag->codagente,
                    'nombre' => $this->etiquetaAgenteAgenda($ag),
                ];
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('Error cargando agentes: ' . $e->getMessage());
        }
    }

    /**
     * Activo para agenda: sin fechabaja real (null/blanco/0/0000-00-00) y sin debaja.
     */
    private function isAgenteActivoParaAgenda(Agente $ag): bool
    {
        if (!empty($ag->debaja)) {
            return false;
        }

        $fechabaja = trim((string) ($ag->fechabaja ?? ''));
        if ($fechabaja === '' || $fechabaja === '0' || $fechabaja === '0000-00-00') {
            return true;
        }

        return false;
    }

    private function etiquetaAgenteAgenda(Agente $ag): string
    {
        $nombre = trim((string) ($ag->nombre ?? ''));
        if ($ag->hasColumn('apellidos')) {
            $apellidos = trim((string) ($ag->apellidos ?? ''));
            if ($apellidos !== '') {
                $nombre = trim($nombre . ' ' . $apellidos);
            }
        }

        return $nombre !== '' ? $nombre : (string) $ag->codagente;
    }

}

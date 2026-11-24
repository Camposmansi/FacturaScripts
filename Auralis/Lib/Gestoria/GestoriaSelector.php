<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Selección de documentos de venta y compra del periodo con permisos por página Edit y «solo sus datos».
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Gestoria;

use Closure;
use FacturaScripts\Core\Model\Base\BusinessDocument;
use FacturaScripts\Core\Where;

final class GestoriaSelector
{
    private const ORDEN = ['fecha' => 'ASC', 'codigo' => 'ASC'];

    private GestoriaCriterios $criterios;

    private ?object $usuario;

    /** @var Closure(string, string): bool */
    private Closure $permiso;

    /**
     * @param object|null $usuario Usuario activo (User del Core).
     * @param Closure(string, string): bool|null $permiso Resolución de permisos (página, permiso); por defecto `$usuario->can()`.
     */
    public function __construct(GestoriaCriterios $criterios, ?object $usuario, ?Closure $permiso = null)
    {
        $this->criterios = $criterios;
        $this->usuario = $usuario;
        $this->permiso = $permiso ?? static function (string $pagina, string $tipoPermiso) use ($usuario): bool {
            return $usuario !== null && method_exists($usuario, 'can') && $usuario->can($pagina, $tipoPermiso);
        };
    }

    /**
     * Tipos seleccionados del ámbito a los que el usuario tiene acceso por su página Edit.
     *
     * @return list<string>
     */
    public function tiposAccesibles(string $ambito): array
    {
        $tipos = [];
        foreach ($this->criterios->tiposDe($ambito) as $tipo) {
            $definicion = GestoriaCriterios::TIPOS[$ambito][$tipo] ?? null;
            if ($definicion !== null && ($this->permiso)($definicion['edit'], 'access')) {
                $tipos[] = $tipo;
            }
        }

        return $tipos;
    }

    /**
     * Etiquetas i18n de los tipos pedidos que el usuario no puede ver.
     *
     * @return list<string>
     */
    public function tiposDenegados(): array
    {
        $denegados = [];
        foreach ([GestoriaCriterios::AMBITO_VENTAS, GestoriaCriterios::AMBITO_COMPRAS] as $ambito) {
            $accesibles = $this->tiposAccesibles($ambito);
            foreach ($this->criterios->tiposDe($ambito) as $tipo) {
                if (false === in_array($tipo, $accesibles, true)) {
                    $denegados[] = GestoriaCriterios::TIPOS[$ambito][$tipo]['etiqueta'];
                }
            }
        }

        return $denegados;
    }

    /**
     * Filtros del periodo, empresa, sujeto, serie, estado de pago y propiedad del documento.
     *
     * @return list<Where>
     */
    public function filtros(string $ambito, string $tipo): array
    {
        $definicion = GestoriaCriterios::TIPOS[$ambito][$tipo];
        /** @var BusinessDocument $modelo */
        $modelo = new $definicion['clase']();

        $filtros = [
            Where::gte('fecha', $this->criterios->desde),
            Where::lte('fecha', $this->criterios->hasta),
        ];

        if ($this->criterios->codEmpresa !== '') {
            $filtros[] = Where::eq('idempresa', (int)$this->criterios->codEmpresa);
        }

        if ($ambito === GestoriaCriterios::AMBITO_VENTAS) {
            if ($this->criterios->codcliente !== '') {
                $filtros[] = Where::eq('codcliente', $this->criterios->codcliente);
            }
            if ($this->criterios->codserie !== '') {
                $filtros[] = Where::eq('codserie', $this->criterios->codserie);
            }
        } elseif ($this->criterios->codproveedor !== '') {
            $filtros[] = Where::eq('codproveedor', $this->criterios->codproveedor);
        }

        if ($tipo === 'facturas' && $modelo->hasColumn('pagada')) {
            if ($this->criterios->estadoPago === GestoriaCriterios::PAGO_PAGADAS) {
                $filtros[] = Where::eq('pagada', true);
            } elseif ($this->criterios->estadoPago === GestoriaCriterios::PAGO_PENDIENTES) {
                $filtros[] = Where::eq('pagada', false);
            }
        }

        if (($this->permiso)($definicion['edit'], 'only-owner-data')) {
            foreach ($this->filtroPropietario($modelo) as $filtro) {
                $filtros[] = $filtro;
            }
        }

        return $filtros;
    }

    /**
     * Recuento e importe por tipo accesible, sin cargar los documentos.
     *
     * @return list<array{ambito: string, tipo: string, etiqueta: string, documentos: int, total: float}>
     */
    public function resumen(): array
    {
        $resumen = [];
        foreach ([GestoriaCriterios::AMBITO_VENTAS, GestoriaCriterios::AMBITO_COMPRAS] as $ambito) {
            foreach ($this->tiposAccesibles($ambito) as $tipo) {
                $definicion = GestoriaCriterios::TIPOS[$ambito][$tipo];
                $clase = $definicion['clase'];
                $filtros = $this->filtros($ambito, $tipo);
                $resumen[] = [
                    'ambito' => $ambito,
                    'tipo' => $tipo,
                    'etiqueta' => $definicion['etiqueta'],
                    'documentos' => $clase::count($filtros),
                    'total' => round($clase::totalSum('total', $filtros), 2),
                ];
            }
        }

        return $resumen;
    }

    public function contarTotal(): int
    {
        $total = 0;
        foreach ($this->resumen() as $linea) {
            $total += $linea['documentos'];
        }

        return $total;
    }

    /**
     * Documentos seleccionados (ventas primero), como mucho $limite.
     *
     * @param string|null $soloAmbito Restringe a un ámbito (p. ej. compras para revisar adjuntos).
     *
     * @return list<array{ambito: string, tipo: string, doc: BusinessDocument}>
     */
    public function documentos(int $limite, ?string $soloAmbito = null): array
    {
        $resultado = [];
        $restante = max(0, $limite);
        foreach ([GestoriaCriterios::AMBITO_VENTAS, GestoriaCriterios::AMBITO_COMPRAS] as $ambito) {
            if ($soloAmbito !== null && $soloAmbito !== $ambito) {
                continue;
            }

            foreach ($this->tiposAccesibles($ambito) as $tipo) {
                if ($restante <= 0) {
                    return $resultado;
                }

                $clase = GestoriaCriterios::TIPOS[$ambito][$tipo]['clase'];
                foreach ($clase::all($this->filtros($ambito, $tipo), self::ORDEN, 0, $restante) as $doc) {
                    $resultado[] = ['ambito' => $ambito, 'tipo' => $tipo, 'doc' => $doc];
                    --$restante;
                }
            }
        }

        return $resultado;
    }

    /**
     * Mismo criterio de propiedad que los listados del Core: agente del usuario o su nick.
     *
     * @return list<Where>
     */
    private function filtroPropietario(BusinessDocument $modelo): array
    {
        $codagente = $this->usuario !== null && property_exists($this->usuario, 'codagente')
            ? (string)$this->usuario->codagente
            : '';
        $nick = $this->usuario !== null && property_exists($this->usuario, 'nick')
            ? (string)$this->usuario->nick
            : '';

        $propios = [];
        if ($modelo->hasColumn('codagente') && $codagente !== '') {
            $propios[] = Where::eq('codagente', $codagente);
        }
        if ($modelo->hasColumn('nick')) {
            $propios[] = $propios === [] ? Where::eq('nick', $nick) : Where::orEq('nick', $nick);
        }

        // con columna de agente pero sin forma de cumplir el criterio: no posee nada
        if ($propios === [] && $modelo->hasColumn('codagente')) {
            return [Where::isNull($modelo->primaryColumn())];
        }

        return $propios === [] ? [] : [Where::sub($propios)];
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Endpoint GET de solo lectura del visor AuralisArbol: valida, comprueba el permiso del proveedor y devuelve los nodos normalizados.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Controller;

use FacturaScripts\Core\Template\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Auralis\Lib\AuralisArbolNormalizador;
use FacturaScripts\Plugins\Auralis\Lib\AuralisArbolPeticion;
use FacturaScripts\Plugins\Auralis\Lib\AuralisArbolRegistry;
use InvalidArgumentException;
use Throwable;

class ArbolAuralis extends Controller
{
    /**
     * El acceso no depende de esta página (sin rol propio): se comprueba contra paginaPermiso()
     * del proveedor una vez autenticado. Sin usuario se responde 401 en JSON.
     *
     * @var bool
     */
    protected $requiresAuth = false;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['submenu'] = '';
        $data['title'] = 'auralis-arbol-titulo';
        $data['icon'] = 'fa-solid fa-sitemap';
        $data['showonmenu'] = false;
        return $data;
    }

    public function run(): void
    {
        parent::run();

        // Solo lectura: liberamos el bloqueo de sesión cuanto antes.
        if (function_exists('session_write_close')) {
            session_write_close();
        }

        if (empty($this->user) || empty($this->user->nick)) {
            $this->json(['ok' => false, 'error' => Tools::trans('auralis-arbol-sesion-caducada')], 401);
            return;
        }

        try {
            $peticion = AuralisArbolPeticion::crear(
                $this->request()->query('proveedor', ''),
                $this->request()->query('id', ''),
                $this->request()->query('modo', ''),
                $this->request()->query('profundidad', '')
            );
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => Tools::trans($e->getMessage())], 400);
            return;
        }

        $proveedor = AuralisArbolRegistry::get($peticion->proveedor);
        if ($proveedor === null) {
            $this->json([
                'ok' => false,
                'error' => Tools::trans('auralis-arbol-proveedor-desconocido', ['%proveedor%' => $peticion->proveedor]),
            ], 404);
            return;
        }

        if (false === $this->user->can($proveedor->paginaPermiso())) {
            $this->json(['ok' => false, 'error' => Tools::trans('auralis-arbol-sin-permiso')], 403);
            return;
        }

        try {
            $nodos = $proveedor->cargar($peticion->id, $peticion->modo, $peticion->profundidad, [
                'nick' => (string)$this->user->nick,
                'idempresa' => (int)($this->user->idempresa ?? 0),
                'maxNodos' => AuralisArbolNormalizador::MAX_NODOS,
            ]);
        } catch (Throwable $e) {
            Tools::log('Auralis')->error('auralis-arbol-error-proveedor', [
                '%proveedor%' => $peticion->proveedor,
                '%error%' => $e->getMessage(),
            ]);
            $error = Tools::trans('auralis-arbol-error');
            if (defined('FS_DEBUG') && FS_DEBUG) {
                $error .= ' ' . $e->getMessage();
            }
            $this->json(['ok' => false, 'error' => $error], 500);
            return;
        }

        $arbol = AuralisArbolNormalizador::normalizar($nodos);
        $this->json([
            'ok' => true,
            'proveedor' => $peticion->proveedor,
            'id' => $peticion->id,
            'modo' => $peticion->modo,
            'profundidad' => $peticion->profundidad,
            'nodos' => $arbol['nodos'],
            'truncado' => $arbol['truncado'],
            'maxNodos' => AuralisArbolNormalizador::MAX_NODOS,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        $response = $this->response();
        $response->headers->set('Cache-Control', 'no-store');
        if ($status !== 200) {
            $response->setHttpCode($status);
        }
        $response->json($payload);
    }
}

<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * @description Semilla CSRF con el nick del usuario en los endpoints Ajax autenticados (Template Controller), para que validen y emitan multireqtoken igual que las páginas PanelController/Base.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Traits;

use FacturaScripts\Dinamic\Lib\MultiRequestProtection;

/**
 * Las páginas del ERP (Base/PanelController) firman el `multireqtoken` con `addSeed($user->nick)`,
 * pero `Core/Template/Controller` valida y emite con la semilla vacía: el primer POST desde la página
 * daría 400 «Petición no válida». Este trait deja la semilla en el nick de la sesión y ofrece la
 * instancia sembrada para emitir el `newToken` de las respuestas JSON.
 *
 * La semilla de MultiRequestProtection es estática: la instancia privada que crea
 * `Template\Controller::validateFormToken()` (su constructor no limpia una semilla ya iniciada)
 * ve la misma semilla que deja `ensureAjaxCsrfSeed()`.
 *
 * El controlador que lo use debe:
 *  - llamar a `ensureAjaxCsrfSeed()` tras autenticar y antes de cualquier `validateFormToken()`;
 *  - emitir el token de sus respuestas con `ajaxCsrfNewToken()` (nunca con un `new MultiRequestProtection()`).
 */
trait AuralisAjaxCsrfSeedTrait
{
    /** @var MultiRequestProtection|null */
    private $ajaxCsrfProteccion = null;

    /**
     * Deja la semilla CSRF en el nick del usuario autenticado (vacía si no hay usuario).
     */
    protected function ensureAjaxCsrfSeed(): MultiRequestProtection
    {
        $nick = isset($this->user) ? (string)($this->user->nick ?? '') : '';

        $proteccion = $this->ajaxCsrfProtection();
        $proteccion->clearSeed();
        $proteccion->addSeed($nick);
        return $proteccion;
    }

    /**
     * Token nuevo firmado con la semilla actual (la del nick tras `ensureAjaxCsrfSeed()`).
     */
    protected function ajaxCsrfNewToken(): string
    {
        return $this->ajaxCsrfProtection()->newToken();
    }

    protected function ajaxCsrfProtection(): MultiRequestProtection
    {
        if (null === $this->ajaxCsrfProteccion) {
            $this->ajaxCsrfProteccion = new MultiRequestProtection();
        }

        return $this->ajaxCsrfProteccion;
    }
}

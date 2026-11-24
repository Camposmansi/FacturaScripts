<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Resultado inmutable de mover una tarjeta Kanban (ok, error o pendiente de confirmación).
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib\Kanban;

final class KanbanResultadoMover
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_NEEDS_CONFIRM = 'needsConfirm';

    public readonly string $status;

    public readonly string $message;

    /** @var array<string, mixed>|null */
    public readonly ?array $card;

    public readonly ?string $summary;

    /** Ruta relativa interna (p. ej. la ficha del documento) que acompaña a un error; nunca una URL externa. */
    public readonly ?string $url;

    /**
     * @param array<string, mixed>|null $card
     */
    private function __construct(string $status, string $message, ?array $card, ?string $summary, ?string $url = null)
    {
        $this->url = $url;
        $this->status = $status;
        $this->message = $message;
        $this->card = $card;
        $this->summary = $summary;
    }

    /**
     * @param array<string, mixed> $card tarjeta actualizada (formato de cardData())
     */
    public static function ok(array $card, string $message = ''): self
    {
        return new self(self::STATUS_OK, $message, $card, null);
    }

    /**
     * @param string|null $url ruta relativa interna a la que el usuario puede ir (p. ej. EditPedidoCliente?code=5)
     */
    public static function error(string $message, ?string $url = null): self
    {
        return new self(self::STATUS_ERROR, $message, null, null, $url);
    }

    public static function needsConfirm(string $summary): self
    {
        return new self(self::STATUS_NEEDS_CONFIRM, '', null, $summary);
    }

    public function isOk(): bool
    {
        return $this->status === self::STATUS_OK;
    }

    /**
     * Respuesta parcial para el JSON del AJAX (sin newToken ni counts, que añade el controlador).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->status === self::STATUS_NEEDS_CONFIRM) {
            return ['status' => $this->status, 'summary' => $this->summary];
        }

        if ($this->status === self::STATUS_ERROR) {
            $error = ['status' => $this->status, 'message' => $this->message];
            if ($this->url !== null && $this->url !== '') {
                $error['url'] = $this->url;
            }

            return $error;
        }

        return ['status' => $this->status, 'message' => $this->message, 'card' => $this->card];
    }
}

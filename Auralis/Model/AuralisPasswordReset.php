<?php
/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.

 * @description Modelo para tokens de restablecimiento de contraseña en Auralis.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Model;

use FacturaScripts\Core\DbQuery;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class AuralisPasswordReset extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $email;

    /** @var string */
    public $token;

    /** @var string */
    public $created_at;

    /** @var string */
    public $expires_at;

    /** @var bool */
    public $used;

    /** @var string|null */
    public $ip;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'auralis_password_resets';
    }

    public function clear(): void
    {
        parent::clear();
        $this->created_at = Tools::dateTime();
        $this->expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $this->used = false;
    }

    public static function clearExpired(): void
    {
        try {
            DbQuery::table(self::tableName())
                ->whereEq('used', true)
                ->orWhereLt('expires_at', Tools::dateTime())
                ->delete();
        } catch (\Throwable $e) {
            Tools::log()->warning('Error al limpiar registros de password reset caducados: ' . $e->getMessage());
        }
    }

    /**
     * Peticiones de recuperación desde una IP en la ventana indicada (rate-limit).
     */
    public static function countRecentByIp(string $ip, int $windowSeconds = 900): int
    {
        $probe = new self();
        if (false === $probe->hasColumn('ip')) {
            return 0;
        }

        $ipClean = trim(Tools::noHtml($ip));
        if ($ipClean === '') {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - max(1, $windowSeconds));

        return DbQuery::table(self::tableName())
            ->whereEq('ip', $ipClean)
            ->whereGte('created_at', $cutoff)
            ->count();
    }
}

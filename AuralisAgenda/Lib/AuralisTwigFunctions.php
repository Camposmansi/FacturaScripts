<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\AuralisAgenda\Lib;

use FacturaScripts\Core\Html;
use Twig\TwigFunction;

final class AuralisTwigFunctions
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Html::addFunction(new TwigFunction('agendaPermisosPagina', static fn(string $page): array => AuralisPermisosPagina::de($page)));
        Html::addFunction(new TwigFunction('agendaAssetVersion', static fn(string $path): string => self::assetVersion($path)));
    }

    private static function assetVersion(string $path): string
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '\\')) {
            return '1.0';
        }
        $file = FS_FOLDER . '/' . $path;
        return is_file($file) ? (string)(int)filemtime($file) : '1.0';
    }

    public static function resetForTests(): void
    {
        self::$registered = false;
    }
}

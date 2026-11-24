<?php

/**
 * @package FacturaScripts
 * @author MansiHost.com
 * @copyright 2026 MansiHost.com. Todos los derechos reservados.
 * @license Licencia privada Auralis. Ver LICENSE.txt
 * Uso sujeto a los términos de LICENSE.txt.
 * @description Lector de novedades orientadas al usuario final. Fusiona novedades.json de todos los plugins activos.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\Auralis\Lib;

use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;

final class AuralisChangelogReader
{
    /** Ruta canónica de Auralis (retrocompat tests / docs). */
    public const DATA_FILE = 'Plugins/Auralis/Assets/Data/novedades.json';

    /** Convención por plugin: Plugins/{Nombre}/Assets/Data/novedades.json */
    public const NOVEDADES_RELATIVE = 'Assets/Data/novedades.json';

    public const CORE_DATA_FILE = 'Plugins/Auralis/Assets/Data/novedades-core.json';

    public const PLUGIN_CORE = 'Core';

    public const MAX_RECENT = 4;

    public const DIGEST_LIMIT = 2;

    /** @deprecated Usar SETTING_KEY_LAST_SEEN_CURSOR. Se mantiene para migración suave. */
    public const SETTING_KEY_LAST_SEEN = 'last_seen_version';

    public const SETTING_KEY_LAST_SEEN_CURSOR = 'last_seen_news_cursor';

    private const CACHE_TTL_SECONDS = 300;

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    private static int $cacheAt = 0;

    private static string $cachePluginsKey = '';

    /**
     * Digest de la campana: última novedad de plugins + tarjeta Core de la versión instalada.
     *
     * @return list<array{
     *     plugin: string,
     *     version: string,
     *     date: string,
     *     title: string,
     *     summary: string,
     *     badges: list<array{type: string, label: string}>,
     *     items: list<array{label: string, text: string}>
     * }>
     */
    public static function entries(int $limit = self::DIGEST_LIMIT): array
    {
        $limit = max(1, min(self::DIGEST_LIMIT, $limit));
        $raw = self::loadEntries();
        if ($raw === []) {
            return [];
        }

        $pluginLatest = null;
        $coreExact = null;
        foreach ($raw as $entry) {
            $plugin = (string) ($entry['plugin'] ?? '');
            if ($plugin === self::PLUGIN_CORE) {
                if ($coreExact === null && self::isExactInstalledCoreVersion((string) ($entry['version'] ?? ''))) {
                    $coreExact = $entry;
                }
                continue;
            }
            if ($pluginLatest === null) {
                $pluginLatest = $entry;
            }
            if ($pluginLatest !== null && $coreExact !== null) {
                break;
            }
        }

        $out = [];
        if ($pluginLatest !== null) {
            $out[] = $pluginLatest;
        }
        if ($coreExact !== null) {
            $out[] = $coreExact;
        }

        if ($out === []) {
            $out = array_slice($raw, 0, $limit);
        } else {
            $out = array_slice($out, 0, $limit);
        }

        return self::presentEntries($out);
    }

    /**
     * Histórico completo ordenado por fecha DESC (Offcanvas «Ver todas»).
     * Auralis guarda la prosa directamente en novedades.json. Se mantiene la resolución
     * de claves de traducción por compatibilidad con catálogos de otros plugins.
     *
     * @return list<array{
     *     plugin: string,
     *     version: string,
     *     date: string,
     *     title: string,
     *     summary: string,
     *     badges: list<array{type: string, label: string}>,
     *     items: list<array{label: string, text: string}>
     * }>
     */
    public static function allEntries(): array
    {
        return self::presentEntries(self::loadEntries());
    }

    public static function latestVersion(): string
    {
        $entries = self::loadEntries();
        if ($entries === []) {
            return '';
        }

        return trim((string)($entries[0]['version'] ?? ''));
    }

    /**
     * Cursor de la novedad más reciente (multi-plugin). Formato: date|plugin|version|title
     */
    public static function latestCursor(): string
    {
        $entries = self::loadEntries();
        if ($entries === []) {
            return '';
        }

        return self::entryCursor($entries[0]);
    }

    /**
     * @param array{
     *     plugin?: string,
     *     version?: string,
     *     date?: string,
     *     title?: string
     * } $entry
     */
    public static function entryCursor(array $entry): string
    {
        return implode('|', [
            trim((string)($entry['date'] ?? '')),
            trim((string)($entry['plugin'] ?? '')),
            trim((string)($entry['version'] ?? '')),
            trim((string)($entry['title'] ?? '')),
        ]);
    }

    public static function hasUnreadNews(string $nick): bool
    {
        $cursor = self::latestCursor();
        if ($cursor === '') {
            return false;
        }

        $group = self::userSettingsGroup($nick);
        if ($group === '') {
            return false;
        }

        $seenCursor = trim((string)Tools::settings($group, self::SETTING_KEY_LAST_SEEN_CURSOR, ''));
        if ($seenCursor !== '') {
            return $cursor !== $seenCursor;
        }

        // Migración suave desde last_seen_version (solo Auralis mono-plugin).
        $seenVersion = trim((string)Tools::settings($group, self::SETTING_KEY_LAST_SEEN, ''));
        if ($seenVersion === '') {
            return true;
        }

        $top = self::loadEntries()[0] ?? null;
        if ($top === null) {
            return false;
        }

        $plugin = trim((string)($top['plugin'] ?? ''));
        $version = trim((string)($top['version'] ?? ''));
        if ($plugin === 'Auralis' && $version === $seenVersion) {
            return false;
        }

        return true;
    }

    public static function markNewsSeen(string $nick): bool
    {
        $group = self::userSettingsGroup($nick);
        if ($group === '') {
            return false;
        }

        $cursor = self::latestCursor();
        if ($cursor === '') {
            return false;
        }

        Tools::settingsSet($group, self::SETTING_KEY_LAST_SEEN_CURSOR, $cursor);
        // Mantener versión del top por compat con respuestas AJAX antiguas.
        $version = self::latestVersion();
        if ($version !== '') {
            Tools::settingsSet($group, self::SETTING_KEY_LAST_SEEN, $version);
        }

        return Tools::settingsSave();
    }

    public static function userSettingsGroup(string $nick): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($nick)) ?? '';
        if ($safe === '') {
            return '';
        }

        return 'auralis_user_' . $safe;
    }

    /** Ruta relativa FS_FOLDER de novedades de un plugin. */
    public static function pluginDataFile(string $pluginName): string
    {
        $safe = basename(trim($pluginName));
        if ($safe === '' || $safe === '.' || $safe === '..') {
            return '';
        }

        return 'Plugins/' . $safe . '/' . self::NOVEDADES_RELATIVE;
    }

    /** @internal Solo para tests. */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$cacheAt = 0;
        self::$cachePluginsKey = '';
    }

    /**
     * @return list<array{
     *     plugin: string,
     *     version: string,
     *     date: string,
     *     title: string,
     *     summary: string,
     *     badges: list<array{type: string, label: string}>,
     *     items: list<array{label: string, text: string}>
     * }>
     */
    private static function loadEntries(): array
    {
        $now = time();
        $pluginsKey = self::enabledPluginsCacheKey();

        if (
            self::$cache !== null
            && self::$cachePluginsKey === $pluginsKey
            && ($now - self::$cacheAt) < self::CACHE_TTL_SECONDS
        ) {
            return self::$cache['entries'] ?? [];
        }

        $entries = self::collectFromEnabledPlugins();
        foreach (self::collectFromCoreCatalog() as $row) {
            $entries[] = $row;
        }
        $entries = self::deduplicate($entries);

        usort($entries, static function (array $a, array $b): int {
            $byDate = strcmp((string)$b['date'], (string)$a['date']);
            if ($byDate !== 0) {
                return $byDate;
            }

            $byVersion = version_compare((string)$b['version'], (string)$a['version']);
            if ($byVersion !== 0) {
                return $byVersion;
            }

            return strcmp((string)$a['plugin'], (string)$b['plugin']);
        });

        self::$cache = ['entries' => $entries];
        self::$cacheAt = $now;
        self::$cachePluginsKey = $pluginsKey;

        return $entries;
    }

    private static function enabledPluginsCacheKey(): string
    {
        if (!class_exists(Plugins::class) || !defined('FS_FOLDER')) {
            return 'auralis-only|' . self::installedCoreVersionString();
        }

        try {
            $names = Plugins::enabled();
        } catch (\Throwable $e) {
            return 'auralis-only|' . self::installedCoreVersionString();
        }

        if ($names === []) {
            return 'auralis-only|' . self::installedCoreVersionString();
        }

        sort($names);
        return implode(',', $names) . '|' . self::installedCoreVersionString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function collectFromEnabledPlugins(): array
    {
        $folder = defined('FS_FOLDER') ? (string)FS_FOLDER : '';
        if ($folder === '') {
            return [];
        }

        $pluginNames = self::resolvePluginNames();
        $entries = [];

        foreach ($pluginNames as $name) {
            $relative = self::pluginDataFile($name);
            if ($relative === '') {
                continue;
            }

            $path = $folder . '/' . $relative;
            if (!is_file($path)) {
                continue;
            }

            foreach (self::fromJsonFile($path, $name) as $row) {
                $entries[] = $row;
            }
        }

        return $entries;
    }

    /**
     * Catálogo Core curado: solo versiones <= la instalada.
     *
     * @return list<array<string, mixed>>
     */
    private static function collectFromCoreCatalog(): array
    {
        $folder = defined('FS_FOLDER') ? (string) FS_FOLDER : '';
        if ($folder === '') {
            return [];
        }

        $path = $folder . '/' . self::CORE_DATA_FILE;
        if (!is_file($path)) {
            return [];
        }

        $installed = self::installedCoreVersionFloat();
        $out = [];
        foreach (self::fromJsonFile($path, self::PLUGIN_CORE) as $row) {
            $row['plugin'] = self::PLUGIN_CORE;
            if (!self::isCoreVersionVisible((string) ($row['version'] ?? ''), $installed)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Visible si la versión del catálogo no es posterior a la instalada.
     */
    public static function isCoreVersionVisible(string $version, ?float $installed = null): bool
    {
        $version = trim($version);
        if ($version === '') {
            return false;
        }

        $installed ??= self::installedCoreVersionFloat();
        if ($installed <= 0.0) {
            return false;
        }

        return (float) $version <= $installed + 1.0e-6;
    }

    public static function isExactInstalledCoreVersion(string $version, ?float $installed = null): bool
    {
        $version = trim($version);
        if ($version === '') {
            return false;
        }

        $installed ??= self::installedCoreVersionFloat();
        return abs((float) $version - $installed) < 1.0e-6;
    }

    /**
     * String de versión Core alineado con el catálogo (sin castear el float a ciegas).
     */
    public static function installedCoreVersionString(?float $installed = null): string
    {
        $installed ??= self::installedCoreVersionFloat();
        if ($installed <= 0.0) {
            return '';
        }

        $best = '0';
        foreach (self::coreCatalogVersionStrings() as $candidate) {
            $asFloat = (float) $candidate;
            if ($asFloat <= $installed + 1.0e-6 && version_compare($candidate, $best, '>')) {
                $best = $candidate;
            }
        }

        return $best === '0' ? '' : $best;
    }

    private static function installedCoreVersionFloat(): float
    {
        if (!class_exists(Kernel::class)) {
            return 0.0;
        }

        try {
            return (float) Kernel::version();
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * @return list<string>
     */
    private static function coreCatalogVersionStrings(): array
    {
        $folder = defined('FS_FOLDER') ? (string) FS_FOLDER : '';
        $path = $folder !== '' ? $folder . '/' . self::CORE_DATA_FILE : '';
        if ($path === '' || !is_file($path)) {
            $local = dirname(__DIR__) . '/Assets/Data/novedades-core.json';
            $path = is_file($local) ? $local : '';
        }
        if ($path === '') {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = $decoded['entries'] ?? [];
        if (!is_array($list)) {
            return [];
        }

        $versions = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = trim((string) ($row['version'] ?? ''));
            if ($v !== '') {
                $versions[] = $v;
            }
        }

        return array_values(array_unique($versions));
    }

    /**
     * @return list<string>
     */
    private static function resolvePluginNames(): array
    {
        if (class_exists(Plugins::class)) {
            try {
                $enabled = Plugins::enabled();
                if ($enabled !== []) {
                    return $enabled;
                }
            } catch (\Throwable $e) {
                // Fallback: al menos Auralis.
            }
        }

        return ['Auralis'];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function deduplicate(array $entries): array
    {
        $seen = [];
        $out = [];
        foreach ($entries as $row) {
            $key = self::entryCursor($row);
            if ($key === '|||' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fromJsonFile(string $path, string $defaultPlugin = 'Auralis'): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = $decoded['entries'] ?? $decoded;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $badges = [];
            foreach (($row['badges'] ?? $row['tags'] ?? []) as $badge) {
                if (is_string($badge)) {
                    $trimmed = trim($badge);
                    if ($trimmed === '') {
                        continue;
                    }
                    $badges[] = [
                        'type' => self::normalizeBadgeType($trimmed),
                        'label' => Tools::noHtml($trimmed),
                    ];
                    continue;
                }
                if (!is_array($badge)) {
                    continue;
                }
                $label = trim((string) ($badge['label'] ?? ''));
                $typeRaw = trim((string) ($badge['type'] ?? ''));
                if ($label === '' && $typeRaw === '') {
                    continue;
                }
                $badges[] = [
                    'type' => self::normalizeBadgeType($typeRaw !== '' ? $typeRaw : $label),
                    'label' => Tools::noHtml($label),
                ];
            }

            $items = [];
            foreach (($row['items'] ?? []) as $item) {
                if (is_string($item)) {
                    $text = trim($item);
                    if ($text !== '') {
                        $items[] = ['label' => '', 'text' => Tools::noHtml($text)];
                    }
                    continue;
                }
                if (!is_array($item)) {
                    continue;
                }
                $label = trim((string) ($item['label'] ?? ''));
                $text = trim((string) ($item['text'] ?? $item['description'] ?? ''));
                if ($label === '' && $text === '') {
                    continue;
                }
                $items[] = [
                    'label' => Tools::noHtml($label),
                    'text' => Tools::noHtml($text),
                ];
            }

            $plugin = trim((string) ($row['plugin'] ?? ''));
            if ($plugin === '') {
                $plugin = $defaultPlugin;
            }

            $out[] = [
                'plugin' => Tools::noHtml($plugin),
                'version' => Tools::noHtml(trim((string) ($row['version'] ?? ''))),
                'date' => Tools::noHtml(trim((string) ($row['date'] ?? ''))),
                'title' => Tools::noHtml($title),
                'summary' => Tools::noHtml(trim((string) ($row['summary'] ?? ''))),
                'badges' => $badges,
                'items' => $items,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function presentEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $out[] = self::presentEntry($entry);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function presentEntry(array $entry): array
    {
        $badges = [];
        foreach (($entry['badges'] ?? []) as $badge) {
            if (!is_array($badge)) {
                continue;
            }
            $type = self::normalizeBadgeType((string) ($badge['type'] ?? 'feature'));
            $badges[] = [
                'type' => $type,
                'label' => self::badgeLabel($type),
            ];
        }

        $items = [];
        foreach (($entry['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'label' => self::resolveTexto((string) ($item['label'] ?? '')),
                'text' => self::resolveTexto((string) ($item['text'] ?? '')),
            ];
        }

        return [
            'plugin' => (string) ($entry['plugin'] ?? ''),
            'version' => (string) ($entry['version'] ?? ''),
            'date' => (string) ($entry['date'] ?? ''),
            'title' => self::resolveTexto((string) ($entry['title'] ?? '')),
            'summary' => self::resolveTexto((string) ($entry['summary'] ?? '')),
            'badges' => $badges,
            'items' => $items,
        ];
    }

    /**
     * Resuelve texto de novedad: prosa literal por defecto y claves i18n antiguas
     * para conservar compatibilidad con catálogos de terceros.
     *
     * @internal También usable desde tests.
     */
    public static function resolveTexto(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return '';
        }

        // Clave FS típica: al menos un guion (evita palabras sueltas tipo «Cobrado»).
        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/i', $t) === 1) {
            $t = Tools::trans($t);
        }

        return Tools::noHtml($t);
    }

    /**
     * Etiqueta humana del badge según type (i18n Auralis).
     *
     * @internal También usable desde tests.
     */
    public static function badgeLabel(string $type): string
    {
        $normalized = self::normalizeBadgeType($type);
        $key = match ($normalized) {
            'improvement' => 'auralis-novedad-badge-improvement',
            'legal' => 'auralis-novedad-badge-legal',
            'tip' => 'auralis-novedad-badge-tip',
            default => 'auralis-novedad-badge-feature',
        };

        return Tools::noHtml(Tools::trans($key));
    }

    private static function normalizeBadgeType(string $raw): string
    {
        $key = mb_strtolower(trim($raw));
        $key = str_replace(['🚀', '⚡', '⚖️', '💡'], '', $key);
        $key = trim($key);

        if (str_contains($key, 'feature') || str_contains($key, 'nueva') || str_contains($key, 'funcion') || str_contains($key, 'función')) {
            return 'feature';
        }
        if (str_contains($key, 'improvement') || str_contains($key, 'mejora') || str_contains($key, 'productividad') || str_contains($key, 'ui')) {
            return 'improvement';
        }
        if (str_contains($key, 'legal') || str_contains($key, 'fiscal') || str_contains($key, 'verifactu')) {
            return 'legal';
        }
        if (str_contains($key, 'tip') || str_contains($key, 'consejo') || str_contains($key, 'truco')) {
            return 'tip';
        }

        return 'feature';
    }
}

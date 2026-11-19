<?php

/**
 * The teme-types file a plugin declares under `contributes.types`
 * (docs/architecture/plugins.md, *Content types from a plugin*): a path
 * inside its own folder to a version 1 file, read and checked for its shape
 * as the plugin is found, and read whole when it is enabled or updated.
 *
 * @package Teme
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Types;

use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginFileUnreadable;
use JsonException;

final class PluginTypesFile
{
    /** A path inside the plugin: folders and a name of letters, digits, `.`, `_` and `-`, ending .json. */
    public const string PATH = '#^(?:[A-Za-z0-9_][A-Za-z0-9_.-]*/)*[A-Za-z0-9_][A-Za-z0-9_.-]*\.json$#D';

    /**
     * The path the manifest names under `contributes.types`, as written, or
     * null when it declares no types.
     */
    public static function declared(Manifest $manifest): ?string
    {
        $path = $manifest->contributes()['types'] ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * Whether the plugin's types file differs from the one it last applied,
     * $applied being its row's `types`: an update brought another, or
     * dropped it, and it waits to be applied as migrations do. Opens no file.
     *
     * @param  array<string, mixed>  $applied
     */
    public static function waiting(FoundPlugin $plugin, array $applied): bool
    {
        $declared = $plugin->manifest === null ? null : self::declared($plugin->manifest);
        $version = $applied['version'] ?? null;

        if ($declared === null) {
            return $version !== null;
        }

        return $plugin->typesVersion !== null && $plugin->typesVersion !== $version;
    }

    /**
     * Why `contributes.types` can't be read as a path inside the plugin, or
     * null when it can; what the manifest's validator says, opening no file.
     */
    public static function pathProblem(mixed $path): ?string
    {
        if (! is_string($path) || preg_match(self::PATH, $path) !== 1 || str_contains($path, '..')) {
            return __('contributes.types must be the path of a teme-types file inside the plugin, such as types/teme-types.json.');
        }

        return null;
    }

    /**
     * The plugin's types file read from $root: its contents' version and why
     * it can't be used, or null for both when it declares none. Only its
     * shape is checked here; enabling checks the rest against the site.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @return array{version: string|null, problems: list<string>}
     */
    public static function inspect(string $root, array $manifest): array
    {
        $declared = self::declared(new Manifest($manifest));

        if ($declared === null || self::pathProblem($declared) !== null) {
            return ['version' => null, 'problems' => []];
        }

        try {
            [$file, $contents] = self::readFrom($root, $declared);
        } catch (PluginTypesUnreadable $unreadable) {
            return ['version' => null, 'problems' => [$unreadable->getMessage()]];
        }

        return ['version' => self::version($contents), 'problems' => self::shapeProblems($declared, $file)];
    }

    /**
     * The decoded file and the version of what it holds.
     *
     * @return array{file: array<mixed>, version: string}
     *
     * @throws PluginTypesUnreadable when it is missing, unreadable, not JSON or not a version 1 file
     */
    public static function read(string $root, Manifest $manifest): array
    {
        $declared = self::declared($manifest);
        $problem = self::pathProblem($declared);

        if ($problem !== null || $declared === null) {
            throw new PluginTypesUnreadable($problem ?? __('The plugin declares no types file.'));
        }

        [$file, $contents] = self::readFrom($root, $declared);
        $problems = self::shapeProblems($declared, $file);

        if ($problems !== []) {
            throw new PluginTypesUnreadable($problems[0]);
        }

        return ['file' => $file, 'version' => self::version($contents)];
    }

    /**
     * @return array{0: array<mixed>, 1: string}
     *
     * @throws PluginTypesUnreadable
     */
    private static function readFrom(string $root, string $declared): array
    {
        $path = rtrim($root, '/\\').'/'.$declared;
        $real = realpath($path);
        $base = realpath($root);

        if ($real === false || $base === false || ! is_file($real) || ! str_starts_with($real, rtrim($base, '/\\').DIRECTORY_SEPARATOR)) {
            throw new PluginTypesUnreadable(__('contributes.types names :path, which the plugin doesn’t have.', ['path' => $declared]));
        }

        try {
            $contents = PluginFileUnreadable::read($base, $real);
        } catch (PluginFileUnreadable) {
            throw new PluginTypesUnreadable(__(':path can’t be read.', ['path' => $declared]));
        }

        try {
            $file = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new PluginTypesUnreadable(__(':path is not valid JSON: :error.', ['path' => $declared, 'error' => $invalid->getMessage()]));
        }

        if (! is_array($file) || array_is_list($file)) {
            throw new PluginTypesUnreadable(__(':path must hold one JSON object.', ['path' => $declared]));
        }

        return [$file, $contents];
    }

    /**
     * @param  array<mixed>  $file
     *
     * @return list<string>
     */
    private static function shapeProblems(string $declared, array $file): array
    {
        if (($file['format'] ?? null) !== 'teme-types' || ($file['version'] ?? null) !== 1) {
            return [__(':path isn’t a teme-types file: it needs "format": "teme-types" and "version": 1, as Settings › Types exports.', ['path' => $declared])];
        }

        if (! is_array($file['types'] ?? null) || ! array_is_list($file['types'])) {
            return [__(':path needs a list of types under [types].', ['path' => $declared])];
        }

        return [];
    }

    private static function version(string $contents): string
    {
        return hash('xxh128', $contents);
    }
}

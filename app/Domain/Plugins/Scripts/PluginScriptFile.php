<?php

/**
 * Serves a plugin's declared script itself: finds the file a manifest declares under
 * contributes.scripts, and only for an enabled plugin whose owner agreed to site.scripts. The file
 * gets the stylesheets' package checks, and its hash and compressed size are kept per plugin, version
 * and file, so a page reads a script's metadata without reading the file. A script is served as it
 * is; Teme does not wrap or rewrite it.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Scripts;

use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Styles\PluginStylesheet;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Teme\Contracts\Plugins\Capability;

final class PluginScriptFile
{
    public function __construct(
        private readonly PluginRecords $records,
        private readonly PluginRegistry $registry,
        private readonly PluginStylesheet $files,
    ) {}

    /**
     * Every enabled plugin, by code, whose owner agreed to site.scripts: its Composer name, its version,
     * its manifest and its consent. Null for anything else, so a script of a plugin that may not run
     * scripts is never served or linked.
     *
     * @return array<string, array{name: string, version: string, manifest: Manifest, consent: array<string, mixed>}>
     */
    public function servedAll(): array
    {
        $served = [];

        foreach ($this->records->enabled() as $record) {
            $consent = (array) $record->consent;
            $granted = array_values(array_filter((array) ($consent['capabilities'] ?? []), is_string(...)));
            $manifest = $this->registry->all()[(string) $record->name]->manifest ?? null;

            if (in_array(Capability::SiteScripts->value, $granted, true) && $manifest !== null) {
                $served[(string) $record->code] = ['name' => (string) $record->name, 'version' => $manifest->version(), 'manifest' => $manifest, 'consent' => $consent];
            }
        }

        return $served;
    }

    /**
     * What served() gives for one plugin code, or null when it is not served.
     *
     * @return array{name: string, version: string, manifest: Manifest, consent: array<string, mixed>}|null
     */
    public function served(string $code): ?array
    {
        return $this->servedAll()[$code] ?? null;
    }

    /**
     * The hash, the compressed size and any refusal of an agreed plugin's declared script, or null when the
     * plugin is not served or doesn't declare the file. A refusal is kept with the other facts: a link on
     * the path or a file outside the package is the plugin's fault, and the page answers it without the script.
     *
     * @param  array{name: string, version: string, consent: array<string, mixed>}|null  $served  what served() gave, when the caller has it
     *
     * @return array{name: string, hash: string|null, gzip: int, refused: string|null}|null
     */
    public function verdict(string $code, string $file, ?array $served = null): ?array
    {
        $served ??= $this->served($code);

        if ($served === null || ! $this->declares($served['manifest'], $file)) {
            return null;
        }

        $key = 'plugin-scripts-verdict:'.$served['name'].':'.$served['version'].':'.$file;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $source = $this->files->source($served['name'], $file);
        } catch (InvalidArgumentException $refused) {
            return ['name' => $served['name'], 'hash' => null, 'gzip' => 0, 'refused' => $refused->getMessage()];
        }

        if ($source === null) {
            return null;
        }

        $text = (string) file_get_contents($source);
        $verdict = ['name' => $served['name'], 'hash' => substr(hash('sha256', $text), 0, 12), 'gzip' => strlen((string) gzencode($text, 9)), 'refused' => null];
        Cache::forever($key, $verdict);

        return $verdict;
    }

    /**
     * The text of an agreed plugin's declared script, or null when it is not served. Read only when a
     * request asks for the script itself, never to draw a page.
     *
     * @param  array{name: string, version: string, manifest: Manifest, consent: array<string, mixed>}|null  $served
     *
     * @throws InvalidArgumentException when the file is a link or sits outside the package
     */
    public function text(string $code, string $file, ?array $served = null): ?string
    {
        $served ??= $this->served($code);

        if ($served === null || ! $this->declares($served['manifest'], $file)) {
            return null;
        }

        $source = $this->files->source($served['name'], $file);

        return $source === null ? null : (string) file_get_contents($source);
    }

    /**
     * Whether the plugin's manifest declares this file under contributes.scripts.
     */
    private function declares(Manifest $manifest, string $file): bool
    {
        return in_array($file, array_column(DeclaredScripts::entries($manifest->contributes()), 'file'), true);
    }
}

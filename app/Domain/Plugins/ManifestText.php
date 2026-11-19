<?php

/**
 * The words a person reads from one plugin's manifest, in their language
 * (docs/plans/plugin-sockets-2.md, C2): looked up by the English text in
 * that plugin's own lang/<locale>.json, never in core's or another
 * plugin's, and the English text when it has no line.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins;

use Throwable;

final class ManifestText
{
    /** @var array<string, array<string, string>> each locale's lines, read once */
    private array $lines = [];

    private function __construct(private readonly ?string $path) {}

    /**
     * The manifest words of the plugin with this code; one Teme can't find
     * answers every word in English.
     */
    public static function for(string $code): self
    {
        foreach (app(PluginRegistry::class)->all() as $plugin) {
            if ($plugin->code === $code) {
                return new self($plugin->path);
            }
        }

        return new self(null);
    }

    /**
     * Returns the plugin's line for this English text in the locale, or the
     * English text when its file has none.
     */
    public function text(string $english, string $locale): string
    {
        if ($english === '') {
            return $english;
        }

        $line = ($this->lines[$locale] ??= $this->read($locale))[$english] ?? null;

        return $line === null || $line === '' ? $english : $line;
    }

    /**
     * The plugin's lines for the locale; none for a locale that isn't a
     * plain language tag, or a file that is missing or can't be read.
     *
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        if ($this->path === null || preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            return [];
        }

        $file = $this->path.'/lang/'.$locale.'.json';

        if (! is_file($file)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }

        $lines = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $line) {
            if (is_string($line)) {
                $lines[(string) $key] = $line;
            }
        }

        return $lines;
    }
}

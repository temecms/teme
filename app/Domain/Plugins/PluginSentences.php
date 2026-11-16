<?php

declare(strict_types=1);

namespace App\Domain\Plugins;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\Translator;
use Throwable;

/**
 * The sentences started plugins add to the translator, from their own
 * lang/<locale>.json (docs/plans/plugin-sockets-1.md, A-W14). A plugin
 * only adds: a line is kept only for a key its own code passes to __(),
 * that none of core's lang/*.json holds, and that no plugin started before
 * it claimed. They answer only a key core has no line for, and never while
 * core's own words are asked for, on the consent and refusal screens.
 */
final class PluginSentences
{
    /** @var list<array{name: string, path: string, keys: array<string, true>}> started plugins with a lang folder, in the order they started */
    private array $plugins = [];

    /** @var array<string, array<string, string>> each locale's lines, read once */
    private array $lines = [];

    /** @var array<string, true>|null every key of core's own language files */
    private ?array $core = null;

    private int $coreOnly = 0;

    public function __construct(private readonly CodeScan $scan) {}

    /**
     * Adds a started plugin's language folder, read only when a locale is
     * first asked for, and has the translator ask these sentences for a
     * key core has no line for.
     */
    public function add(FoundPlugin $plugin, Translator $translator): void
    {
        if (! is_dir($plugin->path.'/lang')) {
            return;
        }

        $composer = is_file($plugin->path.'/composer.json') ? json_decode((string) file_get_contents($plugin->path.'/composer.json'), true) : null;
        $keys = array_fill_keys($this->scan->sentences($plugin->path, is_array($composer) ? $composer : null), true);

        $this->plugins[] = ['name' => $plugin->name, 'path' => $plugin->path, 'keys' => $keys];
        $this->lines = [];

        $translator->handleMissingKeysUsing(fn (string $key, array $replace, ?string $locale): ?string => $this->line($key, $locale ?? $translator->getLocale()));
    }

    /**
     * Returns a plugin's line for this key, or null: always null while
     * core's own words are asked for.
     */
    public function line(string $key, string $locale): ?string
    {
        if ($this->coreOnly > 0 || $this->plugins === []) {
            return null;
        }

        return ($this->lines[$locale] ??= $this->read($locale))[$key] ?? null;
    }

    /**
     * Runs $words with core's own words only, as the consent, password and
     * refusal screens are drawn.
     *
     * @template T
     *
     * @param  Closure(): T  $words
     *
     * @return T
     */
    public function coreOnly(Closure $words): mixed
    {
        $this->coreOnly++;

        try {
            return $words();
        } finally {
            $this->coreOnly--;
        }
    }

    /**
     * Each started plugin's lines for the locale, filtered: its own keys,
     * none of core's, none an earlier plugin claimed. A file that can't be
     * read adds nothing.
     *
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        if (preg_match('/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
            return [];
        }

        $core = $this->core ??= $this->coreKeys();
        $lines = [];

        foreach ($this->plugins as $plugin) {
            $file = $plugin['path'].'/lang/'.$locale.'.json';

            try {
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR) : [];
            } catch (Throwable $failure) {
                report($failure);

                continue;
            }

            foreach (is_array($decoded) ? $decoded : [] as $key => $line) {
                $key = (string) $key;

                if (is_string($line) && isset($plugin['keys'][$key]) && ! isset($core[$key]) && ! array_key_exists($key, $lines)) {
                    $lines[$key] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * Every key any of core's own lang/*.json holds.
     *
     * @return array<string, true>
     */
    private function coreKeys(): array
    {
        $keys = [];

        foreach (File::glob(lang_path('*.json')) as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            foreach (is_array($decoded) ? array_keys($decoded) : [] as $key) {
                $keys[(string) $key] = true;
            }
        }

        return $keys;
    }
}

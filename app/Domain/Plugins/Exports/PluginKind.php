<?php

/**
 * One export a plugin declares, wrapped as an export kind named
 * `plugin_{code}_{id}`: the plugin's lines are read a page at a time and
 * checked before they are written. A plugin that fails is reported, never
 * allowed to stop the export.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Exports;

use App\Domain\Export\ExportContext;
use App\Domain\Export\ExportKind;
use App\Domain\Plugins\Seo\SeoPlugin;
use App\Domain\Plugins\Seo\SeoPlugins;
use App\Domain\Plugins\Seo\SocketAnswerRefused;
use Closure;
use Teme\Contracts\Export\HasExportKinds;
use Teme\Contracts\Export\PluginExportKind;
use Throwable;

final class PluginKind implements ExportKind
{
    /** The most pages one export may read: a plugin that never says it is done is stopped. */
    public const int MOST_PAGES = 100_000;

    private ?PluginExportKind $kind = null;

    /**
     * @param  Closure(): array<array-key, mixed>  $given  what the plugin's exportKinds() answers, asked once
     */
    public function __construct(
        private readonly SeoPlugin $plugin,
        private readonly SeoPlugins $plugins,
        private readonly string $id,
        private readonly string $noun,
        private readonly Closure $given,
    ) {}

    public function name(): string
    {
        return 'plugin_'.str_replace('-', '_', $this->plugin->code).'_'.str_replace('-', '_', $this->id);
    }

    public function version(): int
    {
        return max(1, $this->resolve()->version());
    }

    public function description(): string
    {
        [, $many] = explode('|', $this->noun);

        return "{$this->plugin->label}’s {$many}, as the plugin hands them over.";
    }

    public function noun(): string
    {
        return $this->noun;
    }

    public function fields(): array
    {
        return $this->resolve()->fields();
    }

    public function lines(ExportContext $context): iterable
    {
        $kind = $this->resolve();
        $after = null;

        for ($pages = 1; $pages <= self::MOST_PAGES; $pages++) {
            $answer = self::asked($kind, $after);
            $lines = is_array($answer) ? ($answer['lines'] ?? null) : null;
            $next = is_array($answer) ? ($answer['next'] ?? null) : null;

            if (! is_array($answer) || ! is_array($lines) || ! array_is_list($lines) || count($lines) > PluginExportKind::MOST_LINES || ($next !== null && (! is_string($next) || $next === ''))) {
                throw new SocketAnswerRefused('A page of lines must be {"lines": a list of at most 1000 lines, "next": a text or null}.');
            }

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    throw new SocketAnswerRefused('A line must be an array.');
                }

                yield $line;
            }

            if ($next === null) {
                return;
            }

            if ($next === $after) {
                throw new SocketAnswerRefused('A page said the page after it starts where it did.');
            }

            $after = $next;
        }

        throw new SocketAnswerRefused('The export never said it was done.');
    }

    /**
     * Records the plugin's fault and returns the words the export's manifest
     * leaves out for this file, which never carry the fault's own text.
     *
     * @return array{what: string, why: string}
     */
    public function failed(Throwable $failure): array
    {
        $this->plugins->fault($this->plugin, 'export', HasExportKinds::class, $failure);

        return [
            'what' => "{$this->plugin->label}’s ".explode('|', $this->noun)[1]." ({$this->name()}.ndjson)",
            'why' => 'The plugin couldn’t hand them over, so there is no file for them. The fault is recorded under Plugins.',
        ];
    }

    /**
     * What the manifest says about this file, for the format reference.
     *
     * @return array{kind: string, plugin: string, description: string, fields: array<string, string>}
     */
    public function toManifest(): array
    {
        return ['kind' => $this->name(), 'plugin' => $this->plugin->name, 'description' => $this->description(), 'fields' => $this->fields()];
    }

    /**
     * Whatever the plugin returned, which its contract's types don't promise.
     */
    private static function asked(PluginExportKind $kind, ?string $after): mixed
    {
        return $kind->lines($after);
    }

    private function resolve(): PluginExportKind
    {
        if ($this->kind !== null) {
            return $this->kind;
        }

        $kind = ($this->given)()[$this->id] ?? null;

        if (! $kind instanceof PluginExportKind) {
            throw new SocketAnswerRefused("The plugin has no export “{$this->id}”.");
        }

        return $this->kind = $kind;
    }
}

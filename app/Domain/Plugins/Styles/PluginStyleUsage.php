<?php

/**
 * The plugin outputs one public page drew, each as its plugin's code and its
 * output (`card:<id>` or `insertion:<id>`), recorded while the body
 * renders, so the document head links only those stylesheets. It is scoped
 * to a request, and start() empties it for each page.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Plugins\Styles;

final class PluginStyleUsage
{
    /** @var array<string, array{code: string, output: string}> */
    private array $entries = [];

    /**
     * Empties it, for a page about to be built.
     */
    public function start(): void
    {
        $this->entries = [];
    }

    /**
     * Records one output of a plugin as drawn on the page.
     */
    public function record(string $code, string $output): void
    {
        $this->entries[$code.':'.$output] = ['code' => $code, 'output' => $output];
    }

    /**
     * The outputs drawn, each once, in the order first drawn.
     *
     * @return list<array{code: string, output: string}>
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }
}

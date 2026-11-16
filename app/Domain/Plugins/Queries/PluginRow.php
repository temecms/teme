<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Queries;

use App\Domain\Plugins\PluginState;

/**
 * One plugin as the console, the Status banner and the Plugins page show it
 * (I-3353): what it is, where it stands and why, what was agreed to, and
 * what it asks for now.
 */
final readonly class PluginRow
{
    /**
     * @param  array{capabilities?: list<string>, hosts?: list<string>, hooks?: list<string>, routes?: list<string>}  $consent  what was agreed to; empty when never enabled
     * @param  array{capabilities: list<string>, hosts: list<string>, hooks: list<string>}  $asks  what the manifest asks for now
     * @param  array{capabilities: list<string>, hosts: list<string>, routes: list<string>}  $added  what it asks for that was never agreed to
     * @param  list<string>  $stopped  the subscription ids stopped for this version
     * @param  list<string>  $requiredBy  the plugins found that require it, by Composer name
     */
    public function __construct(
        public string $name,
        public string $code,
        public string $label,
        public string $version,
        public string $description,
        public string $author,
        public string $authorUrl,
        public string $homepage,
        public PluginState $state,
        public string $reason,
        public array $consent,
        public array $asks,
        public array $added,
        public array $stopped,
        public array $requiredBy = [],
    ) {}
}

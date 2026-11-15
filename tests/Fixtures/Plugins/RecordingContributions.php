<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins;

use App\Domain\Plugins\Seams\PluginContributions;

/**
 * A PluginContributions that records each plugin it is asked to add, and
 * refuses those a test names, with the reason given.
 */
final class RecordingContributions implements PluginContributions
{
    /** @var list<string> the plugins added, in order */
    public array $added = [];

    /**
     * @param  array<string, string>  $refuse  plugin name => why
     */
    public function __construct(private readonly array $refuse = []) {}

    public function add(string $name, string $code, string $label, string $version, array $contributes, object $plugin, array $jobs = []): array
    {
        if (isset($this->refuse[$name])) {
            return [$this->refuse[$name]];
        }

        $this->added[] = $name;

        return [];
    }
}

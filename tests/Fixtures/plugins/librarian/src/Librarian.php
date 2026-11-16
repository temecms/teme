<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Librarian;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * A fixture plugin that reads content and writes with a slug, credits and a
 * translation, from a job that acts for its person.
 */
final class Librarian implements Plugin, PluginHasJobs
{
    public function __construct(
        public readonly ContentReader $reader,
        public readonly ContentWriter $writer,
        public readonly Jobs $jobs,
        public readonly SettingsReader $settings,
    ) {}

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return [
            'write-as-person' => new WriteAsPersonHandler($this->writer),
        ];
    }
}

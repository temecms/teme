<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Scribe;

use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that asks the site's AI connection: a long job that
 * writes an article, and a short one that suggests titles.
 */
final class ScribePlugin implements Plugin, PluginHasJobs
{
    public function __construct(
        public readonly SiteAi $ai,
        public readonly Jobs $jobs,
    ) {}

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return [
            'write' => new AskHandler($this->ai, 'article'),
            'titles' => new AskHandler($this->ai, 'titles'),
        ];
    }
}

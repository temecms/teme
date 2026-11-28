<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Jobs;

use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin that declares jobs: one that writes a line, one that
 * makes a long outbound call, and one that throws.
 */
final class JobsPlugin implements Plugin, PluginHasJobs
{
    public function __construct(
        public readonly Jobs $jobs,
        public readonly OutboundHttp $http,
    ) {}

    public function register(Hooks $hooks): void {}

    /**
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        return [
            'write-line' => new WriteLineHandler,
            'quiet-write' => new QuietWriteHandler,
            'progress-write' => new ProgressWriteHandler,
            'long-call' => new LongCallHandler($this->http),
            'throw' => new ThrowHandler,
            'fail-for-good' => new FailForGoodHandler,
            'summarise' => new SummariseHandler,
            'list-permissions' => new PermissionsHandler,
        ];
    }
}

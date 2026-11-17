<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Scribe;

use Teme\Contracts\Ai\AiAnswer;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Asks one declared task for the person the payload names, with the
 * payload's timeout, and keeps the answer where a test can read it.
 */
final class AskHandler implements JobHandler
{
    /** The last answer, for the test that queued the job. */
    public static ?AiAnswer $last = null;

    public function __construct(private SiteAi $ai, private string $task) {}

    public function handle(JobContext $context): void
    {
        $payload = $context->payload();
        $seconds = $payload['seconds'] ?? 55;

        self::$last = $this->ai->ask(new AiAsk(
            task: $this->task,
            instructions: 'Write about the brief.',
            inputs: ['brief' => is_string($payload['brief'] ?? null) ? $payload['brief'] : 'Ferries'],
            timeoutSeconds: is_int($seconds) ? $seconds : 55,
        ), is_string($payload['person'] ?? null) ? $payload['person'] : '');
    }
}

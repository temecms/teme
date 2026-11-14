<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Domain\Jobs\JobKind;
use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobRecord;

/**
 * A job kind for tests: it queues nothing, and keeps what it was asked to
 * do, each job's ID with who asked.
 */
final class RecordingJobKind implements JobKind
{
    /** @var list<array{job: string, by: string|null}> */
    public array $retried = [];

    /** @var list<array{job: string, by: string|null}> */
    public array $cancelled = [];

    public function __construct(private readonly string $key = 'fixture') {}

    /**
     * Registers a kind under this key in the running application, as a part's
     * provider tags its own, and returns it, so a test can read what it was
     * asked.
     */
    public static function register(string $key = 'fixture'): self
    {
        $kind = new self($key);
        app()->instance(self::class.':'.$key, $kind);
        app()->tag([self::class.':'.$key], JobKinds::TAG);

        return $kind;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function retry(JobRecord $job, ?string $actorId): void
    {
        $this->retried[] = ['job' => $job->id, 'by' => $actorId];
    }

    public function cancel(JobRecord $job, ?string $actorId): void
    {
        $this->cancelled[] = ['job' => $job->id, 'by' => $actorId];
    }
}

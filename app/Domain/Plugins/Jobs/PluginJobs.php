<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Jobs\Operations\StartJob;
use App\Domain\Plugins\FoundPlugin;
use App\Domain\Plugins\Manifest;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Teme\Contracts\Jobs\Jobs;

/**
 * A plugin's Jobs door (I-3445): queues one of its declared jobs as a tracked
 * plugin-job, with a JSON-ready payload of at most 64 KB.
 */
final readonly class PluginJobs implements Jobs
{
    /** The largest payload a plugin may queue, in bytes. */
    public const int MOST_BYTES = 65_536;

    public function __construct(
        private string $name,
        private string $code,
        private StartJob $start,
        private PluginRegistry $registry,
        private ?JobActor $as = null,
    ) {}

    public function queue(string $key, array $payload = [], ?string $label = null): string
    {
        $payload = $this->ready($payload);
        $declared = $this->declared($key);
        $actor = $this->actor();

        $id = $this->start->execute(new StartJobInput(
            kind: PluginJobKind::KEY,
            label: $label ?? $declared['label'],
            runType: PluginJobKind::KEY,
            runId: $key,
            actorId: $actor->userId,
            owner: $this->code,
            payload: $payload,
        ));

        $job = RunPluginJob::configure(
            new RunPluginJob($this->code, $key, $id, $actor),
            $declared,
        );

        DB::afterCommit(function () use ($job, $declared): void {
            dispatch($job
                ->onConnection($declared['long'] ? 'database-long' : (string) config('queue.default'))
                ->onQueue($declared['long'] ? 'long' : 'default'));
        });

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @return array<string, mixed>
     */
    private function ready(array $payload): array
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('A job’s payload must be JSON-ready data.');
        }

        $this->assertReady($payload);

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('A job’s payload must be JSON-ready data.');
        }

        if (strlen($json) > self::MOST_BYTES) {
            throw new InvalidArgumentException('A job’s payload may be at most 64 KB.');
        }

        /** @var array<string, mixed> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function assertReady(array $payload): void
    {
        foreach ($payload as $value) {
            if (is_object($value)) {
                throw new InvalidArgumentException('A job’s payload must be JSON-ready data.');
            }

            if (is_array($value)) {
                $this->assertReady($value);
            }
        }
    }

    /**
     * @return array{key: string, label: string, long: bool, tries: int, backoff: list<int>}
     */
    private function declared(string $key): array
    {
        $plugin = $this->registry->find($this->name);
        $manifest = $plugin instanceof FoundPlugin ? $plugin->manifest : null;

        if (! $manifest instanceof Manifest) {
            throw new InvalidArgumentException("The plugin {$this->name} has no manifest.");
        }

        $job = DeclaredJobs::of($manifest)->job($key);

        if ($job === null) {
            throw new InvalidArgumentException("The plugin doesn’t declare a job named {$key}.");
        }

        return $job;
    }

    private function actor(): JobActor
    {
        if ($this->as !== null) {
            return $this->as;
        }

        $id = Auth::id();

        if ($id !== null) {
            return JobActor::person($id);
        }

        if (Operator::isPresent()) {
            return JobActor::operator();
        }

        throw new InvalidArgumentException('Only a person or the operator queues a plugin job.');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Jobs;

use App\Domain\Plugins\Manifest;

/**
 * A plugin's declared jobs and schedules as the runner and scheduler read
 * them (I-3445): defaults filled, keyed by job key. Built only from a
 * manifest ManifestValidator has already accepted.
 *
 * @phpstan-type JobDeclaration array{key: string, label: string, long: bool, tries: int, backoff: list<int>}
 * @phpstan-type ScheduleDeclaration array{job: string, every: string, enabledBy?: string, everyFrom?: string}
 */
final readonly class DeclaredJobs
{
    /** @var list<string> */
    public const array EVERY = ['minute', 'five-minutes', 'fifteen-minutes', 'hour', 'day', 'week'];

    public const string KEY = '/^[a-z][a-z0-9-]{0,47}$/';

    /** @var list<int> */
    public const array DEFAULT_BACKOFF = [30, 120, 600];

    /**
     * @param  array<string, JobDeclaration>  $jobs
     * @param  list<ScheduleDeclaration>  $schedules
     */
    private function __construct(
        private array $jobs,
        private array $schedules,
    ) {}

    /**
     * Returns the declarations from this manifest, with defaults filled.
     */
    public static function of(Manifest $manifest): self
    {
        $jobs = [];

        foreach ($manifest->raw()['jobs'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['key'] ?? null)) {
                continue;
            }

            $key = $entry['key'];
            $jobs[$key] = [
                'key' => $key,
                'label' => is_string($entry['label'] ?? null) ? $entry['label'] : $key,
                'long' => ($entry['long'] ?? false) === true,
                'tries' => is_int($entry['tries'] ?? null) ? $entry['tries'] : 3,
                'backoff' => self::backoff($entry['backoff'] ?? null),
            ];
        }

        $schedules = [];

        foreach ($manifest->raw()['schedules'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['job'] ?? null) || ! is_string($entry['every'] ?? null)) {
                continue;
            }

            // The setting names only when declared, so a plain schedule reads as before.
            $schedules[] = [
                'job' => $entry['job'],
                'every' => $entry['every'],
                ...(is_string($entry['enabledBy'] ?? null) ? ['enabledBy' => $entry['enabledBy']] : []),
                ...(is_string($entry['everyFrom'] ?? null) ? ['everyFrom' => $entry['everyFrom']] : []),
            ];
        }

        return new self($jobs, $schedules);
    }

    /**
     * One declared job by key, or null when it isn't declared.
     *
     * @return JobDeclaration|null
     */
    public function job(string $key): ?array
    {
        return $this->jobs[$key] ?? null;
    }

    /**
     * Every declared job, keyed by key.
     *
     * @return array<string, JobDeclaration>
     */
    public function jobs(): array
    {
        return $this->jobs;
    }

    /**
     * Every declared schedule, in manifest order.
     *
     * @return list<ScheduleDeclaration>
     */
    public function schedules(): array
    {
        return $this->schedules;
    }

    /**
     * @return list<int>
     */
    private static function backoff(mixed $backoff): array
    {
        if (! is_array($backoff) || $backoff === [] || ! array_is_list($backoff)) {
            return self::DEFAULT_BACKOFF;
        }

        $seconds = [];

        foreach ($backoff as $value) {
            if (! is_int($value)) {
                return self::DEFAULT_BACKOFF;
            }

            $seconds[] = $value;
        }

        return $seconds;
    }
}

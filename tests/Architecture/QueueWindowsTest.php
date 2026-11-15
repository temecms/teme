<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\AdvanceBulkRun;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\Timeout;
use Tests\TestCase;

// Booted, for config/queue.php's connections (RegisteredSettingsTest does the same).
uses(TestCase::class);

/**
 * Every class in app/ that is queued, found from its file, so a job added
 * later is covered without editing this test.
 *
 * @return list<class-string>
 */
function qwQueuedClasses(): array
{
    $root = app_path();
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
        $class = 'App\\'.str_replace(['/', '\\'], '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * How long a queued class may run, from #[Timeout] or its $timeout, or null
 * when it doesn't say.
 */
function qwTimeout(string $class): ?int
{
    $reflection = new ReflectionClass($class);
    $attribute = $reflection->getAttributes(Timeout::class)[0] ?? null;
    $timeout = $attribute?->newInstance()->timeout ?? ($reflection->getDefaultProperties()['timeout'] ?? null);

    return is_int($timeout) ? $timeout : null;
}

/**
 * The connection a queued class is dispatched on: its #[Connection], or
 * database, the default config/queue.php gives production (tests run sync).
 */
function qwConnection(string $class): string
{
    $attribute = (new ReflectionClass($class))->getAttributes(Connection::class)[0] ?? null;

    return $attribute === null ? 'database' : (string) $attribute->newInstance()->connection;
}

it('gives every queued job at least 15 seconds of its connection\'s retry window to spare, so no job runs twice at once', function (): void {
    $checked = [];

    foreach (qwQueuedClasses() as $class) {
        $timeout = qwTimeout($class);

        if ($timeout === null) {
            continue;
        }

        $connection = qwConnection($class);
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeInt("{$class} is dispatched on [{$connection}], which has no retry_after.")
            ->and($timeout + 15)->toBeLessThanOrEqual($retryAfter, "{$class} may run {$timeout} s, but [{$connection}] hands its job to another worker after {$retryAfter} s.");

        $checked[] = $class;
    }

    // Not vacuous: the jobs this rule was written for are among those held to it.
    expect($checked)->toContain(GenerateMediaVariants::class, AdvanceBulkRun::class);
});

it('runs the long connection on its own queue in the same jobs table, with the default connection still quick to retry', function (): void {
    expect(config('queue.connections.database-long'))->toMatchArray(['table' => 'jobs', 'queue' => 'long', 'retry_after' => 1860])
        ->and(config('queue.connections.database.retry_after'))->toBe(90)
        ->and(qwConnection(GenerateMediaVariants::class))->toBe('database-long');
});

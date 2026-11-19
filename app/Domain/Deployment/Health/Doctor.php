<?php

/**
 * The doctor's checks, by key (I-157): core's, then those a later part or a
 * plugin registers as the application boots. Its findings come most
 * serious first, and a check that fails to run is an error of its own.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Health;

use App\Domain\Deployment\Health\Checks\DatabaseRole;
use App\Domain\Deployment\Health\Checks\DebugMode;
use App\Domain\Deployment\Health\Checks\Mailer;
use App\Domain\Deployment\Health\Checks\PageCacheFolder;
use App\Domain\Deployment\Health\Checks\PasswordHashing;
use App\Domain\Deployment\Health\Checks\QueueHeartbeat;
use App\Domain\Deployment\Health\Checks\RunningUser;
use App\Domain\Deployment\Health\Checks\SchedulerHeartbeat;
use App\Domain\Deployment\Health\Checks\SiteAddress;
use App\Domain\Deployment\Health\Checks\StartConfiguration;
use App\Domain\Deployment\Health\Checks\Storage;
use App\Domain\Deployment\Health\Checks\TrustedProxyList;
use Illuminate\Database\QueryException;
use LogicException;
use Throwable;

final class Doctor
{
    /**
     * Core's checks, in the order they are shown within a severity.
     *
     * @var array<string, class-string<DoctorCheck>>
     */
    public const array CORE = [
        'database-role' => DatabaseRole::class,
        'configuration' => StartConfiguration::class,
        'site-address' => SiteAddress::class,
        'debug-mode' => DebugMode::class,
        'trusted-proxies' => TrustedProxyList::class,
        'email' => Mailer::class,
        'password-hashing' => PasswordHashing::class,
        'storage' => Storage::class,
        'page-cache-folder' => PageCacheFolder::class,
        'user' => RunningUser::class,
        'scheduler' => SchedulerHeartbeat::class,
        'queue-worker' => QueueHeartbeat::class,
    ];

    /** What a check found that couldn't run before migrating (I-377). */
    public const string NOT_YET = "The check couldn't run yet: it reads a table or column that a migration which hasn't run makes.";

    /** PostgreSQL's undefined table and undefined column. */
    private const array NOT_MIGRATED_YET = ['42P01', '42703'];

    /**
     * @param  array<string, class-string<DoctorCheck>>  $checks
     */
    public function __construct(private array $checks = self::CORE) {}

    /**
     * @param  class-string<DoctorCheck>  $check
     *
     * @throws LogicException when another check has the key
     */
    public function register(string $key, string $check): void
    {
        if (array_key_exists($key, $this->checks)) {
            throw new LogicException("A doctor check is already registered as {$key}.");
        }

        $this->checks[$key] = $check;
    }

    /**
     * Every registered key, in order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->checks);
    }

    /**
     * Every check's findings: errors, then warnings, what is well, and what
     * is only noted; within a severity, in the checks' order.
     *
     * @return list<DoctorResult>
     */
    public function examine(): array
    {
        $results = [];

        foreach ($this->checks as $key => $class) {
            $title = $key;

            try {
                $check = app($class);
                $title = $check->title();

                foreach ($check->examine() as $finding) {
                    $results[] = new DoctorResult($key, $title, $finding);
                }
            } catch (Throwable $exception) {
                $results[] = new DoctorResult($key, $title, $this->couldNotRun($exception));
            }
        }

        // usort is stable, so each check's order holds within a severity.
        usort($results, fn (DoctorResult $a, DoctorResult $b): int => $b->finding->severity->value <=> $a->finding->severity->value);

        return $results;
    }

    /**
     * The findings of the checks registered as $keys, in their order and
     * sorted as examine() sorts them; a deploy asks again after migrating
     * for those that couldn't run before.
     *
     * @param  list<string>  $keys
     *
     * @return list<DoctorResult>
     */
    public function examineOnly(array $keys): array
    {
        $chosen = array_intersect_key($this->checks, array_flip($keys));

        return (new self($chosen))->examine();
    }

    /**
     * What a check that threw found: a warning while it reads a table or a
     * column a waiting migration makes, as a deploy's preflight runs before
     * migrating; an error otherwise.
     */
    private function couldNotRun(Throwable $exception): Finding
    {
        if ($exception instanceof QueryException && in_array((string) $exception->getCode(), self::NOT_MIGRATED_YET, true) && $this->migrationsWait()) {
            return new Finding(Severity::Warning, self::NOT_YET, 'Run php artisan teme:deploy, which runs the migrations.');
        }

        return new Finding(Severity::Error, "The check couldn't run: {$exception->getMessage()}");
    }

    private function migrationsWait(): bool
    {
        try {
            return app(PendingMigrations::class)->names() !== [];
        } catch (Throwable) {
            return false;
        }
    }
}

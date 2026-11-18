<?php

/**
 * What one teme:deploy found and did, step by step, filled in as it runs:
 * the doctor's findings, the lock, the migrations, the backup, the
 * rebuilds, each step's state, and how it ended. toArray() is the JSON
 * --json prints (I-3189). Until a run ends it reads as failed.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment;

use App\Domain\Backups\BackupResult;
use App\Domain\Deployment\Health\DoctorResult;
use stdClass;

final class DeployReport
{
    /** The JSON's shape; a key may be added, never renamed or removed. */
    public const int FORMAT = 1;

    public const string UNEXPECTED = "The deploy stopped on an error Teme didn't expect. The log has its details.";

    /** @var list<string> */
    public const array STEPS = ['preflight', 'lock', 'backup', 'migrate', 'recheck', 'rebuilds', 'queue_restart', 'cache_clear', 'cache_warm'];

    public DeployOutcome $outcome = DeployOutcome::Failed;

    public string $sentence = self::UNEXPECTED;

    /** @var list<DoctorResult> */
    public array $findings = [];

    /** @var array{waited: bool, seconds: float}|null */
    public ?array $lock = null;

    public bool $newDatabase = false;

    /** @var list<string> */
    public array $pending = [];

    /** @var list<string> */
    public array $ran = [];

    /** @var list<string> */
    public array $needsMaintenance = [];

    public ?string $migrationError = null;

    /** @var array{state: string, run: ?string, place: ?string, sentence: ?string, problems: list<string>}|null */
    public ?array $backup;

    /** @var list<string> */
    public array $rebuilds = [];

    /** @var array<string, string> */
    public array $steps;

    private function __construct(public readonly bool $check)
    {
        $this->backup = $check ? null : self::backupOf('not-run');
        $this->steps = $check ? [] : array_fill_keys(self::STEPS, 'not-run');
    }

    public static function forDeploy(): self
    {
        return new self(false);
    }

    public static function forCheck(): self
    {
        return new self(true);
    }

    public function step(string $step, string $state): self
    {
        $this->steps[$step] = $state;

        return $this;
    }

    public function lockTaken(?float $waitedSeconds): self
    {
        $this->lock = ['waited' => $waitedSeconds !== null, 'seconds' => $waitedSeconds ?? 0.0];

        return $this->step('lock', 'done');
    }

    /**
     * @param  list<string>  $pending
     * @param  list<string>  $needsMaintenance
     */
    public function migrationsFound(bool $newDatabase, array $pending, array $needsMaintenance): self
    {
        $this->newDatabase = $newDatabase;
        $this->pending = $pending;
        $this->needsMaintenance = $needsMaintenance;

        return $this;
    }

    /**
     * @param  list<string>  $ran
     */
    public function ran(array $ran): self
    {
        $this->ran = $ran;

        return $this;
    }

    public function migrationFailed(string $error): self
    {
        $this->migrationError = $error;

        return $this->step('migrate', 'failed');
    }

    public function backupState(string $state): self
    {
        $this->backup = self::backupOf($state);

        return $this;
    }

    public function backedUp(BackupResult $result): self
    {
        $this->backup = ['state' => 'done', 'run' => $result->runId, 'place' => $result->place, 'sentence' => null, 'problems' => $result->problems];

        return $this->step('backup', 'done');
    }

    public function backupFailed(?string $runId, string $sentence): self
    {
        $this->backup = ['state' => 'failed', 'run' => $runId, 'place' => null, 'sentence' => $sentence, 'problems' => []];

        return $this->step('backup', 'failed');
    }

    /**
     * The backup this run took, by its run's ID, or null.
     */
    public function backupRunId(): ?string
    {
        return $this->backup['run'] ?? null;
    }

    /**
     * Ends the run because a lock was still held after $waitSeconds: the
     * deploy's ('lock') or the backup's ('backup').
     */
    public function busy(string $step, string $sentence, int $waitSeconds): self
    {
        if ($step === 'lock') {
            $this->lock = ['waited' => true, 'seconds' => (float) $waitSeconds];
        } else {
            $this->backup = self::backupOf('busy');
        }

        return $this->step($step, 'failed')->end(DeployOutcome::Busy, $sentence);
    }

    public function end(DeployOutcome $outcome, string $sentence): self
    {
        $this->outcome = $outcome;
        $this->sentence = $sentence;

        return $this;
    }

    /**
     * The report as teme:deploy --json prints it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'command' => 'teme:deploy',
            'format' => self::FORMAT,
            'check' => $this->check,
            'outcome' => $this->outcome->value,
            'exit' => $this->outcome->exitCode(),
            'sentence' => $this->sentence,
            'findings' => array_map(fn (DoctorResult $result): array => [
                'check' => $result->key,
                'title' => $result->title,
                'severity' => $result->finding->severity->label(),
                'message' => $result->finding->message,
                'fix' => $result->finding->fix,
            ], $this->findings),
            'lock' => $this->lock,
            'migrations' => [
                'new_database' => $this->newDatabase,
                'pending' => $this->pending,
                'ran' => $this->ran,
                'needs_maintenance' => $this->needsMaintenance,
                'error' => $this->migrationError,
            ],
            'backup' => $this->backup,
            'rebuilds' => $this->rebuilds,
            // An object, even empty, as a program expects of this key.
            'steps' => $this->steps === [] ? new stdClass : $this->steps,
        ];
    }

    /**
     * @return array{state: string, run: null, place: null, sentence: null, problems: list<string>}
     */
    private static function backupOf(string $state): array
    {
        return ['state' => $state, 'run' => null, 'place' => null, 'sentence' => null, 'problems' => []];
    }
}

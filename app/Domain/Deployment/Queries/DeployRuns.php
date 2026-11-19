<?php

/**
 * The deploys that ran (I-?? draft), read from deploy_runs; empty on a database a deploy hasn't brought to the release that makes the table.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeployRuns
{
    /**
     * The last deploys, newest first.
     *
     * @return list<array{version: string, outcome: string, exitCode: int, finishedAt: CarbonImmutable, startedAt: CarbonImmutable, migrations: int, backupRun: ?string, sentence: string}>
     */
    public function recent(int $limit = 10): array
    {
        if (! Schema::hasTable('deploy_runs')) {
            return [];
        }

        return DB::table('deploy_runs')->orderByDesc('finished_at')->limit($limit)->get()
            ->map(fn (object $row): array => $this->shape($row))->values()->all();
    }

    /**
     * The newest deploy that ended deployed, or null.
     *
     * @return array{version: string, outcome: string, exitCode: int, finishedAt: CarbonImmutable, startedAt: CarbonImmutable, migrations: int, backupRun: ?string, sentence: string}|null
     */
    public function lastDeployed(): ?array
    {
        if (! Schema::hasTable('deploy_runs')) {
            return null;
        }

        $row = DB::table('deploy_runs')->where('outcome', 'deployed')->orderByDesc('finished_at')->first();

        return $row === null ? null : $this->shape($row);
    }

    /** How many migrations the deploy's report says ran; 0 when it names none. */
    private static function migrationsRan(mixed $report): int
    {
        $decoded = is_string($report) ? json_decode($report, true) : null;
        $ran = is_array($decoded) ? ($decoded['migrations']['ran'] ?? []) : [];

        return is_array($ran) ? count($ran) : 0;
    }

    /**
     * @return array{version: string, outcome: string, exitCode: int, finishedAt: CarbonImmutable, startedAt: CarbonImmutable, migrations: int, backupRun: ?string, sentence: string}
     */
    private function shape(object $row): array
    {
        return [
            'version' => (string) $row->version,
            'outcome' => (string) $row->outcome,
            'exitCode' => (int) $row->exit_code,
            'finishedAt' => CarbonImmutable::parse((string) $row->finished_at),
            'startedAt' => CarbonImmutable::parse((string) $row->started_at),
            'migrations' => self::migrationsRan($row->report),
            'backupRun' => $row->backup_run_id === null ? null : (string) $row->backup_run_id,
            'sentence' => (string) $row->sentence,
        ];
    }
}

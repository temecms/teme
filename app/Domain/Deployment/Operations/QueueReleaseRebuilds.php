<?php

/**
 * Queues, as the operator, every rebuild a release has asked for that
 * hasn't been queued (I-3113): one statement marks them queued, and after
 * commit one RunReleaseRebuild each goes on the queue, a request whose job
 * can't be queued put back. It says which, in alphabetical order.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Deployment\Operations;

use App\Domain\Deployment\Health\PendingMigrations;
use App\Domain\Deployment\Jobs\RunReleaseRebuild;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

final class QueueReleaseRebuilds extends BaseOperation
{
    public function __construct(private readonly PendingMigrations $migrations) {}

    protected function authorize(OperationInput $input): void
    {
        if (! Operator::isPresent()) {
            throw new AuthorizationException("Only the operator queues a release's rebuilds.");
        }
    }

    protected function validate(OperationInput $input): void {}

    /**
     * @return list<string>
     */
    protected function perform(OperationInput $input): array
    {
        $rows = DB::selectFromWriteConnection(
            'UPDATE release_rebuilds SET queued_at = CAST(? AS timestamptz) WHERE queued_at IS NULL RETURNING rebuild',
            [CarbonImmutable::now()->format('Y-m-d H:i:s.uP')],
        );

        $rebuilds = array_map(fn (object $row): string => (string) $row->rebuild, $rows);
        sort($rebuilds);

        return $rebuilds;
    }

    /**
     * After commit, as ChangeAddresses queues its recompute: the jobs carry
     * the operator, whom only this console process may name (I-392), and
     * this release's newest migration. A request whose job can't be queued
     * waits again, so the next deploy queues it, and the failure is thrown.
     */
    protected function afterCommit(mixed $result): void
    {
        $release = (string) $this->migrations->newest();

        /** @var list<string> $result */
        foreach ($result as $rebuild) {
            try {
                RunReleaseRebuild::dispatch($rebuild, $release, JobActor::operator());
            } catch (Throwable $failed) {
                DB::table('release_rebuilds')->whereIn('rebuild', array_slice($result, (int) array_search($rebuild, $result, true)))->update(['queued_at' => null]);

                throw $failed;
            }
        }
    }
}

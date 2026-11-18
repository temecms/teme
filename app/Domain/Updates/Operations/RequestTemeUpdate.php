<?php

/**
 * Asks for Teme to be updated, or rolled back to the release before (I-3326): a person who holds
 * updates.manage, after their password, or the operator. It records the request; the updater carries it
 * out. One at a time. Every administrator is emailed once it is recorded. Returns the request's ID.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Updates\Operations;

use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Updates\Jobs\SendTemeUpdateEmails;
use App\Domain\Updates\Notifications\TemeUpdateNotice;
use App\Domain\Updates\Operations\Concerns\AuthorizesUpdateRequests;
use App\Domain\Updates\Operations\Input\RequestTemeUpdateInput;
use App\Domain\Updates\Requests\UpdateStatus;
use App\Domain\Updates\TemeVersion;
use App\Domain\Updates\UpdatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Operations\RequiresElevation;

final class RequestTemeUpdate extends BaseOperation implements RequiresElevation
{
    use AuthorizesUpdateRequests;

    /** The advisory lock that puts one request in line at a time. */
    public const string LOCK = 'teme.updates.request';

    public function __construct(private readonly UpdatePlan $plan) {}

    public function requiresElevation(OperationInput $input): bool
    {
        return true;
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RequestTemeUpdateInput $input */
        $refusal = $this->plan->refusal($input->kind, $input->version);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['version' => [$refusal]]);
        }
    }

    protected function perform(OperationInput $input): string
    {
        /** @var RequestTemeUpdateInput $input */
        // One request at a time, from every server, until this transaction ends; then the rules again, under it.
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
        $this->validate($input);

        $id = strtolower((string) Str::ulid());
        DB::table('teme_updates')->insert([
            'id' => $id,
            'kind' => $input->kind->value,
            'from_version' => TemeVersion::current(),
            'to_version' => $input->version,
            'status' => UpdateStatus::Requested->value,
            'steps' => '[]',
            'requested_by' => $input->actorId() === null ? null : (int) $input->actorId(),
            'requested_at' => CarbonImmutable::now(),
        ]);

        DB::afterCommit(fn () => SendTemeUpdateEmails::dispatch($id, TemeUpdateNotice::REQUESTED));

        return $id;
    }
}

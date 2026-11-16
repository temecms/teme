<?php

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\ExtensionAction;
use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Operations\Input\BeginExtensionRequestInput;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Operations\OperationInput;

/**
 * Begins an extension request the installer takes up: it runs, its steps
 * waiting. Or, for a new Teme the current release no longer fits, records a
 * rebuild Teme asks for itself and begins it. The operator only. Returns the
 * request's id, or null when there is nothing to begin: the request was
 * cancelled first, another is open, or a Teme update is.
 */
final class BeginExtensionRequest extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the installer, as the operator, records an extension request’s steps.');
        }
    }

    protected function validate(OperationInput $input): void {}

    protected function perform(OperationInput $input): ?string
    {
        /** @var BeginExtensionRequestInput $input */
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['teme.extensions.request']);

        if (DB::table('teme_updates')->whereIn('status', ['requested', 'running'])->exists()) {
            return null;
        }

        $now = CarbonImmutable::now();
        $begun = [
            'status' => RequestStatus::Running->value,
            'step' => RequestStep::Checking->value,
            'steps' => json_encode(self::steps(), JSON_THROW_ON_ERROR),
            'started_at' => $now,
        ];

        if ($input->rebuild) {
            if (DB::table('extension_requests')->whereIn('status', ['requested', 'running', 'waiting'])->exists()) {
                return null;
            }

            $id = strtolower((string) Str::ulid());
            DB::table('extension_requests')->insert([
                'id' => $id,
                'action' => ExtensionAction::Rebuild->value,
                'kind' => ExtensionKind::Plugin->value,
                'requested_at' => $now,
                ...$begun,
            ]);

            return $id;
        }

        $row = DB::table('extension_requests')->where('id', $input->requestId)->lockForUpdate()->first();

        if ($row === null || $row->status !== RequestStatus::Requested->value) {
            return null;
        }

        DB::table('extension_requests')->where('id', $input->requestId)->update($begun);

        return (string) $row->id;
    }

    /**
     * Every step, waiting.
     *
     * @return list<array{step: string, state: string, startedAt: null, finishedAt: null, detail: null}>
     */
    private static function steps(): array
    {
        return array_map(fn (RequestStep $step): array => [
            'step' => $step->value, 'state' => RequestStep::PENDING, 'startedAt' => null, 'finishedAt' => null, 'detail' => null,
        ], RequestStep::cases());
    }
}

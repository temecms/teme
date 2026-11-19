<?php

/**
 * Records where one step of an open extension request stands: its state
 * and a sentence, the releases it moves between, and what it waits for the
 * administrator to agree to. The operator only: the installer.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Extensions\Operations;

use App\Domain\Extensions\Operations\Input\RecordExtensionStepInput;
use App\Domain\Extensions\RequestStatus;
use App\Domain\Extensions\RequestStep;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Teme\Contracts\Operations\OperationInput;

final class RecordExtensionStep extends BaseOperation
{
    /** The states a step is recorded in. */
    public const array STATES = [RequestStep::PENDING, RequestStep::RUNNING, RequestStep::DONE, RequestStep::FAILED, RequestStep::WAITING];

    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the installer, as the operator, records an extension request’s steps.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordExtensionStepInput $input */
        if (! in_array($input->state, self::STATES, true)) {
            throw ValidationException::withMessages(['state' => ["{$input->state} isn’t a state a step is recorded in."]]);
        }

        if ($input->status !== null && $input->status !== RequestStatus::Running && $input->status !== RequestStatus::Waiting) {
            throw ValidationException::withMessages(['status' => ['A step only runs a request or makes it wait; EndExtensionRequest ends it.']]);
        }
    }

    protected function perform(OperationInput $input): null
    {
        /** @var RecordExtensionStepInput $input */
        $row = DB::table('extension_requests')->where('id', $input->requestId)->lockForUpdate()->first();

        if ($row === null || ! in_array($row->status, [RequestStatus::Running->value, RequestStatus::Waiting->value], true)) {
            throw ValidationException::withMessages(['request' => ['There’s no such open request.']]);
        }

        $now = CarbonImmutable::now()->toIso8601String();
        $steps = json_decode((string) $row->steps, true);
        $steps = array_map(function (array $entry) use ($input, $now): array {
            if ($entry['step'] !== $input->step->value) {
                return $entry;
            }

            $entry['startedAt'] = $input->state === RequestStep::RUNNING && $entry['state'] !== RequestStep::RUNNING ? $now : $entry['startedAt'];
            $entry['state'] = $input->state;
            $entry['detail'] = $input->detail;
            $entry['finishedAt'] = in_array($input->state, [RequestStep::DONE, RequestStep::FAILED], true) ? $now : null;

            return $entry;
        }, is_array($steps) ? $steps : []);

        $changes = [
            'step' => $input->step->value,
            'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
        ];

        if ($input->needs !== null) {
            $changes['needs'] = json_encode($input->needs === [] ? new stdClass : $input->needs, JSON_THROW_ON_ERROR);
        }

        foreach (['status' => $input->status?->value, 'from_release' => $input->fromRelease, 'to_release' => $input->toRelease] as $column => $value) {
            if ($value !== null) {
                $changes[$column] = $value;
            }
        }

        DB::table('extension_requests')->where('id', $input->requestId)->update($changes);

        return null;
    }
}

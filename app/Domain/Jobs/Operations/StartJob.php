<?php

declare(strict_types=1);

namespace App\Domain\Jobs\Operations;

use App\Domain\Jobs\JobKinds;
use App\Domain\Jobs\JobState;
use App\Domain\Jobs\Models\TrackedJob;
use App\Domain\Jobs\Operations\Input\StartJobInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use App\Domain\Users\Actors;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Teme\Contracts\Operations\OperationInput;

/**
 * Records a piece of background work as it's queued, waiting for its first
 * step, so people can follow it (I-104). The part that starts the work calls
 * it, as the person starting it or the operator, having authorized the work.
 */
final class StartJob extends BaseOperation
{
    public function __construct(private Actors $actors, private JobKinds $kinds) {}

    protected function authorize(OperationInput $input): void
    {
        /** @var StartJobInput $input */
        $actorId = $input->actorId();

        if ($actorId === null ? Operator::isPresent() : $this->actors->find($actorId) !== null) {
            return;
        }

        throw new AuthorizationException('Only a person or the operator starts a job.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var StartJobInput $input */
        Validator::make([
            'kind' => $input->kind,
            'owner' => $input->owner,
            'label' => $input->label,
            'total' => $input->total,
            'run_type' => $input->runType,
            'run_id' => $input->runId,
        ], [
            // A kind that can't be found can't be retried, so it can't start (I-104).
            'kind' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $this->kinds->find($value) === null) {
                    $fail('No job kind is registered as '.(is_string($value) ? $value : 'that').'.');
                }
            }],
            'owner' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/'],
            'label' => ['required', 'string', 'max:200'],
            'total' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'run_type' => ['nullable', 'required_with:run_id', 'string', 'max:32', 'regex:/^[a-z][a-z0-9-]*$/'],
            'run_id' => ['nullable', 'required_with:run_type', 'string', 'max:64'],
        ])->validate();
    }

    protected function perform(OperationInput $input): string
    {
        /** @var StartJobInput $input */
        $actorId = $input->actorId();

        $job = TrackedJob::query()->create([
            'kind' => $input->kind,
            'owner' => $input->owner,
            'label' => $input->label,
            'starter' => $actorId === null ? 'operator' : 'person',
            'started_by' => $actorId === null ? null : (int) $actorId,
            'run_type' => $input->runType,
            'run_id' => $input->runId,
            'state' => JobState::Waiting,
            'total' => $input->total,
            'done' => 0,
            'payload' => $input->payload,
        ]);

        return (string) $job->id;
    }
}

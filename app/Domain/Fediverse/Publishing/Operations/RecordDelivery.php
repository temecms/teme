<?php

/**
 * Records what one try of a delivery came to (docs/plans/fediverse-publishing.md,
 * D3): sent; refused for good; gone, which ends that account's follows; or
 * failed for a reason that may pass, queued again on the retry schedule
 * until it runs out, the server marked failing from its first such
 * failure and no longer once it answers. Only the site's own delivery
 * work records; returns the seconds until the next try, or null for none.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing\Operations;

use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Publishing\DeliveryOutcome;
use App\Domain\Fediverse\Publishing\DeliverySchedule;
use App\Domain\Fediverse\Publishing\Operations\Input\RecordDeliveryInput;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Support\Security\Operator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class RecordDelivery extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        if ($input->actorId() !== null || ! Operator::isPresent()) {
            throw new AuthorizationException('Only the site’s own delivery work records a delivery.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var RecordDeliveryInput $input */
        $kinds = [DeliveryOutcome::SENT, DeliveryOutcome::REFUSED, DeliveryOutcome::GONE, DeliveryOutcome::RETRY, DeliveryOutcome::SKIPPED];

        if (! in_array($input->outcome->kind, $kinds, true)) {
            throw ValidationException::withMessages(['outcome' => 'Not an outcome of a delivery.']);
        }
    }

    protected function perform(OperationInput $input): ?int
    {
        /** @var RecordDeliveryInput $input */
        $delivery = Delivery::query()->whereKey($input->deliveryId)->lockForUpdate()->first();

        if ($delivery === null || $delivery->state !== 'queued') {
            return null;
        }

        $outcome = $input->outcome;
        $tried = $outcome->kind !== DeliveryOutcome::SKIPPED;
        $attempts = $delivery->attempts + ($tried ? 1 : 0);
        $delay = $outcome->kind === DeliveryOutcome::RETRY ? DeliverySchedule::delayAfter($attempts) : null;

        $state = match ($outcome->kind) {
            DeliveryOutcome::SENT => 'sent',
            DeliveryOutcome::SKIPPED => 'skipped',
            DeliveryOutcome::RETRY => $delay === null ? 'failed' : 'queued',
            default => 'failed',
        };

        Delivery::query()->whereKey($delivery->id)->update([
            'state' => $state,
            'attempts' => $attempts,
            'last_status' => $outcome->status,
            'last_error' => $outcome->error === null ? null : mb_substr($outcome->error, 0, 255),
            'next_attempt_at' => $delay === null ? $delivery->next_attempt_at : now()->addSeconds($delay),
            'sent_at' => $state === 'sent' ? now() : null,
        ]);

        // A server that answers is not failing; one that fails is, from its first failure.
        if ($outcome->kind === DeliveryOutcome::SENT) {
            RemoteActor::query()->where('host', $delivery->host)->whereNotNull('failing_since')->update(['failing_since' => null]);
        } elseif ($outcome->kind === DeliveryOutcome::RETRY) {
            RemoteActor::query()->where('host', $delivery->host)->whereNull('failing_since')->update(['failing_since' => now()]);
        }

        if ($outcome->kind === DeliveryOutcome::GONE) {
            $gone = RemoteActor::query()->where('inbox_url', $delivery->inbox_url)->pluck('id')->all();
            RemoteActor::query()->whereIn('id', $gone)->update(['gone_at' => now()]);
            Follower::query()->whereIn('remote_actor_id', $gone)->delete();
        }

        return $state === 'queued' ? $delay : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets\Operations;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Fediverse\Publishing\Operations\QueueActivity;
use App\Domain\Fediverse\Sockets\CoreActivities;
use App\Domain\Fediverse\Sockets\Operations\Input\SendPluginActivityInput;
use App\Domain\Fediverse\Sockets\SentActivities;
use App\Domain\People\Models\Person;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Support\Operations\BaseOperation;
use App\Domain\Users\Actors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;
use Teme\Contracts\Plugins\Capability;

/**
 * Stores an activity a plugin sends through FediverseSender, with one
 * delivery per distinct inbox of the sending account's followers
 * (docs/plans/fediverse-publishing.md, D5): only for an enabled plugin
 * granted fediverse.send, acting for a person (follow-up R7) who manages
 * the fediverse, or who sends as their own author account; never a type or
 * an object Teme keeps for itself.
 * Core signs and delivers it once the plugin's work commits.
 */
final class SendPluginActivity extends BaseOperation
{
    protected function authorize(OperationInput $input): void
    {
        /** @var SendPluginActivityInput $input */
        $granted = PluginRecord::query()
            ->where('name', $input->plugin)
            ->where('state', PluginRecord::ENABLED)
            ->get()
            ->contains(fn (PluginRecord $record): bool => in_array(Capability::FediverseSend->value, $record->consent['capabilities'] ?? [], true));
        $person = $input->actorId === null ? null : app(Actors::class)->find($input->actorId);

        if (! $granted || $person === null) {
            throw new AuthorizationException('Not permitted to send to the fediverse.');
        }

        if ($person->can('settings.fediverse.manage')) {
            return;
        }

        // Anyone else sends only as their own author account.
        $own = $input->actorUri !== null && FediverseActor::query()
            ->where('uri', $input->actorUri)
            ->where('kind', FediverseActor::PERSON)
            ->whereIn('person_id', Person::query()->where('user_id', (int) $input->actorId)->select('id'))
            ->exists();

        if (! $own) {
            throw new AuthorizationException('Not permitted to send to the fediverse as that account.');
        }
    }

    protected function validate(OperationInput $input): void
    {
        /** @var SendPluginActivityInput $input */
        if (! FediverseSwitch::on()) {
            throw ValidationException::withMessages(['fediverse' => 'The fediverse is paused.']);
        }

        if (preg_match('/^[A-Z][A-Za-z]{1,63}$/', $input->type) !== 1 || CoreActivities::owns($input->type)) {
            throw ValidationException::withMessages(['type' => "Teme doesn’t send {$input->type} for a plugin."]);
        }

        $object = $input->objectUri();

        if ($object === null) {
            throw ValidationException::withMessages(['object' => 'The object needs an address.']);
        }

        if (FediverseObject::query()->whereKey($object)->exists() || FediverseActor::query()->where('uri', $object)->exists()) {
            throw ValidationException::withMessages(['object' => 'Teme sends what concerns its own posts and accounts itself.']);
        }

        if ($this->sender($input) === null) {
            throw ValidationException::withMessages(['actor' => 'The site has no such account.']);
        }
    }

    protected function perform(OperationInput $input): Activity
    {
        /** @var SendPluginActivityInput $input */
        $actor = $this->sender($input) ?? throw ValidationException::withMessages(['actor' => 'The site has no such account.']);

        $activity = Activity::query()->create([
            'local_actor_id' => $actor->id,
            'type' => $input->type,
            'object_uri' => (string) $input->objectUri(),
            'payload' => [
                '@context' => ActivityPub::CONTEXT,
                'id' => $actor->uri.'#activities/'.strtolower((string) Str::ulid()),
                'type' => $input->type,
                'actor' => $actor->uri,
                'published' => now()->utc()->toIso8601ZuluString(),
                'to' => [ActivityPub::PUBLIC],
                'cc' => array_filter([$actor->urls['followers'] ?? null]),
                'object' => $input->object,
            ],
        ]);

        QueueActivity::deliver($activity, $actor);

        return $activity;
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var Activity $result */
        app(Deliveries::class)->dispatch(Delivery::query()->where('activity_id', $result->id)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
        app(SentActivities::class)->announce([$result]);
    }

    /**
     * The site's enabled account that sends it: the one named, else the blog's.
     */
    private function sender(SendPluginActivityInput $input): ?FediverseActor
    {
        $query = FediverseActor::query()->where('enabled', true);

        return $input->actorUri === null
            ? $query->where('kind', FediverseActor::BLOG)->first()
            : $query->where('uri', $input->actorUri)->first();
    }
}

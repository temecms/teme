<?php

/**
 * Turns a change to a federated item into what its followers are sent
 * (docs/plans/fediverse-publishing.md, D1): a Create the first time it goes
 * public, an Update after, a Delete when it leaves the public site; in one
 * transaction, the activity and one delivery per distinct inbox of the
 * sending actor's followers, a shared inbox standing for every follower on
 * its server. Nothing while the fediverse is paused or an import runs.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing\Operations;

use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\BlockedHosts;
use App\Domain\Fediverse\FediverseSwitch;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\Delivery;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Operations\Concerns\AuthorizesFediverseWork;
use App\Domain\Fediverse\Publishing\Deliveries;
use App\Domain\Fediverse\Publishing\ObjectDocuments;
use App\Domain\Fediverse\Publishing\Operations\Input\QueueActivityInput;
use App\Domain\Fediverse\Publishing\PublishingSettings;
use App\Domain\Fediverse\Publishing\QueuedActivities;
use App\Domain\Fediverse\Publishing\SiteWork;
use App\Domain\Fediverse\Sockets\SentActivities;
use App\Domain\Import\ImportMode;
use App\Domain\Support\Operations\BaseOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Operations\OperationInput;

final class QueueActivity extends BaseOperation
{
    use AuthorizesFediverseWork;

    protected function authorize(OperationInput $input): void
    {
        /** @var QueueActivityInput $input */
        if ($input->actorId === null && SiteWork::issued($input->siteWork)) {
            return;
        }

        $this->authorizeFediverseWork($input, 'Not permitted to send to the fediverse.');
    }

    protected function validate(OperationInput $input): void
    {
        /** @var QueueActivityInput $input */
        if (! in_array($input->change, [QueueActivityInput::PUBLISHED, QueueActivityInput::REMOVED], true)) {
            throw ValidationException::withMessages(['change' => 'Not a change the fediverse is sent.']);
        }
    }

    protected function perform(OperationInput $input): QueuedActivities
    {
        /** @var QueueActivityInput $input */
        if (! FediverseSwitch::on() || app(ImportMode::class)->active()) {
            return new QueuedActivities;
        }

        $item = Content::withTrashed()->whereKey($input->contentId)->lockForUpdate()->first();

        if ($item === null) {
            return new QueuedActivities;
        }

        return $input->change === QueueActivityInput::PUBLISHED ? $this->published($item) : $this->removed($item);
    }

    protected function afterCommit(mixed $result): void
    {
        /** @var QueuedActivities $result */
        app(Deliveries::class)->dispatch($result->deliveryIds);
        app(SentActivities::class)->announce($result->activities);
    }

    /**
     * The scheme, host and any port of an actor's address, so an object
     * lives on the sending actor's host whatever host the editor used.
     */
    private static function origin(string $uri): string
    {
        $parts = parse_url($uri);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function published(Content $item): QueuedActivities
    {
        $objects = app(ObjectDocuments::class);

        if ($item->trashed() || ! ObjectDocuments::isLive($item) || ! app(PublishingSettings::class)->federates($item->type)) {
            return new QueuedActivities;
        }

        $sender = $objects->attributedTo($item);

        if ($sender === null) {
            return new QueuedActivities;
        }

        $object = FediverseObject::query()->where('content_id', $item->id)->where('kind', FediverseObject::POST)->lockForUpdate()->first();
        $create = $object === null || $object->deleted_at !== null;

        if ($object === null) {
            $object = FediverseObject::query()->create([
                'uri' => self::origin($sender->uri).'/'.ActivityPub::PREFIX.'/objects/'.strtolower((string) Str::ulid()),
                'kind' => FediverseObject::POST,
                'content_id' => $item->id,
            ]);
        } elseif ($object->deleted_at !== null) {
            FediverseObject::query()->whereKey($object->uri)->update(['deleted_at' => null]);
        }

        $document = $objects->build($item, $object->uri, $sender, updated: ! $create);
        $queued = [$this->queue($sender, $create ? 'Create' : 'Update', $object->uri, $document)];
        $announcer = $create ? $objects->announcer($sender) : null;

        if ($announcer !== null) {
            $queued[] = $this->queue($announcer, 'Announce', $object->uri, $object->uri);
        }

        return self::joined($queued);
    }

    private function removed(Content $item): QueuedActivities
    {
        $object = FediverseObject::query()->where('content_id', $item->id)->where('kind', FediverseObject::POST)->whereNull('deleted_at')->lockForUpdate()->first();

        if ($object === null) {
            return new QueuedActivities;
        }

        // The actor that sent it takes it back.
        $senderId = Activity::query()->where('object_uri', $object->uri)->whereIn('type', ['Create', 'Update'])->latest('created_at')->latest('id')->value('local_actor_id');
        $sender = is_string($senderId) ? FediverseActor::query()->whereKey($senderId)->where('enabled', true)->first() : null;
        $sender ??= app(ObjectDocuments::class)->attributedTo($item);

        FediverseObject::query()->whereKey($object->uri)->update(['deleted_at' => now()]);

        if ($sender === null) {
            return new QueuedActivities;
        }

        return self::joined([$this->queue($sender, 'Delete', $object->uri, ['id' => $object->uri, 'type' => 'Tombstone'])]);
    }

    /**
     * Stores one activity and its deliveries.
     *
     * @param  array<string, mixed>|string  $object
     */
    private function queue(FediverseActor $actor, string $type, string $objectUri, array|string $object): QueuedActivities
    {
        $activity = Activity::query()->create([
            'local_actor_id' => $actor->id,
            'type' => $type,
            'object_uri' => $objectUri,
            'payload' => [
                '@context' => ActivityPub::CONTEXT,
                'id' => $actor->uri.'#activities/'.strtolower((string) Str::ulid()),
                'type' => $type,
                'actor' => $actor->uri,
                'published' => now()->utc()->toIso8601ZuluString(),
                'to' => [ActivityPub::PUBLIC],
                'cc' => array_filter([$actor->urls['followers'] ?? null]),
                'object' => $object,
            ],
        ]);

        return new QueuedActivities([$activity], self::deliver($activity, $actor));
    }

    /**
     * One delivery per distinct inbox of the actor's followers: the shared
     * inbox of a follower's server when it has one, else its own; none to
     * a blocked server or a follower that is gone.
     *
     * @return list<string>
     */
    public static function deliver(Activity $activity, FediverseActor $actor): array
    {
        $inboxes = DB::table('fediverse_followers as f')
            ->join('fediverse_remote_actors as r', 'r.id', '=', 'f.remote_actor_id')
            ->where('f.local_actor_id', $actor->id)
            ->whereNull('r.gone_at')
            ->selectRaw('coalesce(r.shared_inbox_url, r.inbox_url) as inbox, r.host')
            ->distinct()
            ->orderBy('inbox')
            ->get();

        $ids = [];
        $seen = [];

        foreach ($inboxes as $row) {
            $inbox = (string) $row->inbox;

            if (isset($seen[$inbox]) || BlockedHosts::blocks((string) $row->host)) {
                continue;
            }

            $seen[$inbox] = true;
            $ids[] = (string) Delivery::query()->create([
                'activity_id' => $activity->id,
                'inbox_url' => $inbox,
                'host' => strtolower((string) (parse_url($inbox, PHP_URL_HOST) ?: $row->host)),
                'state' => 'queued',
                'next_attempt_at' => now(),
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  list<QueuedActivities>  $parts
     */
    private static function joined(array $parts): QueuedActivities
    {
        $activities = [];
        $deliveries = [];

        foreach ($parts as $part) {
            array_push($activities, ...$part->activities);
            array_push($deliveries, ...$part->deliveryIds);
        }

        return new QueuedActivities($activities, $deliveries);
    }
}

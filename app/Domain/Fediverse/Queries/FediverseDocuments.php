<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Queries;

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\Paths;
use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Models\Activity;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Publishing\ObjectDocuments;
use App\Domain\People\Models\Person;
use Teme\Contracts\Settings\SettingsReader;

/**
 * The documents the site answers at its fediverse addresses: an actor, its
 * outbox, followers (a count only) and following (always empty), found by
 * the full address asked for, so an imported actor answers at its old one.
 * Read whether the fediverse is on or paused (proposal, Settled 4).
 */
final readonly class FediverseDocuments
{
    /** How many activities the outbox shows (proposal, P3). */
    public const int OUTBOX_SIZE = 20;

    public function __construct(private SettingsReader $settings) {}

    /**
     * The document at this full address, or null when the site has none
     * there.
     *
     * @return array<string, mixed>|null
     */
    public function at(string $url): ?array
    {
        $actor = $this->actorAt($url);

        if ($actor !== null) {
            return $this->actor($actor);
        }

        foreach (['outbox', 'followers', 'following'] as $collection) {
            $owner = FediverseActor::query()->where('enabled', true)->whereRaw('urls->>? = ?', [$collection, $url])->first();

            if ($owner !== null) {
                return $this->{$collection}($owner);
            }
        }

        $object = FediverseObject::query()->whereKey($url)->whereNotNull('deleted_at')->first();

        if ($object !== null) {
            return ['@context' => ActivityPub::CONTEXT, 'id' => $object->uri, 'type' => 'Tombstone', 'deleted' => $object->deleted_at?->toIso8601ZuluString()];
        }

        return app(ObjectDocuments::class)->at($url);
    }

    /**
     * The enabled actor whose address, or one of whose old addresses, is
     * this one, or null.
     */
    public function actorAt(string $url): ?FediverseActor
    {
        return FediverseActor::query()
            ->where('enabled', true)
            ->where(fn ($query) => $query->where('uri', $url)->orWhereRaw('aliases @> ?::jsonb', [json_encode([$url], JSON_THROW_ON_ERROR)]))
            ->first();
    }

    /**
     * The WebFinger answer for acct:name@host or an actor's address, or
     * null when no enabled actor is called that on this site.
     *
     * @return array<string, mixed>|null
     */
    public function webFinger(string $resource): ?array
    {
        $resource = trim($resource);
        $actor = null;

        if (preg_match('/^(?:acct:)?@?([^@\s]+)@([^@\s]+)$/i', $resource, $match) === 1) {
            if (strtolower($match[2]) !== self::host()) {
                return null;
            }

            $actor = FediverseActor::query()->where('enabled', true)->whereRaw('lower(username) = ?', [strtolower($match[1])])->first();
        } elseif (str_starts_with($resource, 'https://') || str_starts_with($resource, 'http://')) {
            $actor = $this->actorAt($resource);
        }

        if ($actor === null) {
            return null;
        }

        $profile = $this->profile($actor);

        return [
            'subject' => 'acct:'.$actor->username.'@'.self::host(),
            'aliases' => array_values(array_unique([$actor->uri, $profile, ...$actor->aliases])),
            'links' => [
                ['rel' => 'self', 'type' => ActivityPub::CONTENT_TYPE, 'href' => $actor->uri],
                ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => $profile],
            ],
        ];
    }

    /**
     * The NodeInfo 2.1 document.
     *
     * @return array<string, mixed>
     */
    public function nodeInfo(): array
    {
        $users = FediverseActor::query()->where('enabled', true)->where('kind', '!=', FediverseActor::APPLICATION)->count();

        return [
            'version' => '2.1',
            'software' => ['name' => 'teme', 'version' => (string) config('teme.version'), 'homepage' => 'https://github.com/temecms/teme'],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => false,
            'usage' => [
                'users' => ['total' => $users, 'activeMonth' => $users, 'activeHalfyear' => $users],
                'localPosts' => FediverseObject::query()->where('kind', FediverseObject::POST)->whereNull('deleted_at')->count(),
            ],
            'metadata' => ['nodeName' => $this->settings->string('general', 'site_title')],
        ];
    }

    /**
     * Where a browser asking for a fediverse address goes instead: the
     * author's page for an actor, the item for an object, else the home page.
     */
    public function browserAddress(string $url): string
    {
        $actor = $this->actorAt($url);

        if ($actor !== null) {
            return $this->profile($actor);
        }

        $contentId = FediverseObject::query()->whereKey($url)->whereNull('deleted_at')->value('content_id');
        $path = is_string($contentId) ? Paths::current('content', $contentId) : null;

        return $path === null ? url('/') : rtrim(url('/'), '/').Addresses::toUrl($path, $this->contentType((string) $contentId));
    }

    /**
     * The site's host, as WebFinger names its accounts.
     */
    public static function host(): string
    {
        return strtolower((string) parse_url(url('/'), PHP_URL_HOST));
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(FediverseActor $actor): array
    {
        $person = $actor->kind === FediverseActor::PERSON ? Person::query()->with('user')->find($actor->person_id) : null;

        $user = $person?->user;

        [$type, $name, $summary] = match ($actor->kind) {
            FediverseActor::PERSON => ['Person', $user !== null ? $user->name : ($person->name ?? $actor->username), $user === null ? $person?->bio : null],
            FediverseActor::APPLICATION => ['Application', $this->settings->string('general', 'site_title'), null],
            default => ['Group', $this->settings->string('general', 'site_title'), $this->settings->string('general', 'tagline')],
        };

        return array_filter([
            '@context' => [ActivityPub::CONTEXT, ActivityPub::SECURITY_CONTEXT],
            'id' => $actor->uri,
            'type' => $type,
            'preferredUsername' => $actor->username,
            'name' => $name,
            'summary' => $summary === null || $summary === '' ? null : e($summary),
            'url' => $this->profile($actor),
            'inbox' => $actor->urls['inbox'] ?? null,
            'outbox' => $actor->urls['outbox'] ?? null,
            'followers' => $actor->urls['followers'] ?? null,
            'following' => $actor->urls['following'] ?? null,
            'endpoints' => ['sharedInbox' => rtrim(url('/'), '/').'/'.ActivityPub::PREFIX.'/inbox'],
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'publicKey' => ['id' => $actor->keyId(), 'owner' => $actor->uri, 'publicKeyPem' => $actor->public_key_pem],
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function outbox(FediverseActor $actor): array
    {
        $activities = Activity::query()->where('local_actor_id', $actor->id)->latest('created_at')->latest('id')->limit(self::OUTBOX_SIZE)->get();

        return [
            '@context' => ActivityPub::CONTEXT,
            'id' => $actor->urls['outbox'] ?? '',
            'type' => 'OrderedCollection',
            'totalItems' => Activity::query()->where('local_actor_id', $actor->id)->count(),
            'orderedItems' => $activities->map(fn (Activity $activity): array => $activity->payload)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function followers(FediverseActor $actor): array
    {
        return [
            '@context' => ActivityPub::CONTEXT,
            'id' => $actor->urls['followers'] ?? '',
            'type' => 'OrderedCollection',
            'totalItems' => Follower::query()->where('local_actor_id', $actor->id)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function following(FediverseActor $actor): array
    {
        return [
            '@context' => ActivityPub::CONTEXT,
            'id' => $actor->urls['following'] ?? '',
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ];
    }

    /**
     * The page a person reads for this actor: the author's page, or the home page.
     */
    private function profile(FediverseActor $actor): string
    {
        if ($actor->person_id !== null) {
            $person = Person::query()->find($actor->person_id);
            [$type, $id] = $person?->user_id === null ? ['person', $actor->person_id] : ['user', (string) $person->user_id];
            $path = Paths::current($type, $id);

            if ($path !== null) {
                return rtrim(url('/'), '/').Addresses::toUrl($path);
            }
        }

        return url('/');
    }

    private function contentType(string $contentId): ?string
    {
        $type = Content::query()->whereKey($contentId)->value('type');

        return is_string($type) ? $type : null;
    }
}

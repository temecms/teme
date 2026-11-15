<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Publishing;

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Visibility;
use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Sockets\ObjectTransforms;
use App\Domain\Feeds\FeedBody;
use App\Domain\People\Credit;
use App\Domain\People\Credits;
use App\Domain\People\Models\Person;
use Carbon\CarbonImmutable;

/**
 * An item as the fediverse reads it (docs/plans/fediverse-publishing.md, D2):
 * its object address, an Article or a Note, the live page as its url, the
 * actor it is attributed to, its body drawn as a feed reader gets it with
 * absolute links (rule 9), public and copied to that actor's followers.
 */
final readonly class ObjectDocuments
{
    public function __construct(
        private PublishingSettings $settings,
        private FeedBody $body,
        private ObjectTransforms $transforms,
    ) {}

    /**
     * The object document a remote server reads at this address: a live,
     * public, federated item's, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function at(string $url): ?array
    {
        $object = FediverseObject::query()->whereKey($url)->where('kind', FediverseObject::POST)->whereNull('deleted_at')->first();
        $item = $object?->content_id === null ? null : Content::query()->find($object->content_id);

        if ($object === null || $item === null || ! self::isLive($item) || ! $this->settings->federates($item->type)) {
            return null;
        }

        $actor = $this->attributedTo($item);

        return $actor === null ? null : ['@context' => ActivityPub::CONTEXT, ...$this->build($item, $object->uri, $actor)];
    }

    /**
     * The object for this item at this address, attributed to this actor,
     * as an activity carries it: without its own @context.
     *
     * @return array<string, mixed>
     */
    public function build(Content $item, string $uri, FediverseActor $actor, bool $updated = false): array
    {
        $revision = ContentRevision::query()->find($item->published_revision_id);
        $type = $this->settings->objectType();
        $url = (string) Addresses::urlFor($item);
        $html = $this->body->html($revision === null ? ['type' => 'doc', 'content' => []] : $revision->document);
        $published = $item->published_at === null ? null : CarbonImmutable::parse((string) $item->published_at)->utc();

        $object = array_filter([
            'id' => $uri,
            'type' => $type,
            'url' => $url,
            'attributedTo' => $actor->uri,
            'name' => $type === 'Article' ? (string) $item->title : null,
            'content' => $type === 'Note' ? '<p><a href="'.e($url).'">'.e((string) $item->title).'</a></p>'.$html : $html,
            'mediaType' => 'text/html',
            'published' => $published?->toIso8601ZuluString(),
            'updated' => $updated && $revision?->created_at !== null ? CarbonImmutable::parse((string) $revision->created_at)->utc()->toIso8601ZuluString() : null,
            'to' => [ActivityPub::PUBLIC],
            'cc' => array_filter([$actor->urls['followers'] ?? null]),
        ], fn (mixed $value): bool => $value !== null);

        return $this->transforms->object($item, $object);
    }

    /**
     * The actor an item is attributed to (D2): in the blog mode the blog
     * actor; otherwise its first byline that has an enabled author actor,
     * else the blog actor. Null when the site has no enabled blog actor
     * either.
     */
    public function attributedTo(Content $item): ?FediverseActor
    {
        $blog = FediverseActor::query()->where('kind', FediverseActor::BLOG)->where('enabled', true)->first();

        if ($this->settings->mode() === PublishingSettings::MODE_BLOG) {
            return $blog;
        }

        foreach (app(Credits::class)->forItems([(string) $item->id => $item->author_id === null ? null : (int) $item->author_id])[(string) $item->id] ?? [] as $credit) {
            $personId = self::personOf($credit);
            $actor = $personId === null ? null : FediverseActor::query()->where('kind', FediverseActor::PERSON)->where('person_id', $personId)->where('enabled', true)->first();

            if ($actor !== null) {
                return $actor;
            }
        }

        return $blog;
    }

    /**
     * The blog actor, when an author actor sends the item and the blog
     * announces it (the actor_blog mode); otherwise null.
     */
    public function announcer(FediverseActor $sender): ?FediverseActor
    {
        if ($this->settings->mode() !== PublishingSettings::MODE_ACTOR_BLOG || $sender->kind === FediverseActor::BLOG) {
            return null;
        }

        return FediverseActor::query()->where('kind', FediverseActor::BLOG)->where('enabled', true)->first();
    }

    /**
     * Whether the public sees the item now: published, public and dated now or earlier.
     */
    public static function isLive(Content $item): bool
    {
        return $item->status === 'published'
            && $item->visibility === Visibility::Public
            && $item->published_at !== null
            && ! CarbonImmutable::parse((string) $item->published_at)->isFuture();
    }

    private static function personOf(Credit $credit): ?string
    {
        if ($credit->kind === Credit::PERSON) {
            return $credit->id;
        }

        $id = Person::query()->where('user_id', (int) $credit->id)->value('id');

        return is_string($id) ? $id : null;
    }
}

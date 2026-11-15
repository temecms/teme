<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Sockets;

use App\Domain\Contents\Models\Content;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Hooks\Payloads;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Fediverse\ActorDocumentBuilt;
use Teme\Contracts\Fediverse\CollectObjectBuilders;
use Teme\Contracts\Fediverse\ObjectBuilder;
use Teme\Contracts\Fediverse\ObjectBuilt;
use Throwable;

/**
 * Where plugins change what core sends (docs/plans/fediverse-publishing.md,
 * D5): an item's object, built by the first plugin builder for its type
 * and then changed by ObjectBuilt, and an actor's document, changed by
 * ActorDocumentBuilt; each with the keys that say who and where put back
 * as core wrote them.
 */
final readonly class ObjectTransforms
{
    /** What a builder may not change in an item's object. */
    public const array BUILDER_KEEPS = ['id', 'attributedTo', 'to', 'cc'];

    /** What ObjectBuilt may not change in an item's object. */
    public const array OBJECT_KEEPS = ['id', 'type', 'attributedTo', 'to', 'cc'];

    /** What ActorDocumentBuilt may not change in an actor's document. */
    public const array ACTOR_KEEPS = ['@context', 'id', 'type', 'preferredUsername', 'inbox', 'outbox', 'followers', 'following', 'endpoints', 'publicKey'];

    public function __construct(private HookDispatcher $hooks) {}

    /**
     * The item's object as plugins leave it.
     *
     * @param  array<string, mixed>  $object
     *
     * @return array<string, mixed>
     */
    public function object(Content $item, array $object): array
    {
        $builds = $this->hooks->wants(CollectObjectBuilders::class);

        if (! $builds && ! $this->hooks->wants(ObjectBuilt::class)) {
            return $object;
        }

        $ref = Payloads::item($item);
        $builder = $builds ? $this->builder($item->type) : null;

        if ($builder !== null) {
            try {
                $object = CoreActivities::keep($builder->build($ref, $object), $object, self::BUILDER_KEEPS);
            } catch (Throwable $failure) {
                Log::warning('A plugin’s fediverse object builder failed.', ['builder' => $builder::class, 'error' => $failure->getMessage()]);
            }
        }

        /** @var array<string, mixed> $changed */
        $changed = $this->hooks->transform(new ObjectBuilt($object, $ref));

        return CoreActivities::keep($changed, $object, self::OBJECT_KEEPS);
    }

    /**
     * The actor's document as plugins leave it.
     *
     * @param  array<string, mixed>  $document
     *
     * @return array<string, mixed>
     */
    public function actor(string $actorUri, array $document): array
    {
        if (! $this->hooks->wants(ActorDocumentBuilt::class)) {
            return $document;
        }

        /** @var array<string, mixed> $changed */
        $changed = $this->hooks->transform(new ActorDocumentBuilt($document, $actorUri));

        return CoreActivities::keep($changed, $document, self::ACTOR_KEEPS);
    }

    /**
     * The first plugin builder for this content type, or null.
     */
    private function builder(string $type): ?ObjectBuilder
    {
        /** @var list<ObjectBuilder> $builders */
        $builders = $this->hooks->collect(new CollectObjectBuilders);

        foreach ($builders as $builder) {
            try {
                if (in_array($type, $builder->contentTypes(), true)) {
                    return $builder;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}

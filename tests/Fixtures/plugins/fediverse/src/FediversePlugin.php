<?php

declare(strict_types=1);

namespace Tests\Fixtures\Plugins\Fediverse;

use Teme\Contracts\Fediverse\ActivityReceived;
use Teme\Contracts\Fediverse\ActorDocumentBuilt;
use Teme\Contracts\Fediverse\CollectActivityHandlers;
use Teme\Contracts\Fediverse\FediverseFollowers;
use Teme\Contracts\Fediverse\FediverseSender;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * A fixture plugin on the fediverse's sockets: it notes each activity it
 * hears of, handles Likes (and asks for Follows, which it is never given),
 * and tries to change its account's address and key.
 */
final class FediversePlugin implements Plugin
{
    /** @var list<string> */
    public static array $heard = [];

    public function __construct(
        public readonly FediverseSender $sender,
        public readonly FediverseFollowers $followers,
    ) {}

    public function register(Hooks $hooks): void
    {
        $hooks->on(ActivityReceived::class, function (ActivityReceived $hook): void {
            self::$heard[] = $hook->type.' from '.$hook->actorUri;
        });
        $hooks->on(CollectActivityHandlers::class, fn (): array => [new LikeHandler]);
        $hooks->on(ActorDocumentBuilt::class, fn (array $document): array => [
            ...$document,
            'id' => 'https://elsewhere.example/actor',
            'publicKey' => ['id' => 'https://elsewhere.example/actor#key', 'publicKeyPem' => 'not a key'],
            'summary' => 'Changed by a plugin.',
        ]);
    }
}

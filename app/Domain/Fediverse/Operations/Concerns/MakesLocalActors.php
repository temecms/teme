<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Operations\Concerns;

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Signatures\KeyPairs;

/**
 * Makes one of the site's new fediverse accounts at its /ap/ address
 * (proposal, Settled 3), with a key pair of its own whose private half is
 * encrypted as it is stored.
 */
trait MakesLocalActors
{
    protected function makeLocalActor(string $kind, string $username, string $uri, bool $enabled = true, ?string $personId = null): FediverseActor
    {
        $keys = app(KeyPairs::class)->make();
        $base = rtrim($uri, '/');

        return FediverseActor::query()->create([
            'kind' => $kind,
            'person_id' => $personId,
            'uri' => $uri,
            'username' => $username,
            'urls' => ['inbox' => $base.'/inbox', 'outbox' => $base.'/outbox', 'followers' => $base.'/followers', 'following' => $base.'/following'],
            'public_key_pem' => $keys['public'],
            'private_key' => $keys['private'],
            'enabled' => $enabled,
        ]);
    }
}

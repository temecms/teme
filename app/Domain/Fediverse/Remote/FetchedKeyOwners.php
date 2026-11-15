<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Remote;

use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Signatures\KeyOwner;
use App\Domain\Fediverse\Signatures\KeyOwners;
use Illuminate\Support\Facades\Cache;

/**
 * Finds who owns a remote key: from the cache of keys fetched lately, from
 * the remote accounts Teme holds, or by fetching the key's actor. A key is
 * accepted only when its actor's own document names it. What is fetched is
 * kept in the cache, never written to a table here: the inbox's job records
 * the account through RecordRemoteActor.
 */
final readonly class FetchedKeyOwners implements KeyOwners
{
    /** How long a fetched key and actor stay in the cache. */
    public const int CACHE_SECONDS = 86_400;

    public function __construct(private RemoteFetcher $fetcher) {}

    public function owner(string $keyId, bool $fresh = false): ?KeyOwner
    {
        if (! $fresh) {
            $known = $this->known($keyId);

            if ($known !== null) {
                return $known;
            }
        }

        try {
            return $this->fetch($keyId);
        } catch (FetchRefused) {
            return null;
        }
    }

    /**
     * The actor document fetched lately for this address, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function cachedDocument(string $actorUri): ?array
    {
        $stored = Cache::get(self::documentKey($actorUri));
        $document = is_string($stored) ? json_decode($stored, true) : null;

        /** @var array<string, mixed>|null $document */
        return is_array($document) ? $document : null;
    }

    /**
     * Keeps an actor document fetched just now, for the inbox's job.
     *
     * @param  array<string, mixed>  $document
     */
    public static function remember(array $document): void
    {
        if (is_string($document['id'] ?? null)) {
            Cache::put(self::documentKey($document['id']), json_encode($document, JSON_THROW_ON_ERROR), self::CACHE_SECONDS);
        }
    }

    /**
     * The public key an actor document names for this key id, or null.
     *
     * @param  array<string, mixed>  $document
     */
    public static function keyIn(array $document, string $keyId): ?string
    {
        $keys = $document['publicKey'] ?? null;
        $keys = is_array($keys) && array_is_list($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            if (is_array($key) && ($key['id'] ?? null) === $keyId && is_string($key['publicKeyPem'] ?? null)
                && ($key['owner'] ?? $document['id'] ?? null) === ($document['id'] ?? null)) {
                return $key['publicKeyPem'];
            }
        }

        return null;
    }

    private function known(string $keyId): ?KeyOwner
    {
        $cached = Cache::get(self::keyKey($keyId));

        if (is_string($cached)) {
            $owner = json_decode($cached, true);

            if (is_array($owner) && is_string($owner['owner'] ?? null) && is_string($owner['pem'] ?? null)) {
                return new KeyOwner($keyId, $owner['owner'], $owner['pem']);
            }
        }

        $remote = RemoteActor::query()->where('key_id', $keyId)->whereNull('gone_at')->first();

        return $remote === null ? null : new KeyOwner($keyId, $remote->uri, $remote->public_key_pem);
    }

    /**
     * @throws FetchRefused
     */
    private function fetch(string $keyId): ?KeyOwner
    {
        $address = explode('#', $keyId, 2)[0];
        $document = $this->fetcher->get($address);

        // A key's own document names its owner, whose document must name the key back.
        if (! isset($document['publicKey']) && is_string($document['owner'] ?? null)) {
            $document = $this->fetcher->get($document['owner']);
        }

        $pem = self::keyIn($document, $keyId);

        if ($pem === null || ! is_string($document['id'] ?? null)) {
            return null;
        }

        self::remember($document);
        Cache::put(self::keyKey($keyId), json_encode(['owner' => $document['id'], 'pem' => $pem], JSON_THROW_ON_ERROR), self::CACHE_SECONDS);

        return new KeyOwner($keyId, $document['id'], $pem);
    }

    private static function keyKey(string $keyId): string
    {
        return 'fediverse:key:'.hash('sha256', $keyId);
    }

    private static function documentKey(string $actorUri): string
    {
        return 'fediverse:actor:'.hash('sha256', $actorUri);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Fediverse;

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\RemoteActor;

/**
 * The site's and remote accounts for the fediverse's tests, each with a
 * fixed key pair from keys/, so no test waits for a key to be made. The
 * keys are for tests only and sign nothing anywhere else.
 */
final class Actors
{
    /**
     * A key pair from keys/: site or remote.
     *
     * @return array{public: string, private: string}
     */
    public static function keys(string $name = 'site'): array
    {
        return [
            'public' => (string) file_get_contents(__DIR__."/keys/{$name}-public.pem"),
            'private' => (string) file_get_contents(__DIR__."/keys/{$name}-private.pem"),
        ];
    }

    /**
     * The site's blog actor, at the site's own address unless told otherwise.
     */
    public static function blog(string $username = 'blog', ?string $uri = null): FediverseActor
    {
        return self::local(FediverseActor::BLOG, $username, $uri ?? url('/ap/blog'));
    }

    /**
     * The site's application actor, which signs what Teme fetches.
     */
    public static function application(): FediverseActor
    {
        return self::local(FediverseActor::APPLICATION, (string) (parse_url(url('/'), PHP_URL_HOST) ?: 'localhost'), url('/ap/application'));
    }

    /**
     * One of the site's actors, as stored.
     *
     * @param  list<string>  $aliases
     */
    public static function local(string $kind, string $name, string $uri, ?string $personId = null, array $aliases = [], ?string $username = null): FediverseActor
    {
        $keys = self::keys();
        $base = rtrim($uri, '/');

        return FediverseActor::query()->create([
            'kind' => $kind,
            'person_id' => $personId,
            'uri' => $uri,
            'username' => $username ?? $name,
            'urls' => ['inbox' => $base.'/inbox', 'outbox' => $base.'/outbox', 'followers' => $base.'/followers', 'following' => $base.'/following'],
            'aliases' => $aliases,
            'public_key_pem' => $keys['public'],
            'private_key' => $keys['private'],
        ]);
    }

    /**
     * A remote account on another server, with the remote key pair.
     */
    public static function remote(string $uri = 'https://remote.example/users/alice', array $overrides = []): RemoteActor
    {
        $host = (string) parse_url($uri, PHP_URL_HOST);

        return RemoteActor::query()->create([
            'uri' => $uri,
            'handle' => 'alice@'.$host,
            'host' => $host,
            'name' => 'Alice',
            'inbox_url' => $uri.'/inbox',
            'shared_inbox_url' => 'https://'.$host.'/inbox',
            'key_id' => $uri.'#main-key',
            'public_key_pem' => self::keys('remote')['public'],
            'document' => self::remoteDocument($uri),
            'fetched_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * The actor document a remote server answers for this account.
     *
     * @return array<string, mixed>
     */
    public static function remoteDocument(string $uri = 'https://remote.example/users/alice', ?string $publicKey = null): array
    {
        $host = (string) parse_url($uri, PHP_URL_HOST);

        return [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $uri,
            'type' => 'Person',
            'preferredUsername' => 'alice',
            'name' => 'Alice',
            'url' => 'https://'.$host.'/@alice',
            'inbox' => $uri.'/inbox',
            'outbox' => $uri.'/outbox',
            'endpoints' => ['sharedInbox' => 'https://'.$host.'/inbox'],
            'publicKey' => ['id' => $uri.'#main-key', 'owner' => $uri, 'publicKeyPem' => $publicKey ?? self::keys('remote')['public']],
        ];
    }
}

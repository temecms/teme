<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Remote;

use InvalidArgumentException;

/**
 * Reads a remote actor document into what fediverse_remote_actors keeps:
 * its address, handle, host, name, pages, inboxes and key, the one the
 * sender signed with when known. Refuses one without an https address, an
 * https inbox on the same server, or a key on the same server; a shared
 * inbox, when it names one, must be on the same server too.
 */
final class RemoteActorDocument
{
    /**
     * The columns for this document, keeping the key $keyId names, or its first.
     *
     * @param  array<string, mixed>  $document
     *
     * @return array{uri: string, handle: string|null, host: string, name: string|null, profile_url: string|null, avatar_url: string|null, inbox_url: string, shared_inbox_url: string|null, key_id: string, public_key_pem: string, document: array<string, mixed>}
     *
     * @throws InvalidArgumentException
     */
    public static function attributes(array $document, ?string $keyId = null): array
    {
        $uri = self::https($document['id'] ?? null);
        $host = strtolower((string) parse_url((string) $uri, PHP_URL_HOST));
        $inbox = self::https($document['inbox'] ?? null);

        if ($uri === null || $inbox === null) {
            throw new InvalidArgumentException('The actor has no https address or inbox.');
        }

        $keys = $document['publicKey'] ?? null;
        $keys = is_array($keys) && array_is_list($keys) ? $keys : [$keys];
        $key = $keyId === null ? ($keys[0] ?? null) : (array_values(array_filter($keys, fn (mixed $entry): bool => is_array($entry) && ($entry['id'] ?? null) === $keyId))[0] ?? null);

        if (! is_array($key) || ! is_string($key['id'] ?? null) || ! is_string($key['publicKeyPem'] ?? null)) {
            throw new InvalidArgumentException('The actor has no public key.');
        }

        $endpoints = is_array($document['endpoints'] ?? null) ? $document['endpoints'] : [];
        $shared = self::https($endpoints['sharedInbox'] ?? null);

        foreach ([$key['id'], $inbox, $shared] as $address) {
            if ($address !== null && strtolower((string) parse_url($address, PHP_URL_HOST)) !== $host) {
                throw new InvalidArgumentException('The actor’s key and inboxes must be on its own server.');
            }
        }
        $username = is_string($document['preferredUsername'] ?? null) ? $document['preferredUsername'] : null;
        $icon = $document['icon'] ?? null;
        $icon = is_array($icon) && array_is_list($icon) ? ($icon[0] ?? null) : $icon;
        $url = $document['url'] ?? null;
        $url = is_array($url) ? ($url['href'] ?? null) : $url;

        return [
            'uri' => $uri,
            'handle' => $username === null ? null : mb_substr($username.'@'.$host, 0, 255),
            'host' => $host,
            'name' => is_string($document['name'] ?? null) ? mb_substr(strip_tags($document['name']), 0, 1000) : null,
            'profile_url' => self::https($url),
            'avatar_url' => is_array($icon) ? self::https($icon['url'] ?? null) : null,
            'inbox_url' => $inbox,
            'shared_inbox_url' => $shared,
            'key_id' => $key['id'],
            'public_key_pem' => $key['publicKeyPem'],
            'document' => $document,
        ];
    }

    private static function https(mixed $value): ?string
    {
        return is_string($value) && str_starts_with(strtolower($value), 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}

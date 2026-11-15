<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Remote;

use InvalidArgumentException;

/**
 * Reads a remote actor document into what fediverse_remote_actors keeps:
 * its address, handle, host, name, pages, inboxes and key. Refuses one
 * without an https address, an https inbox on the same server, or a key.
 */
final class RemoteActorDocument
{
    /**
     * The columns for this document.
     *
     * @param  array<string, mixed>  $document
     *
     * @return array{uri: string, handle: string|null, host: string, name: string|null, profile_url: string|null, avatar_url: string|null, inbox_url: string, shared_inbox_url: string|null, key_id: string, public_key_pem: string, document: array<string, mixed>}
     *
     * @throws InvalidArgumentException
     */
    public static function attributes(array $document): array
    {
        $uri = self::https($document['id'] ?? null);
        $host = strtolower((string) parse_url((string) $uri, PHP_URL_HOST));
        $inbox = self::https($document['inbox'] ?? null);

        if ($uri === null || $inbox === null) {
            throw new InvalidArgumentException('The actor has no https address or inbox.');
        }

        $key = $document['publicKey'] ?? null;
        $key = is_array($key) && array_is_list($key) ? ($key[0] ?? null) : $key;

        if (! is_array($key) || ! is_string($key['id'] ?? null) || ! is_string($key['publicKeyPem'] ?? null)) {
            throw new InvalidArgumentException('The actor has no public key.');
        }

        $endpoints = is_array($document['endpoints'] ?? null) ? $document['endpoints'] : [];
        $shared = self::https($endpoints['sharedInbox'] ?? null);
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

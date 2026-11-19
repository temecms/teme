<?php

/**
 * The companion's fediverse route (docs/specs/companion-fediverse-export.md):
 * the WordPress ActivityPub plugin's settings and accounts, whole, and its
 * followers, the sources of its replies and its older posts' permalinks,
 * each a keyset page. Reads an answer into plain shapes, refusing one that
 * isn't the route's.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fediverse\Import;

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Source\SourceRefused;

final class FediverseExport
{
    /** The companion's export API that first has the route. */
    public const int API = 3;

    /** The route's name under teme/v1/. */
    public const string ROUTE = 'fediverse';

    /** The plugin's three ways of being followed. */
    public const array MODES = ['actor', 'blog', 'actor_blog'];

    /** The largest page of followers the route gives. */
    public const int FOLLOWERS_PAGE = 200;

    /** The largest page of reply sources the route gives. */
    public const int REPLIES_PAGE = 500;

    /** The most posts one request for permalinks names. */
    public const int PERMALINKS_ASKED = 200;

    /**
     * Returns whether the source's companion answers the route.
     */
    public static function answered(string $sourceId): bool
    {
        return (int) ImportSource::query()->whereKey($sourceId)->value('api_version') >= self::API;
    }

    /**
     * The route's whole answer, read: whether the plugin was active, the
     * site's home, its settings and accounts, and how many accounts the
     * site followed.
     *
     * @param  array<string, mixed>  $answer
     *
     * @return array{active: bool, home: string, settings: array{mode: string, blog_identifier: string, object_type: string, post_types: list<string>, last_permalink_id: int, blog_also_known_as: list<string>, blog_moved_to: string|null}, actors: list<array<string, mixed>>, follows: int}
     *
     * @throws SourceRefused for an answer that isn't the route's
     */
    public static function whole(array $answer): array
    {
        $settings = $answer['settings'] ?? null;
        $actors = $answer['actors'] ?? null;
        $home = $answer['home'] ?? null;

        if (! is_array($settings) || ! is_array($actors) || ! array_is_list($actors) || ! is_string($home) || ! self::isHttp($home)) {
            throw SourceRefused::badAnswer();
        }

        $mode = $settings['activitypub_actor_mode'] ?? 'actor';
        $types = $settings['activitypub_support_post_types'] ?? ['post'];

        return [
            'active' => ($answer['active'] ?? false) === true,
            'home' => $home,
            'settings' => [
                'mode' => is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'actor',
                'blog_identifier' => is_string($settings['activitypub_blog_identifier'] ?? null) ? $settings['activitypub_blog_identifier'] : '',
                'object_type' => is_string($settings['activitypub_object_type'] ?? null) ? $settings['activitypub_object_type'] : '',
                'post_types' => is_array($types) ? array_values(array_filter($types, is_string(...))) : [],
                'last_permalink_id' => is_numeric($settings['activitypub_last_post_with_permalink_as_id'] ?? null) ? max(0, (int) $settings['activitypub_last_post_with_permalink_as_id']) : 0,
                'blog_also_known_as' => self::strings($settings['activitypub_blog_user_also_known_as'] ?? []),
                'blog_moved_to' => is_string($settings['activitypub_blog_user_moved_to'] ?? null) && $settings['activitypub_blog_user_moved_to'] !== '' ? $settings['activitypub_blog_user_moved_to'] : null,
            ],
            'actors' => array_values(array_filter($actors, is_array(...))),
            'follows' => is_int($answer['followed_by'] ?? null) ? max(0, $answer['followed_by']) : 0,
        ];
    }

    /**
     * One account from the whole answer, read, or null when it lacks an
     * address of its own: a key half the plugin hadn't is an empty string.
     *
     * @param  array<string, mixed>  $actor
     *
     * @return array{user_id: int, login: string, username: string, id: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string|null, addresses: list<string>, public_key_pem: string, private_key_pem: string, also_known_as: list<string>, moved_to: string|null}|null
     */
    public static function actor(array $actor): ?array
    {
        $urls = [];

        foreach (['id', 'inbox', 'outbox', 'followers', 'following'] as $key) {
            if (! is_string($actor[$key] ?? null) || ! self::isHttp($actor[$key])) {
                return null;
            }

            $urls[$key] = $actor[$key];
        }

        $userId = $actor['user_id'] ?? null;

        if (! is_int($userId) || $userId < 0) {
            return null;
        }

        $shared = $actor['shared_inbox'] ?? null;
        $movedTo = $actor['moved_to'] ?? null;

        return [
            'user_id' => $userId,
            'login' => is_string($actor['user_login'] ?? null) ? $actor['user_login'] : '',
            'username' => is_string($actor['preferred_username'] ?? null) ? $actor['preferred_username'] : '',
            ...$urls,
            'shared_inbox' => is_string($shared) && self::isHttp($shared) ? $shared : null,
            'addresses' => array_values(array_filter(self::strings($actor['addresses'] ?? []), self::isHttp(...))),
            'public_key_pem' => is_string($actor['public_key_pem'] ?? null) ? $actor['public_key_pem'] : '',
            'private_key_pem' => is_string($actor['private_key_pem'] ?? null) ? $actor['private_key_pem'] : '',
            'also_known_as' => self::strings($actor['also_known_as'] ?? []),
            'moved_to' => is_string($movedTo) && $movedTo !== '' ? $movedTo : null,
        ];
    }

    /**
     * Whether this is an http or https address with a host.
     */
    public static function isHttp(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        return is_array($values) ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== '')) : [];
    }
}

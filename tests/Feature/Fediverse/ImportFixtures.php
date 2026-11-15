<?php

declare(strict_types=1);

namespace Tests\Feature\Fediverse;

use App\Domain\Contents\Models\Content;
use App\Domain\Fediverse\Import\FediverseRepliesStep;
use App\Domain\Fediverse\Import\FediverseStep;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\People\Models\Person;
use App\Models\User;
use Tests\Fixtures\Fediverse\Actors;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;

/**
 * The fediverse import's test fixtures (docs/plans/fediverse-import.md): the
 * WordPress ActivityPub plugin as the companion's fediverse route answers
 * for it, the people and posts an earlier phase left in the map, and a run
 * of the two fediverse steps alone. Shared by the Import* tests here.
 */
final class ImportFixtures
{
    public const string HOME = 'https://old.example';

    /** The REST root the plugin's addresses hang from. */
    public const string REST = self::HOME.'/wp-json/activitypub/1.0';

    /**
     * Registers the two fediverse steps alone, as their provider would.
     */
    public static function steps(): void
    {
        importPhaseAlone(app(FediverseRepliesStep::class), app(FediverseStep::class));
    }

    /**
     * The companion answers the probe with this export API.
     */
    public static function api(FakeCompanion $companion, int $api): void
    {
        $companion->items('probe', ['name' => 'teme-companion', 'version' => '0.4.0', 'api' => $api, 'application_passwords' => true]);
    }

    /**
     * One account as the route sends it, its addresses as the plugin makes
     * them: `/?author=N` and the REST root's actor addresses, unless $id
     * says otherwise; its key pair from keys/ unless $keys says otherwise.
     *
     * @param  array{public: string, private: string}|null  $keys
     * @param  array<string, mixed>  $changes
     *
     * @return array<string, mixed>
     */
    public static function actor(int $userId, string $username, ?array $keys = null, ?string $id = null, array $changes = []): array
    {
        $keys ??= Actors::keys($userId === 0 ? 'site' : 'remote');
        $id ??= self::HOME.'/?author='.$userId;
        $rest = self::REST.'/actors/'.$userId;

        return [
            'user_id' => $userId,
            'user_login' => $username,
            'preferred_username' => $username,
            'id' => $id,
            'url' => $id,
            'inbox' => $rest.'/inbox',
            'outbox' => $rest.'/outbox',
            'followers' => $rest.'/followers',
            'following' => $rest.'/following',
            'shared_inbox' => self::REST.'/inbox',
            'addresses' => array_values(array_unique([$id, self::HOME.'/?author='.$userId, self::HOME.'/@'.$username])),
            'public_key_pem' => $keys['public'],
            'private_key_pem' => $keys['private'],
            'use_permalink_as_id' => false,
            'also_known_as' => [],
            'moved_to' => null,
            ...$changes,
        ];
    }

    /**
     * The route's whole answer: the plugin active in $mode, with the blog
     * and Ada as its accounts unless $actors says otherwise.
     *
     * @param  list<array<string, mixed>>|null  $actors
     * @param  array<string, mixed>  $settings
     *
     * @return array<string, mixed>
     */
    public static function whole(string $mode = 'actor_blog', ?array $actors = null, array $settings = [], bool $active = true, int $followedBy = 0): array
    {
        return [
            'api' => 3,
            'active' => $active,
            'home' => self::HOME,
            'settings' => [
                'activitypub_actor_mode' => $mode,
                'activitypub_blog_identifier' => 'old.example',
                'activitypub_use_permalink_as_id_for_blog' => false,
                'activitypub_last_post_with_permalink_as_id' => 0,
                'activitypub_object_type' => 'wordpress-post-format',
                'activitypub_support_post_types' => ['post'],
                'activitypub_blog_user_also_known_as' => [],
                'activitypub_blog_user_moved_to' => '',
                ...$settings,
            ],
            'actors' => $actors ?? [self::actor(0, 'old.example'), self::actor(1, 'ada')],
            'followed_by' => $followedBy,
        ];
    }

    /**
     * A remote account's document as WordPress stored it when it followed.
     *
     * @return array<string, mixed>
     */
    public static function remoteDocument(string $uri): array
    {
        return [
            '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
            'id' => $uri,
            'type' => 'Person',
            'preferredUsername' => basename($uri),
            'name' => ucfirst(basename($uri)),
            'inbox' => $uri.'/inbox',
            'endpoints' => ['sharedInbox' => 'https://'.parse_url($uri, PHP_URL_HOST).'/inbox'],
            'publicKey' => ['id' => $uri.'#main-key', 'owner' => $uri, 'publicKeyPem' => Actors::keys('remote')['public']],
        ];
    }

    /**
     * One follower as the route's page sends it: WordPress's `ap_actor`
     * post $postId for $uri, following the accounts of these user IDs.
     *
     * @param  list<int>  $following
     *
     * @return array<string, mixed>
     */
    public static function follower(int $postId, string $uri, array $following): array
    {
        return [
            'id' => $postId,
            'guid' => $uri,
            'actor' => self::remoteDocument($uri),
            'following' => $following,
            'inbox' => $uri.'/inbox',
            'acct' => basename($uri).'@'.parse_url($uri, PHP_URL_HOST),
            'since' => '2024-03-04 05:06:07',
        ];
    }

    /**
     * WordPress user $wordpressId as the users phase leaves them: a person
     * with an account, and the map row.
     */
    public static function person(ImportSource $source, int $wordpressId): Person
    {
        $user = User::factory()->create();
        $person = Person::query()->create(['user_id' => $user->id]);
        ImportRecords::entry($source->id, ImportRecords::run($source->id), 'user', (string) $wordpressId, 'created', ['teme_type' => 'user', 'teme_id' => $user->id]);

        return $person;
    }

    /**
     * WordPress post $wordpressId as the content phase leaves it: an item
     * of $type, published unless $status says otherwise, and the map row.
     */
    public static function post(ImportSource $source, int $wordpressId, string $status = 'published', string $type = 'post'): Content
    {
        $item = Content::factory()->create(['type' => $type, 'status' => $status, 'published_at' => $status === 'published' ? now()->subDay() : null]);
        ImportRecords::entry($source->id, ImportRecords::run($source->id), 'post', (string) $wordpressId, 'created', ['teme_type' => 'content', 'teme_id' => $item->id]);

        return $item;
    }

    /**
     * A run of the source, by the operator, carried to its end.
     */
    public static function run(ImportSource $source, ImportRunKind $kind = ImportRunKind::Full): ImportRun
    {
        return importRun($source, kind: $kind);
    }
}

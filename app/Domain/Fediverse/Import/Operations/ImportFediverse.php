<?php

declare(strict_types=1);

namespace App\Domain\Fediverse\Import\Operations;

use App\Domain\Fediverse\ActivityPub;
use App\Domain\Fediverse\FediverseSettingsGroup;
use App\Domain\Fediverse\Import\FediverseExport;
use App\Domain\Fediverse\Import\Operations\Input\ImportFediverseInput;
use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Fediverse\Models\RemoteActor;
use App\Domain\Fediverse\Operations\Concerns\MakesLocalActors;
use App\Domain\Fediverse\Remote\FetchedKeyOwners;
use App\Domain\Fediverse\Remote\RemoteActorDocument;
use App\Domain\Fediverse\Signatures\KeyPairs;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\Concerns\AuthorizesImports;
use App\Domain\Import\Operations\ImportSettings;
use App\Domain\Import\Operations\Input\ImportSettingsInput;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\People\Models\Person;
use App\Domain\Settings\Modules;
use App\Domain\Support\Operations\BaseOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Teme\Contracts\Operations\OperationInput;
use Throwable;

/**
 * Writes what the WordPress ActivityPub plugin had (docs/plans/fediverse-import.md):
 * its accounts, each at its old address with its key pair, never replaced
 * once written; its settings and switch; its followers, replaced on every
 * run; and the addresses of the posts and replies it federated. The private
 * key is encrypted as it is stored, and never put in the map, a message or a log.
 */
final class ImportFediverse extends BaseOperation
{
    use AuthorizesImports;
    use MakesLocalActors;

    /** The map's object type for a WordPress account, by its user ID, 0 for the blog. */
    public const string ACTOR = 'fediverse-actor';

    /** The map's object type for a remote account WordPress kept as a follower, by its post ID. */
    public const string FOLLOWER = 'fediverse-follower';

    /** The map's object type for the run's own facts: the site's home, its federated types and its permalink rule. */
    public const string SITE = 'fediverse-site';

    /** The step whose settings ImportSettings fingerprints. */
    private const string STEP = 'fediverse';

    /** A name WebFinger can answer: no space, no @, no slash. */
    private const string USERNAME = '/^[^\s@\/]{1,100}$/u';

    protected function authorize(OperationInput $input): void
    {
        $this->authorizeImporter($input->actorId());
    }

    protected function validate(OperationInput $input): void
    {
        /** @var ImportFediverseInput $input */
        $parts = [ImportFediverseInput::ACTORS, ImportFediverseInput::FOLLOWERS, ImportFediverseInput::SWEEP, ImportFediverseInput::OBJECTS, ImportFediverseInput::REPLIES];

        if (! in_array($input->part, $parts, true)) {
            throw ValidationException::withMessages(['part' => "No part [{$input->part}] of the fediverse import."]);
        }

        foreach ($input->part === ImportFediverseInput::OBJECTS || $input->part === ImportFediverseInput::REPLIES ? (array) ($input->data['links'] ?? []) : [] as $link) {
            if (! is_array($link) || ! is_string($link['uri'] ?? null) || ! FediverseExport::isHttp($link['uri']) || ! is_string($link['id'] ?? null)) {
                throw ValidationException::withMessages(['links' => 'Each address needs an http or https URL and the item it names.']);
            }
        }
    }

    /**
     * @return list<ImportMessage>
     */
    protected function perform(OperationInput $input): array
    {
        /** @var ImportFediverseInput $input */
        return match ($input->part) {
            ImportFediverseInput::ACTORS => $this->actors($input),
            ImportFediverseInput::FOLLOWERS => $this->followers($input),
            ImportFediverseInput::SWEEP => $this->sweep($input),
            ImportFediverseInput::OBJECTS => $this->links($input, FediverseObject::POST),
            default => $this->links($input, FediverseObject::REPLY),
        };
    }

    /**
     * The accounts, the settings, the switch and the run's own facts.
     *
     * @return list<ImportMessage>
     */
    private function actors(ImportFediverseInput $input): array
    {
        /** @var array{active: bool, home: string, settings: array{mode: string, blog_identifier: string, object_type: string, post_types: list<string>, last_permalink_id: int, blog_also_known_as: list<string>, blog_moved_to: string|null}, actors: list<array<string, mixed>>, follows: int} $export */
        $export = $input->data;
        $mode = $export['settings']['mode'];
        $messages = [];

        foreach ($export['actors'] as $raw) {
            $actor = FediverseExport::actor($raw);

            if ($actor === null) {
                $messages[] = new ImportMessage('fediverse.account-unreadable', __('One of the WordPress plugin’s accounts had no address of its own, so it wasn’t brought across.'));

                continue;
            }

            if ($actor['user_id'] === 0) {
                $actor['also_known_as'] = $actor['also_known_as'] === [] ? $export['settings']['blog_also_known_as'] : $actor['also_known_as'];
                $actor['moved_to'] ??= $export['settings']['blog_moved_to'];
            }

            array_push($messages, ...$this->actor($input, $actor, $mode));
        }

        $followable = match ($mode) {
            'blog' => FediverseSettingsGroup::BLOG,
            'actor_blog' => FediverseSettingsGroup::BOTH,
            default => FediverseSettingsGroup::AUTHORS,
        };
        $values = [FediverseSettingsGroup::KEY => [FediverseSettingsGroup::FOLLOWABLE => $followable]];

        if ($export['active']) {
            $values['modules'] = [Modules::FEDIVERSE => Modules::ON];
        }

        $written = app(ImportSettings::class)->execute(new ImportSettingsInput($input->sourceId, $input->runId, self::STEP, $values, $input->actorId()));

        if ($written['kept'] !== []) {
            $messages[] = new ImportMessage('fediverse.settings-kept', __('Changed here since the last import, so kept as they are: :settings.', ['settings' => implode(', ', $written['kept'])]), ['settings' => $written['kept']]);
        }

        if ($export['active']) {
            $this->siteActors($followable);
        }

        array_push($messages, ...self::unkept($export));
        $this->entry($input, self::SITE, 'settings', 'setting', self::SITE, [
            'home' => $export['home'],
            'post_types' => $export['settings']['post_types'],
            'last_permalink_id' => $export['settings']['last_permalink_id'],
        ]);

        return $messages;
    }

    /**
     * One account: made at its old address, linked to its person through
     * the map, unless an earlier run made it.
     *
     * @param  array{user_id: int, login: string, username: string, id: string, inbox: string, outbox: string, followers: string, following: string, shared_inbox: string|null, addresses: list<string>, public_key_pem: string, private_key_pem: string, also_known_as: list<string>, moved_to: string|null}  $actor
     *
     * @return list<ImportMessage>
     */
    private function actor(ImportFediverseInput $input, array $actor, string $mode): array
    {
        $who = ['wordpress_user' => $actor['user_id']];
        $existing = FediverseActor::query()->where('uri', $actor['id'])->lockForUpdate()->first();

        // Written once and never replaced: its followers know it by this key.
        if ($existing !== null) {
            $this->entry($input, self::ACTOR, (string) $actor['user_id'], 'fediverse_actor', $existing->id);

            return [];
        }

        $blog = $actor['user_id'] === 0;
        $personId = null;

        if ($blog) {
            if (FediverseActor::query()->where('kind', FediverseActor::BLOG)->exists()) {
                return [ImportMessage::error('fediverse.blog-taken', __('Teme already has a blog account of its own, so the WordPress blog’s account and its followers weren’t brought across.'), $who)];
            }
        } else {
            $userId = DB::table('import_map')->where('source_id', $input->sourceId)->where('object_type', 'user')
                ->where('object_id', (string) $actor['user_id'])->where('teme_type', 'user')->value('teme_id');
            $personId = $userId === null ? null : Person::query()->where('user_id', $userId)->value('id');

            if (! is_string($personId)) {
                return [ImportMessage::error('fediverse.author-missing', __('A WordPress author’s fediverse account wasn’t brought across, because the import didn’t bring the author.'), $who)];
            }

            if (FediverseActor::query()->where('person_id', $personId)->exists()) {
                return [ImportMessage::error('fediverse.author-taken', __('An author already has a fediverse account in Teme, so their WordPress account and its followers weren’t brought across.'), $who)];
            }
        }

        $username = $actor['username'] !== '' ? $actor['username'] : $actor['login'];

        if (preg_match(self::USERNAME, $username) !== 1 || FediverseActor::query()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            return [ImportMessage::error('fediverse.name-taken', __('A WordPress fediverse account’s name is taken in Teme or isn’t one Teme can answer for, so it and its followers weren’t brought across.'), $who)];
        }

        $messages = [];
        $keys = ['public' => $actor['public_key_pem'], 'private' => $actor['private_key_pem']];

        if (! self::pairs($keys['public'], $keys['private'])) {
            $keys = app(KeyPairs::class)->make();
            $messages[] = new ImportMessage('fediverse.key-made', __('WordPress had no usable key for a fediverse account, so Teme made a new one. Its followers’ servers pick it up the next time they hear from it.'), $who);
        }

        $made = FediverseActor::query()->create([
            'kind' => $blog ? FediverseActor::BLOG : FediverseActor::PERSON,
            'person_id' => $personId,
            'uri' => $actor['id'],
            'username' => $username,
            'urls' => array_filter([
                'inbox' => $actor['inbox'],
                'outbox' => $actor['outbox'],
                'followers' => $actor['followers'],
                'following' => $actor['following'],
                'sharedInbox' => $actor['shared_inbox'],
            ], fn (?string $url): bool => $url !== null),
            'aliases' => array_values(array_unique(array_diff($actor['addresses'], [$actor['id']]))),
            'public_key_pem' => $keys['public'],
            'private_key' => $keys['private'],
            'enabled' => $blog ? $mode !== 'actor' : $mode !== 'blog',
        ]);

        $this->entry($input, self::ACTOR, (string) $actor['user_id'], 'fediverse_actor', $made->id);

        if ($actor['also_known_as'] !== [] || $actor['moved_to'] !== null) {
            $messages[] = new ImportMessage('fediverse.moves-not-kept', __('A WordPress fediverse account named other accounts it had moved from or to. Teme doesn’t keep those yet, so a later move starts again from this account.'), $who);
        }

        return $messages;
    }

    /**
     * The blog's and the application's accounts at Teme's own addresses, as
     * switching the fediverse on makes them, when the import brought none.
     */
    private function siteActors(string $followable): void
    {
        $base = rtrim(url('/'), '/').'/'.ActivityPub::PREFIX;

        if (! FediverseActor::query()->where('kind', FediverseActor::BLOG)->exists()
            && ! FediverseActor::query()->whereRaw('lower(username) = ?', ['blog'])->exists()) {
            $this->makeLocalActor(FediverseActor::BLOG, 'blog', $base.'/blog', $followable !== FediverseSettingsGroup::AUTHORS);
        }

        if (! FediverseActor::query()->where('kind', FediverseActor::APPLICATION)->exists()) {
            $host = (string) (parse_url(url('/'), PHP_URL_HOST) ?: 'localhost');
            $this->makeLocalActor(FediverseActor::APPLICATION, $host, $base.'/application');
        }
    }

    /**
     * What WordPress chose that Teme has no place for, said once.
     *
     * @param  array{follows: int, settings: array{object_type: string, post_types: list<string>}}  $export
     *
     * @return list<ImportMessage>
     */
    private static function unkept(array $export): array
    {
        $messages = [];

        if ($export['settings']['object_type'] === 'note') {
            $messages[] = new ImportMessage('fediverse.notes', __('WordPress shared posts on the fediverse as notes. Teme shares them as articles, which Mastodon shows as a title and a link.'));
        }

        $types = array_values(array_diff($export['settings']['post_types'], ['post']));

        if ($types !== []) {
            $messages[] = new ImportMessage('fediverse.types', __('WordPress also shared these types on the fediverse: :types. Teme shares posts only.', ['types' => implode(', ', $types)]), ['types' => $types]);
        }

        if ($export['follows'] > 0) {
            $messages[] = new ImportMessage('fediverse.site-follows', __('The site followed :count fediverse accounts from WordPress. Teme doesn’t follow other accounts, so those weren’t brought across.', ['count' => $export['follows']]), ['count' => $export['follows']]);
        }

        return $messages;
    }

    /**
     * A page of followers: each remote account kept as WordPress stored its
     * document, unless Teme has it already, and following exactly the
     * imported accounts WordPress says it followed.
     *
     * @return list<ImportMessage>
     */
    private function followers(ImportFediverseInput $input): array
    {
        $locals = self::importedActors($input->sourceId);
        $messages = [];

        foreach ((array) ($input->data['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $postId = is_int($item['id'] ?? null) ? $item['id'] : 0;
            $document = $item['actor'] ?? null;

            try {
                if (! is_array($document) || ($document['id'] ?? null) !== ($item['guid'] ?? null)) {
                    throw new InvalidArgumentException('The follower’s document isn’t its own.');
                }

                $attributes = RemoteActorDocument::attributes($document);

                if (FetchedKeyOwners::keyIn($document, $attributes['key_id']) === null) {
                    throw new InvalidArgumentException('The follower’s key isn’t its own.');
                }
            } catch (InvalidArgumentException) {
                $messages[] = new ImportMessage('fediverse.follower-unreadable', __('WordPress kept a follower whose account Teme can’t read, so that follower wasn’t brought across.'), ['wordpress_post' => $postId]);

                continue;
            }

            $remote = RemoteActor::query()->where('uri', $attributes['uri'])->lockForUpdate()->first()
                ?? RemoteActor::query()->create([...$attributes, 'fetched_at' => now()]);

            $following = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, array_filter((array) ($item['following'] ?? []), is_numeric(...)))));
            $wanted = array_values(array_intersect_key($locals, array_flip($following)));
            $since = self::since($item['since'] ?? null);

            Follower::query()->where('remote_actor_id', $remote->id)->whereIn('local_actor_id', array_values($locals))
                ->whereNotIn('local_actor_id', $wanted)->whereNull('follow_uri')->delete();

            if ($wanted !== []) {
                Follower::query()->insertOrIgnore(array_map(fn (string $local): array => [
                    'local_actor_id' => $local, 'remote_actor_id' => $remote->id, 'follow_uri' => null, 'since' => $since,
                ], $wanted));
            }

            $this->entry($input, self::FOLLOWER, (string) $postId, 'fediverse_remote_actor', $remote->id);
        }

        return $messages;
    }

    /**
     * The followers WordPress no longer has: each one this run didn't read
     * stops following the imported accounts, unless it followed again
     * since, through Teme's own inbox.
     *
     * @return list<ImportMessage>
     */
    private function sweep(ImportFediverseInput $input): array
    {
        $locals = array_values(self::importedActors($input->sourceId));
        $gone = ImportMapEntry::query()->where('source_id', $input->sourceId)->where('object_type', self::FOLLOWER)
            ->where(fn ($query) => $query->whereNull('run_id')->orWhere('run_id', '!=', $input->runId))
            ->lockForUpdate()->get(['id', 'teme_id']);

        if ($gone->isEmpty()) {
            return [];
        }

        Follower::query()->whereIn('remote_actor_id', $gone->pluck('teme_id')->filter()->values()->all())
            ->whereIn('local_actor_id', $locals)->whereNull('follow_uri')->delete();
        ImportMapEntry::query()->whereIn('id', $gone->pluck('id')->all())->delete();

        return [];
    }

    /**
     * A batch of post or reply addresses, each kept for its item unless
     * Teme already knows the address.
     *
     * @return list<ImportMessage>
     */
    private function links(ImportFediverseInput $input, string $kind): array
    {
        /** @var list<array{uri: string, id: string}> $links */
        $links = (array) ($input->data['links'] ?? []);

        if ($links !== []) {
            FediverseObject::query()->insertOrIgnore(array_map(fn (array $link): array => [
                'uri' => $link['uri'],
                'kind' => $kind,
                'content_id' => $kind === FediverseObject::POST ? $link['id'] : null,
                'comment_id' => $kind === FediverseObject::REPLY ? $link['id'] : null,
            ], $links));
        }

        return [];
    }

    /**
     * The accounts this source's imports made, by WordPress user ID.
     *
     * @return array<int, string>
     */
    public static function importedActors(string $sourceId): array
    {
        $actors = [];

        foreach (DB::table('import_map')->where('source_id', $sourceId)->where('object_type', self::ACTOR)->whereNotNull('teme_id')->get(['object_id', 'teme_id']) as $row) {
            $actors[(int) $row->object_id] = (string) $row->teme_id;
        }

        return $actors;
    }

    /**
     * Writes or refreshes one map row for this run, its facts never a key.
     *
     * @param  array<string, mixed>  $facts
     */
    private function entry(ImportFediverseInput $input, string $type, string $id, string $temeType, string $temeId, array $facts = []): void
    {
        $entry = ImportMapEntry::query()->where('source_id', $input->sourceId)->where('object_type', $type)->where('object_id', $id)->lockForUpdate()->first();
        $outcome = $entry === null ? ImportOutcome::Created : ImportOutcome::Updated;
        $entry ??= new ImportMapEntry(['source_id' => $input->sourceId, 'object_type' => $type, 'object_id' => $id]);

        $entry->fill([
            'teme_type' => $temeType,
            'teme_id' => $temeId,
            'status' => $outcome->status(),
            'outcome' => $outcome,
            'source_hash' => null,
            'facts' => $facts,
            'run_id' => $input->runId,
            'imported_at' => now(),
        ])->save();
    }

    /**
     * Whether these two PEM halves are one RSA key pair.
     */
    private static function pairs(string $public, string $private): bool
    {
        if ($public === '' || $private === '') {
            return false;
        }

        try {
            $key = openssl_pkey_get_private($private);
            $details = $key === false ? false : openssl_pkey_get_details($key);
        } catch (Throwable) {
            return false;
        } finally {
            // Nothing openssl says about a key it read is kept for a log.
            while (openssl_error_string() !== false) {
                //
            }
        }

        $body = fn (string $pem): string => (string) preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && $body((string) $details['key']) === $body($public);
    }

    /**
     * When WordPress says the follow began, or now.
     */
    private static function since(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC') ?: CarbonImmutable::now();
        }

        return CarbonImmutable::now();
    }
}

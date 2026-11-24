<?php

/**
 * The users phase (I-340): each WordPress account becomes a Teme person
 * with their role and their WordPress hash, or the Teme person who already
 * has their email address; subscribers without published posts and unplaced roles stay behind (I-2998).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Users;

use App\Domain\Contents\Slugs;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportFailed;
use App\Domain\Import\Phases\ImportList;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportResult;
use App\Domain\Import\Phases\ListPhase;
use App\Domain\Import\Phases\PreparedObject;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Phases\Terms\WordPressSlugs;
use App\Domain\Import\Phases\Terms\WordPressText;
use App\Domain\Import\Planning\ImportChoices;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Users\ImportedHashes;
use App\Domain\Users\Operations\ImportUser;
use App\Domain\Users\Operations\Input\ImportUserInput;
use App\Domain\Users\Operations\Input\UpdateImportedUserInput;
use App\Domain\Users\Operations\UpdateImportedUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class UsersPhase implements ListPhase
{
    /** @var array<string, string> */
    private const array ARTICLED = [
        'administrator' => 'an Administrator',
        'editor' => 'an Editor',
        'author' => 'an Author',
        'contributor' => 'a Contributor',
    ];

    public function __construct(private ImportMap $map, private ImportedPeople $people) {}

    public function key(): string
    {
        return 'users';
    }

    public function order(): int
    {
        return 200;
    }

    public function label(): string
    {
        return 'People';
    }

    public function runsIn(ImportRunKind $kind): bool
    {
        return true;
    }

    public function lists(ImportContext $context): array
    {
        // A count of published posts decides whether a subscriber comes across (I-2998).
        return [new ImportList('users', [], 200)];
    }

    public function identify(ImportList $list, array $item, int|string $key): SourceRef
    {
        return SourceRef::of('user', (int) $item['id']);
    }

    public function import(SourceRef $ref, array $item, ImportContext $context, ?PreparedObject $prepared): ImportResult
    {
        // Their WordPress author address's last part, for the address check when Teme's differs.
        return $this->decide($ref, $item, $context)->withFacts(['nicename' => rawurldecode((string) ($item['nicename'] ?? ''))]);
    }

    /**
     * What becomes of one account: skipped, failed (thrown), matched,
     * created, or, met again, updated.
     *
     * @param  array<string, mixed>  $item
     */
    private function decide(SourceRef $ref, array $item, ImportContext $context): ImportResult
    {
        if ($context->readsFile()) {
            return $this->fromFile($ref, $item, $context);
        }

        $roles = array_values(array_map(strval(...), array_filter((array) ($item['roles'] ?? []), is_string(...))));
        $choices = $context->choice('roles', []);
        $place = WordPressRoles::place($roles, is_array($choices) ? $choices : []);
        $posts = (int) ($item['published_posts'] ?? 0);
        // A subscriber who has published writes here as a contributor, so their posts keep their name (I-2998).
        $promoted = $place['role'] === null && $place['unknown'] === [] && in_array('subscriber', $roles, true) && $posts > 0;

        if ($promoted) {
            $place['role'] = 'contributor';
        }

        $entry = $this->map->entry($context->sourceId, $ref);

        if ($entry?->teme_id !== null) {
            return $this->again($entry, $item, $roles, $place, $context, $promoted);
        }

        if ($place['role'] === null) {
            if ($place['unknown'] !== []) {
                $role = $place['unknown'][0];

                throw new ImportFailed(ImportMessage::error('user.role-unknown', "Their WordPress role {$role} isn’t one Teme knows, and this import’s choices don’t place it, so they weren’t imported. Give them one of WordPress’s own roles there and catch up, or invite them in People.", ['role' => $role]));
            }

            return ImportResult::skipped(self::leftBehind($roles, $place['placed']));
        }

        $email = Str::lower(trim((string) ($item['email'] ?? '')));

        if ($email === '') {
            throw new ImportFailed(ImportMessage::error('user.no-email', 'WordPress has no email address for them, so they weren’t imported. Give them one in WordPress, then catch up.'));
        }

        // Teme keeps every address lower-cased, so this is users_email_unique (I-1706).
        $holder = User::query()->where('email', $email)->value('id');

        if ($holder !== null) {
            if ($this->people->mappedFrom($context->sourceId, (string) $holder, $ref->id)) {
                throw new ImportFailed(ImportMessage::error('user.email-shared', 'Another WordPress account has the same email address, and Teme gives each address one person, so this one wasn’t imported.'));
            }

            return ImportResult::updated('user', (string) $holder)->withWarning(self::matched())->withFacts(['matched' => true, 'matched_in' => $context->runId]);
        }

        return $this->create($item, $email, (string) $place['role'], $place, $context, $promoted);
    }

    /**
     * What becomes of one of an export file's authors, as the plan page
     * chose: matched to a person here, credited to another, or added with
     * the role chosen and no password (docs/architecture/import.md). A file
     * has no roles or passwords, so a person met again stays as they are.
     *
     * @param  array<string, mixed>  $item
     */
    private function fromFile(SourceRef $ref, array $item, ImportContext $context): ImportResult
    {
        $entry = $this->map->entry($context->sourceId, $ref);

        if ($entry?->teme_id !== null) {
            if (! User::query()->whereKey($entry->teme_id)->exists()) {
                return ImportResult::skipped(new ImportMessage('user.deleted-here', 'They were imported before and have since been deleted in Teme, so they weren’t imported again.'));
            }

            return ImportResult::updated('user', (string) $entry->teme_id);
        }

        $login = (string) ($item['login'] ?? $item['nicename'] ?? '');
        $email = Str::lower(trim((string) ($item['email'] ?? '')));
        $authors = $context->choice('authors', []);
        $chosen = is_array($authors) && is_string($authors[$login] ?? null) ? $authors[$login] : 'new:author';
        [$how, $what] = array_pad(explode(':', $chosen, 2), 2, '');
        $holder = $email === '' ? null : User::query()->where('email', $email)->value('id');

        if ($how === 'match' || $how === 'to') {
            $person = User::query()->find($what);

            if ($person === null) {
                throw new ImportFailed(ImportMessage::error('user.chosen-gone', 'The person chosen for them on the plan is no longer on this site, so their account wasn’t imported. Choose again and import the file again.'));
            }

            $result = ImportResult::updated('user', (string) $person->id)->withFacts(['matched' => true, 'matched_in' => $context->runId]);

            return $how === 'match'
                ? $result->withWarning(self::matched())
                : $result->withWarning(new ImportMessage('user.credited', "Their posts and pages are credited to {$person->name}, as chosen on the plan.", ['to' => (string) $person->id]));
        }

        if ($email === '') {
            throw new ImportFailed(ImportMessage::error('user.no-email', 'The export file has no email address for them, so they weren’t imported. Give them one in WordPress and upload a newer file, or choose who they are here on the plan.'));
        }

        // Teme gives each address one person, so the person who has it is them.
        if ($holder !== null) {
            return ImportResult::updated('user', (string) $holder)->withWarning(self::matched())->withFacts(['matched' => true, 'matched_in' => $context->runId]);
        }

        $role = in_array($what, ImportChoices::FILE_ROLES, true) ? $what : 'author';

        return $this->create($item, $email, $role, ['role' => $role, 'placed' => [], 'unknown' => []], $context, false);
    }

    /**
     * A new person, with every way they differ from WordPress said.
     *
     * @param  array<string, mixed>  $item
     * @param  array{role: string|null, placed: array<string, string|null>, unknown: list<string>}  $place
     */
    private function create(array $item, string $email, string $role, array $place, ImportContext $context, bool $promoted): ImportResult
    {
        $name = self::name($item);
        $nicename = (string) ($item['nicename'] ?? '');

        ['slug' => $slug] = WordPressSlugs::fromWordPress($nicename);

        if ($slug === '') {
            ['slug' => $slug] = WordPressSlugs::fromWordPress(Slugs::fromText($name) ?: 'author');
        }

        $holder = User::query()->where('slug', $slug)->value('id');

        // Someone here has the address already: the person from WordPress takes the next free one, as WordPress's importer does.
        if ($holder !== null) {
            $slug = self::nextFree($slug);
        }

        $hash = ImportedHashes::usable((string) ($item['password_hash'] ?? ''));
        $bio = WordPressText::plain($item['description'] ?? null);

        $created = app(ImportUser::class)->execute(new ImportUserInput(
            name: $name,
            email: $email,
            slug: $slug,
            bio: $bio === null ? null : rtrim(mb_substr($bio, 0, 1000)),
            role: $role,
            passwordHash: $hash,
            registeredAt: self::registered($item['registered'] ?? null),
            actorId: $context->actorId(),
        ));

        $result = ImportResult::created('user', $created['userId'])->withFacts(['reset' => $hash === null]);

        foreach (self::standing($item, $slug, $hash, $place, $promoted) as $warning) {
            $result = $result->withWarning($warning);
        }

        return $result;
    }

    /**
     * An account an earlier run brought in: a matched person is left as
     * they are; a person this import made follows WordPress until they
     * first sign in here, a lower role included but never a higher one.
     * What the first run said of them is said again, since an object keeps
     * only its latest run's messages.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $roles
     * @param  array{role: string|null, placed: array<string, string|null>, unknown: list<string>}  $place
     */
    private function again(ImportMapEntry $entry, array $item, array $roles, array $place, ImportContext $context, bool $promoted): ImportResult
    {
        $userId = (string) $entry->teme_id;
        $person = User::query()->find($userId);

        if ($person === null) {
            return ImportResult::skipped(new ImportMessage('user.deleted-here', 'They were imported before and have since been deleted in Teme, so they weren’t imported again.'));
        }

        if (($entry->facts['matched'] ?? false) === true) {
            return ImportResult::updated('user', $userId)->withWarning(self::matched())->withFacts(['matched' => true]);
        }

        $before = $person->getRoleNames()->first();
        $hash = ImportedHashes::usable((string) ($item['password_hash'] ?? ''));
        $bio = WordPressText::plain($item['description'] ?? null);

        app(UpdateImportedUser::class)->execute(new UpdateImportedUserInput(
            userId: $userId,
            name: self::name($item),
            bio: $bio === null ? null : rtrim(mb_substr($bio, 0, 1000)),
            passwordHash: $hash,
            role: $place['role'],
            actorId: $context->actorId(),
        ));

        $fresh = User::query()->findOrFail($userId);
        $after = $fresh->getRoleNames()->first();
        $now = $roles === [] ? 'no role' : implode(', ', $roles);
        $result = ImportResult::updated('user', $userId)->withFacts(['reset' => $hash === null]);

        if ($person->suspended_at === null && $fresh->suspended_at !== null) {
            $result = $result->withWarning(new ImportMessage('user.suspended-by-wordpress', "WordPress gives them no role Teme imports now ({$now}), so Teme suspended their account here. Reactivate it in People if they still need to sign in.", ['roles' => $now]));
        } elseif ($fresh->suspended_at !== null && $place['role'] !== null) {
            // Nothing in an import lifts a suspension: a person decides that.
            $result = $result->withWarning(new ImportMessage('user.still-suspended', "WordPress gives them a role again ({$now}), but their account here stays suspended until someone reactivates it in People.", ['roles' => $now]));
        } elseif (is_string($after) && isset(self::ARTICLED[$after]) && $after !== $before) {
            $result = $result->withWarning(new ImportMessage('user.role-lowered', "Their role on WordPress ({$now}) is lower than the one they had here, so they’re ".self::ARTICLED[$after].' now.', ['roles' => $now, 'to' => $after]));
        } elseif (is_string($after) && isset(self::ARTICLED[$after]) && $place['role'] !== $after) {
            $result = $result->withWarning(new ImportMessage('user.role-differs', "Their role on WordPress ({$now}) isn’t the one they have here; Teme kept them as ".self::ARTICLED[$after].'. Change it in People if they need another.', ['roles' => $now, 'kept' => $after]));
        }

        foreach (self::standing($item, $person->slug, $hash, $place, $promoted) as $warning) {
            $result = $result->withWarning($warning);
        }

        return $result;
    }

    /**
     * What stays true of a person this import made, said on every run that
     * meets them: an author address Teme had to change, a password to
     * reset, a biography cut short, and the custom roles it placed or
     * passed over.
     *
     * @param  array<string, mixed>  $item
     * @param  array{role: string|null, placed: array<string, string|null>, unknown: list<string>}  $place
     *
     * @return list<ImportMessage>
     */
    private static function standing(array $item, string $slug, ?string $hash, array $place, bool $promoted): array
    {
        $said = [];

        if ($promoted) {
            $said[] = self::promoted((int) ($item['published_posts'] ?? 0));
        }

        $from = rawurldecode((string) ($item['nicename'] ?? ''));
        $bio = WordPressText::plain($item['description'] ?? null);

        if ($from !== '' && mb_strtolower($from) !== $slug) {
            $said[] = new ImportMessage('user.slug-changed', "Their author address on WordPress ended in {$from}, which Teme can’t give them here, so theirs ends in {$slug}.", ['from' => $from, 'to' => $slug]);
        }

        if ($hash === null) {
            $said[] = new ImportMessage('user.password-reset', 'Teme can’t check the password WordPress stored for them, so they’ll need to reset it before they first sign in.');
        }

        if ($bio !== null && mb_strlen($bio) > 1000) {
            $said[] = new ImportMessage('user.bio-shortened', 'Their biography was longer than Teme’s 1,000 characters, so it was cut short.');
        }

        foreach ($place['placed'] as $wordpress => $teme) {
            if ($teme !== null) {
                $said[] = new ImportMessage('user.custom-role', "Their WordPress role {$wordpress} isn’t one of Teme’s, so they’re ".self::ARTICLED[$teme].' here.', ['role' => $wordpress, 'to' => $teme]);
            }
        }

        $role = $place['role'];

        if ($role !== null) {
            foreach ($place['unknown'] as $wordpress) {
                $said[] = new ImportMessage('user.role-skipped', "Teme doesn’t know their WordPress role {$wordpress}, so they’re ".self::ARTICLED[$role].' here, from their other role.', ['role' => $wordpress, 'to' => $role]);
            }
        }

        return $said;
    }

    /**
     * The first of $slug, $slug-2, $slug-3 … that no account holds, the slug
     * kept as given (I-397), its suffix's place counted in characters.
     */
    private static function nextFree(string $slug): string
    {
        $base = rtrim(mb_substr($slug, 0, 180), '-');
        $prefix = $base.'-';
        $held = User::query()->where('slug', $base)->orWhere('slug', 'like', $prefix.'%')->pluck('slug')->all();

        if (! in_array($base, $held, true)) {
            return $base;
        }

        $highest = 1;

        foreach ($held as $taken) {
            $suffix = mb_substr((string) $taken, mb_strlen($prefix));

            if (str_starts_with((string) $taken, $prefix) && preg_match('/\A[1-9][0-9]{0,8}\z/', $suffix) === 1) {
                $highest = max($highest, (int) $suffix);
            }
        }

        return $prefix.($highest + 1);
    }

    private static function matched(): ImportMessage
    {
        return new ImportMessage('user.matched', 'Teme already had a person with this email address, so this account became theirs. Their Teme password and role stay as they were.');
    }

    private static function promoted(int $posts): ImportMessage
    {
        $words = $posts === 1 ? 'A subscriber with 1 published post: brought across as a contributor, so their post keeps their name.' : "A subscriber with {$posts} published posts: brought across as a contributor, so their posts keep their name.";

        return new ImportMessage('user.subscriber-with-posts', $words.' Change their role in People if they should have more, or less.', ['published' => $posts]);
    }

    /**
     * Why a person whose roles give no Teme role stays behind.
     *
     * @param  list<string>  $roles
     * @param  array<string, string|null>  $placed
     */
    private static function leftBehind(array $roles, array $placed): ImportMessage
    {
        if (in_array('subscriber', $roles, true)) {
            return new ImportMessage('user.subscriber', 'A subscriber: Teme has no accounts for readers yet, so they weren’t imported.');
        }

        if ($placed !== []) {
            $role = (string) array_key_first($placed);

            return new ImportMessage('user.role-left', "The import’s choices leave their WordPress role {$role} behind, so they weren’t imported.", ['role' => $role]);
        }

        return new ImportMessage('user.no-role', 'They have no role on WordPress, so they weren’t imported.');
    }

    /**
     * WordPress's display name, its escaping undone once, else their first
     * and last names, else their nicename; at most 255 characters.
     *
     * @param  array<string, mixed>  $item
     */
    private static function name(array $item): string
    {
        $name = WordPressText::decoded($item['display_name'] ?? '');

        if ($name === '') {
            $name = trim(WordPressText::decoded($item['first_name'] ?? '').' '.WordPressText::decoded($item['last_name'] ?? ''));
        }

        if ($name === '') {
            $name = WordPressText::decoded(rawurldecode((string) ($item['nicename'] ?? '')));
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * When they joined: user_registered, which WordPress keeps in UTC; null
     * for a missing or zero date.
     */
    private static function registered(mixed $value): ?CarbonImmutable
    {
        $text = (string) $value;

        if (preg_match('/^(19[7-9][0-9]|2[0-9]{3})-[01][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/', $text) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $text, 'UTC') ?: null;
    }
}

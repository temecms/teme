<?php

/**
 * The choices an import needs (I-227): where each WordPress menu goes, what
 * each page template becomes, the role for each custom role, who owns items
 * whose author has gone, which outside sites' images come into the library,
 * and the pause between requests. Offered from a site's analysis and Teme's
 * own lists, and checked against them.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Planning;

use App\Domain\Import\File\FileAddresses;
use App\Domain\Import\Operations\StartImportRun;
use App\Domain\Import\Phases\Users\WordPressRoles;
use App\Domain\Import\Queries\ImportFiles;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Users\Queries\AuthorOptions;
use App\Domain\Users\Queries\RoleOptions;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class ImportChoices
{
    /** The pauses between requests to the old site, in milliseconds, with their words. */
    public const array PAUSES = [0 => 'No pause', 500 => 'Half a second', 1000 => 'One second', 2000 => 'Two seconds', 5000 => 'Five seconds'];

    private const string CHANGED = 'Analyze the site again: its report has changed.';

    /** A custom role's choice that leaves its people behind, as the users phase reads it. */
    public const string NONE = 'none';

    /** The roles a person added from an export file can be given, in the plan page's order. */
    public const array FILE_ROLES = ['author', 'contributor', 'editor'];

    /** What becomes of an export file's media files: downloaded from the old site, kept there as links, or brought after the articles (#716). */
    public const array MEDIA = ['download', 'links', 'later'];

    /**
     * What becomes of a live site's media files: downloaded during the
     * import, or brought after the articles (#716).
     *
     * @since 1.5.0
     */
    public const array LIVE_MEDIA = ['download', 'later'];

    /** An address's base: words of letters, digits, hyphens and underscores, between slashes. */
    private const string BASE = '/^[a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*$/';

    public function __construct(private RoleOptions $roles, private AuthorOptions $authors, private ImportFiles $files, private ContentTypeRegistry $types) {}

    /**
     * Returns what the choices step draws: each choice's options, and its
     * default, for this site's analysis and the person choosing.
     *
     * @return array{roles: list<array{key: string, value: string}>, roleOptions: list<array{value: string, label: string}>, owner: string, people: list<array{value: string, label: string}>, outsideHosts: list<array{host: string, count: int}>, pause: int, media: string, pauses: list<array{value: int, label: string}>}
     */
    public function form(SiteAnalysis $analysis, string $personId): array
    {
        $people = $this->people();

        return [
            'roles' => array_map(fn (string $role, array $capabilities): array => ['key' => $role, 'value' => self::suggestedRole($capabilities) ?? self::NONE], array_keys($analysis->customRoles()), $analysis->customRoles()),
            'roleOptions' => [...$this->roles->all(), ['value' => self::NONE, 'label' => 'Leave behind']],
            'owner' => array_key_exists($personId, $people) ? $personId : (string) (array_key_first($people) ?? ''),
            'people' => self::options($people),
            'outsideHosts' => array_map(fn (string $host, int $count): array => ['host' => $host, 'count' => $count], array_keys($analysis->outsideHosts()), $analysis->outsideHosts()),
            'pause' => StartImportRun::OPTIONS['pause_ms'],
            'media' => 'download',
            'pauses' => array_map(fn (int $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::PAUSES), self::PAUSES),
        ];
    }

    /**
     * Returns the run's choices and its pause from what the form sent, each
     * checked against what the analysis lists and Teme offers. Where menus
     * sit and which template a page uses are the new theme's, so neither is
     * asked, and any sent, as an earlier release's form did, is ignored.
     *
     * @param  array<string, mixed>  $input
     *
     * @return array{choices: array{roles: array<string, string>, owner: string, outside_images: list<string>, media: string}, pauseMs: int}
     *
     * @throws ValidationException under each field that doesn't fit
     */
    public function validated(array $input, SiteAnalysis $analysis): array
    {
        $errors = [];

        [$roles, $errors] = self::mapped($input, 'roles', array_keys($analysis->customRoles()), [...array_column($this->roles->all(), 'value'), self::NONE], false, 'Choose one of Teme’s roles.', $errors);

        $owner = is_scalar($input['owner'] ?? null) ? (string) $input['owner'] : '';

        if (! array_key_exists($owner, $this->people())) {
            $errors['owner'] = 'Choose someone who can write on Teme.';
        }

        $hosts = $input['outside_images'] ?? [];
        $hosts = is_array($hosts) ? array_values(array_unique(array_map(fn (mixed $host): string => is_scalar($host) ? strtolower((string) $host) : '', $hosts))) : null;

        if ($hosts === null || array_diff($hosts, array_keys($analysis->outsideHosts())) !== []) {
            $errors['outside_images'] = 'Tick only the sites listed.';
        }

        $media = $input['media'] ?? 'download';

        if (! in_array($media, self::LIVE_MEDIA, true)) {
            $errors['media'] = 'Choose how the media files come across.';
        }

        $pause = $input['pause'] ?? (string) StartImportRun::OPTIONS['pause_ms'];

        if (! is_numeric($pause) || ! array_key_exists((int) $pause, self::PAUSES)) {
            $errors['pause'] = 'Choose one of the pauses listed.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'choices' => [
                // Every role was checked above, so none is empty here.
                'roles' => array_map(fn (?string $role): string => (string) $role, $roles),
                'owner' => $owner,
                'outside_images' => $hosts ?? [],
                'media' => (string) $media,
            ],
            'pauseMs' => (int) $pause,
        ];
    }

    /**
     * Returns what the plan of an export file draws: the companion's choices
     * a file has, and the file's own, each with its default: every author
     * matched by email, then by login, to a person here, else added as an
     * author; media downloaded; each custom type to the Teme type for it.
     * The choices of the site's last import, $last, come first, so a newer
     * file of the same site is imported as the one before it.
     *
     * @param  array<string, mixed>  $last
     *
     * @return array{owner: string, people: list<array{value: string, label: string}>, pause: int, authors: list<array{login: string, name: string, email: string, items: int, matched: string|null, value: string}>, fileRoles: list<string>, types: list<array{key: string, label: string, count: int, type: string|null, typeLabel: string|null, value: string}>, media: string, categoryBase: string, tagBase: string, categoryExample: string, tagExample: string, polylang: bool, drafts: bool, comments: bool, postCount: int, pageCount: int, mediaCount: int}
     */
    public function fileForm(SiteAnalysis $analysis, string $fileId, string $personId, array $last = []): array
    {
        $companion = $this->form($analysis, $personId);
        $authors = [];

        foreach ($this->files->authors($fileId) as $author) {
            $matched = $this->match($author['email'], $author['login']);
            $before = is_array($last['authors'] ?? null) && is_string($last['authors'][$author['login']] ?? null) ? $last['authors'][$author['login']] : null;
            $authors[] = [
                'login' => $author['login'],
                'name' => $author['name'],
                'email' => $author['email'],
                'items' => $author['items'],
                'matched' => $matched,
                'value' => $before ?? ($matched === null ? 'new:author' : 'match:'.$matched),
            ];
        }

        $plan = $this->files->plan($fileId);
        $bases = $plan === null ? FileAddresses::bases($fileId) : ['category_base' => $plan['category_base'], 'tag_base' => $plan['tag_base']];
        $examples = $plan === null ? FileAddresses::examples($fileId) : ['categoryExample' => $plan['category_example'], 'tagExample' => $plan['tag_example']];
        $kept = fn (string $key, mixed $default): mixed => array_key_exists($key, $last) && get_debug_type($last[$key]) === get_debug_type($default) ? $last[$key] : $default;
        $types = is_array($last['types'] ?? null) ? $last['types'] : [];

        return [
            'owner' => $companion['owner'],
            'people' => $companion['people'],
            'pause' => $companion['pause'],
            'authors' => $authors,
            'fileRoles' => self::FILE_ROLES,
            'types' => array_map(fn (array $type): array => [...$type, 'value' => array_key_exists($type['key'], $types) ? (string) $types[$type['key']] : (string) $type['type']], $this->customTypes($analysis)),
            'media' => (string) $kept('media', 'download'),
            'categoryBase' => (string) $kept('category_base', $bases['category_base']),
            'tagBase' => (string) $kept('tag_base', $bases['tag_base']),
            // A real category's and tag's slug, for the example under each box.
            ...$examples,
            // Polylang's languages in the file: everything comes across in the default language, and the plan says so.
            'polylang' => ($analysis->polylangCount() ?? 0) > 0,
            'drafts' => (bool) $kept('drafts', true),
            'comments' => (bool) $kept('comments', true),
            ...$this->files->counts($fileId),
        ];
    }

    /**
     * Returns an export file's run's choices and its pause from what the form
     * sent, each checked against the file and what Teme offers.
     *
     * @param  array<string, mixed>  $input
     *
     * @return array{choices: array{roles: array<string, string>, owner: string, outside_images: list<string>, authors: array<string, string>, media: string, category_base: string, tag_base: string, types: array<string, string|null>, drafts: bool, comments: bool}, pauseMs: int}
     *
     * @throws ValidationException under each field that doesn't fit
     */
    public function validatedFile(array $input, SiteAnalysis $analysis, string $fileId): array
    {
        $errors = [];

        try {
            // A file's media choice has links beside the live ones, so it is checked below.
            $companion = $this->validated([...$input, 'roles' => [], 'outside_images' => [], 'media' => 'download'], $analysis);
        } catch (ValidationException $refused) {
            $errors = array_map(fn (array $words): string => (string) $words[0], $refused->errors());
            $companion = null;
        }

        $sent = is_array($input['authors'] ?? null) ? $input['authors'] : [];
        $people = array_map(strval(...), array_column($this->authors->all(), 'value'));
        $authors = [];

        foreach ($this->files->authors($fileId) as $author) {
            $value = is_string($sent[$author['login']] ?? null) ? $sent[$author['login']] : '';
            [$how, $what] = array_pad(explode(':', $value, 2), 2, '');
            $fits = match ($how) {
                'match' => ctype_digit($what) && User::query()->whereKey($what)->exists(),
                'to' => in_array($what, $people, true),
                'new' => in_array($what, self::FILE_ROLES, true),
                default => false,
            };

            if (! $fits) {
                $errors["authors.{$author['login']}"] = 'Choose who this author is here.';
            }

            $authors[$author['login']] = $value;
        }

        $media = $input['media'] ?? 'download';

        if (! in_array($media, self::MEDIA, true)) {
            $errors['media'] = 'Choose how the media files come across.';
        }

        $bases = [];

        foreach (['category_base', 'tag_base'] as $field) {
            // Empty is no prefix: the pages sit straight after the site's address, as Yoast's "Remove category prefix" makes them.
            $base = is_string($input[$field] ?? null) ? strtolower(trim($input[$field], '/ ')) : '';

            if ($base !== '' && (strlen($base) > 100 || preg_match(self::BASE, $base) !== 1)) {
                $errors[$field] = 'Use lower-case letters, numbers and hyphens, or choose No prefix.';
            }

            $bases[$field] = $base;
        }

        $sentTypes = is_array($input['types'] ?? null) ? $input['types'] : [];
        $types = [];

        foreach ($this->customTypes($analysis) as $type) {
            $value = is_scalar($sentTypes[$type['key']] ?? null) ? (string) $sentTypes[$type['key']] : '';

            if ($value !== '' && $value !== $type['type']) {
                $errors["types.{$type['key']}"] = 'Choose the Teme type for these, or leave them behind.';
            }

            $types[$type['key']] = $value === '' ? null : $value;
        }

        if ($errors !== [] || $companion === null) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'choices' => [
                ...$companion['choices'],
                'authors' => $authors,
                'media' => (string) $media,
                'category_base' => $bases['category_base'],
                'tag_base' => $bases['tag_base'],
                'types' => $types,
                'drafts' => filter_var($input['drafts'] ?? true, FILTER_VALIDATE_BOOL),
                'comments' => filter_var($input['comments'] ?? true, FILTER_VALIDATE_BOOL),
            ],
            'pauseMs' => $companion['pauseMs'],
        ];
    }

    /**
     * Returns the ID of the person here an export file's author is, by their
     * email first, then by their login as a person's address; null when none.
     */
    public function match(string $email, string $login): ?string
    {
        $email = strtolower(trim($email));
        $id = $email === '' ? null : User::query()->where('email', $email)->value('id');
        $id ??= $login === '' ? null : User::query()->where('slug', strtolower($login))->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The custom types the file holds, each with the Teme type that brings
     * them, the same rule as the live import's, or none.
     *
     * @return list<array{key: string, label: string, count: int, type: string|null, typeLabel: string|null}>
     */
    public function customTypes(SiteAnalysis $analysis): array
    {
        $types = [];

        foreach ($analysis->toArray()['stays'] as $stay) {
            if (! str_starts_with($stay['key'], 'type.') || $stay['wordpressKey'] === null) {
                continue;
            }

            $teme = null;

            foreach ($this->types->all() as $type) {
                if ($type->listed && $type->wordpress === $stay['wordpressKey']) {
                    $teme = $type;

                    break;
                }
            }

            $types[] = ['key' => $stay['wordpressKey'], 'label' => $stay['label'], 'count' => $stay['count'], 'type' => $teme?->key, 'typeLabel' => $teme?->plural];
        }

        return $types;
    }

    /**
     * The nearest Teme role at or below a role with these WordPress
     * capabilities, by the users phase's own ladder: the highest whose
     * needs it fully holds; null when it can't even write, as a reader can't.
     *
     * @param  list<string>  $capabilities
     */
    public static function suggestedRole(array $capabilities): ?string
    {
        return WordPressRoles::nearest($capabilities);
    }

    /**
     * One map of choices: every key the analysis lists, each to one of the
     * allowed values, or to nothing where nothing is allowed; any key the
     * analysis doesn't list refuses the whole map.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @param  list<string>  $allowed
     * @param  array<string, string>  $errors
     *
     * @return array{0: array<string, string|null>, 1: array<string, string>}
     */
    private static function mapped(array $input, string $field, array $keys, array $allowed, bool $nothingAllowed, string $words, array $errors): array
    {
        $sent = is_array($input[$field] ?? null) ? $input[$field] : [];
        $kept = [];

        foreach (array_keys($sent) as $key) {
            if (! in_array((string) $key, $keys, true)) {
                $errors[$field] = self::CHANGED;
            }
        }

        foreach ($keys as $key) {
            $value = is_scalar($sent[$key] ?? null) ? (string) $sent[$key] : '';

            if (($value === '' && ! $nothingAllowed) || ($value !== '' && ! in_array($value, $allowed, true))) {
                $errors["{$field}.{$key}"] = $words;
            }

            $kept[$key] = $value === '' ? null : $value;
        }

        return [$kept, $errors];
    }

    /**
     * Who may own content: ID to name.
     *
     * @return array<int|string, string>
     */
    private function people(): array
    {
        $people = [];

        foreach ($this->authors->all() as $person) {
            $people[(string) $person['value']] = $person['label'];
        }

        return $people;
    }

    /**
     * @param  array<string|int, string>  $map
     *
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $map): array
    {
        return array_map(fn (string|int $value, string $label): array => ['value' => (string) $value, 'label' => $label], array_keys($map), $map);
    }
}

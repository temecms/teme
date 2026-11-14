<?php

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Comments\Discussion;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;

/**
 * A content type as the file and the table hold it: labels, admin address,
 * features and comments, before the registries check it (I-411).
 */
final readonly class TypeDefinition
{
    public const string SHARED = 'shared';

    public const string OWN = 'own';

    /** @var list<string> */
    private const array KNOWN = [
        'key', 'singular', 'plural', 'segment', 'icon', 'single', 'public', 'archive',
        'searchable', 'taxonomies', 'supports', 'comments', 'pattern', 'permissions',
    ];

    /**
     * @param  list<string>  $taxonomies
     * @param  list<string>  $supports
     */
    public function __construct(
        public string $key,
        public string $singular,
        public string $plural,
        public string $segment,
        public string $icon = 'file',
        public bool $public = true,
        public bool $archive = false,
        public bool $searchable = true,
        public array $taxonomies = [],
        public array $supports = [],
        public ?string $comments = null,
        public ?string $pattern = null,
        public bool $single = false,
        public string $permissions = self::SHARED,
    ) {}

    /**
     * Reads a definition from a file entry or a table row. Checks shape only.
     *
     * @param  array<mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $problems = [];

        if ($raw !== [] && array_is_list($raw)) {
            throw new InvalidTypeDefinition(['A type must be an object of named values.']);
        }

        foreach (['key', 'singular', 'plural', 'segment'] as $field) {
            if (! array_key_exists($field, $raw)) {
                $problems[] = "[{$field}] is missing.";
            } elseif (! is_string($raw[$field])) {
                $problems[] = "[{$field}] must be text.";
            }
        }

        if (array_key_exists('icon', $raw) && ! is_string($raw['icon'])) {
            $problems[] = '[icon] must be text.';
        }

        if (array_key_exists('single', $raw) && ! is_bool($raw['single'])) {
            $problems[] = '[single] must be true or false.';
        }

        foreach (['public', 'archive', 'searchable'] as $field) {
            if (array_key_exists($field, $raw) && ! is_bool($raw[$field])) {
                $problems[] = "[{$field}] must be true or false.";
            }
        }

        foreach (['taxonomies', 'supports'] as $field) {
            if (array_key_exists($field, $raw) && ! self::isListOfText($raw[$field])) {
                $problems[] = "[{$field}] must be a list of text.";
            }
        }

        if (array_key_exists('comments', $raw)) {
            $comments = $raw['comments'];
            $commentsOk = $comments === null
                || (is_string($comments) && in_array($comments, ['open', 'closed'], true));

            if (! $commentsOk) {
                $problems[] = '[comments] must be open, closed or null.';
            }
        }

        if (array_key_exists('pattern', $raw) && ! (is_string($raw['pattern']) || $raw['pattern'] === null)) {
            $problems[] = '[pattern] must be text or null.';
        }

        if (array_key_exists('permissions', $raw) && ! in_array($raw['permissions'], [self::SHARED, self::OWN], true)) {
            $problems[] = '[permissions] must be shared or own.';
        }

        foreach (array_keys($raw) as $field) {
            if (! in_array($field, self::KNOWN, true)) {
                $problems[] = "[{$field}] isn’t part of a version 1 type.";
            }
        }

        if ($problems !== []) {
            throw new InvalidTypeDefinition($problems);
        }

        /** @var array{key: string, singular: string, plural: string, segment: string, icon?: string, single?: bool, public?: bool, archive?: bool, searchable?: bool, taxonomies?: list<string>, supports?: list<string>, comments?: ?string, pattern?: ?string, permissions?: string} $raw */
        return new self(
            key: $raw['key'],
            singular: $raw['singular'],
            plural: $raw['plural'],
            segment: $raw['segment'],
            icon: $raw['icon'] ?? 'file',
            public: $raw['public'] ?? true,
            archive: $raw['archive'] ?? false,
            searchable: $raw['searchable'] ?? true,
            taxonomies: $raw['taxonomies'] ?? [],
            supports: $raw['supports'] ?? [],
            comments: $raw['comments'] ?? null,
            pattern: $raw['pattern'] ?? null,
            single: $raw['single'] ?? false,
            permissions: $raw['permissions'] ?? self::SHARED,
        );
    }

    /**
     * Reads a stored row as far as it can (I-3302): each field of the right
     * shape, the default for the rest, unknown keys dropped. For a row
     * nobody can read, so the screen can show it and a change can replace it.
     *
     * @param  array<mixed>  $raw
     */
    public static function lenient(array $raw): self
    {
        if ($raw !== [] && array_is_list($raw)) {
            $raw = [];
        }

        $text = static function (string $field, string $fallback) use ($raw): string {
            $value = $raw[$field] ?? null;

            return is_string($value) ? $value : $fallback;
        };

        $flag = static function (string $field, bool $fallback) use ($raw): bool {
            $value = $raw[$field] ?? null;

            return is_bool($value) ? $value : $fallback;
        };

        $list = static function (string $field) use ($raw): array {
            $value = $raw[$field] ?? null;

            return self::isListOfText($value) ? $value : [];
        };

        $comments = $raw['comments'] ?? null;
        $permissions = $raw['permissions'] ?? null;

        return new self(
            key: $text('key', ''),
            singular: $text('singular', ''),
            plural: $text('plural', ''),
            segment: $text('segment', ''),
            icon: $text('icon', 'file'),
            public: $flag('public', true),
            archive: $flag('archive', false),
            searchable: $flag('searchable', true),
            taxonomies: $list('taxonomies'),
            supports: $list('supports'),
            comments: is_string($comments) && in_array($comments, ['open', 'closed'], true) ? $comments : null,
            pattern: is_string($raw['pattern'] ?? null) ? $raw['pattern'] : null,
            single: $flag('single', false),
            permissions: is_string($permissions) && in_array($permissions, [self::SHARED, self::OWN], true) ? $permissions : self::SHARED,
        );
    }

    /**
     * The definition in the file's key order.
     *
     * @return array{key: string, singular: string, plural: string, segment: string, icon: string, single: bool, public: bool, archive: bool, searchable: bool, taxonomies: list<string>, supports: list<string>, comments: ?string, pattern: ?string, permissions: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'singular' => $this->singular,
            'plural' => $this->plural,
            'segment' => $this->segment,
            'icon' => $this->icon,
            'single' => $this->single,
            'public' => $this->public,
            'archive' => $this->archive,
            'searchable' => $this->searchable,
            'taxonomies' => $this->taxonomies,
            'supports' => $this->supports,
            'comments' => $this->comments,
            'pattern' => $this->pattern,
            'permissions' => $this->permissions,
        ];
    }

    /**
     * A listed, stored content type with the global abilities.
     */
    public function toContentType(): ContentType
    {
        return new ContentType(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            public: $this->public,
            archive: $this->archive,
            searchable: $this->searchable,
            taxonomies: $this->taxonomies,
            supports: $this->supports,
            listed: true,
            icon: $this->icon,
            single: $this->single,
            pattern: $this->pattern,
            comments: $this->comments === null ? null : Discussion::from($this->comments),
            stored: true,
            abilities: $this->permissions === self::OWN ? TypePermissions::names($this->key) : [],
        );
    }

    /**
     * A public type with no pattern gets /{segment}/{slug}/; one that isn't
     * public gets none.
     */
    public function settled(): self
    {
        if (! $this->public) {
            return $this->withPattern(null);
        }

        return $this->pattern === null
            ? $this->withPattern('/'.$this->segment.'/{slug}/')
            : $this;
    }

    public function withPattern(?string $pattern): self
    {
        return new self(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            icon: $this->icon,
            public: $this->public,
            archive: $this->archive,
            searchable: $this->searchable,
            taxonomies: $this->taxonomies,
            supports: $this->supports,
            comments: $this->comments,
            pattern: $pattern,
            single: $this->single,
            permissions: $this->permissions,
        );
    }

    /**
     * @param  list<string>  $taxonomies
     */
    public function withTaxonomies(array $taxonomies): self
    {
        return new self(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            icon: $this->icon,
            public: $this->public,
            archive: $this->archive,
            searchable: $this->searchable,
            taxonomies: $taxonomies,
            supports: $this->supports,
            comments: $this->comments,
            pattern: $this->pattern,
            single: $this->single,
            permissions: $this->permissions,
        );
    }

    private static function isListOfText(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }
}

<?php

/**
 * A taxonomy as the file and the table hold it: labels, admin address,
 * arrangement, address base and the code types that use it, before the
 * registries check it (I-414).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;

final readonly class TaxonomyDefinition
{
    /** @var list<string> */
    private const array KNOWN = [
        'key', 'singular', 'plural', 'segment', 'icon', 'hierarchical', 'public', 'base', 'code_types',
    ];

    /**
     * @param  list<string>  $codeTypes  the listed types code declares that use this taxonomy
     */
    public function __construct(
        public string $key,
        public string $singular,
        public string $plural,
        public string $segment,
        public string $icon = 'tag',
        public bool $hierarchical = false,
        public bool $public = true,
        public ?string $base = null,
        public array $codeTypes = [],
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
            throw new InvalidTaxonomyDefinition(['A taxonomy must be an object of named values.']);
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

        foreach (['hierarchical', 'public'] as $field) {
            if (array_key_exists($field, $raw) && ! is_bool($raw[$field])) {
                $problems[] = "[{$field}] must be true or false.";
            }
        }

        if (array_key_exists('base', $raw) && ! (is_string($raw['base']) || $raw['base'] === null)) {
            $problems[] = '[base] must be text or null.';
        }

        if (array_key_exists('code_types', $raw) && ! self::isListOfText($raw['code_types'])) {
            $problems[] = '[code_types] must be a list of text.';
        }

        foreach (array_keys($raw) as $field) {
            if (! in_array($field, self::KNOWN, true)) {
                $problems[] = "[{$field}] isn’t part of a version 1 taxonomy.";
            }
        }

        if ($problems !== []) {
            throw new InvalidTaxonomyDefinition($problems);
        }

        /** @var array{key: string, singular: string, plural: string, segment: string, icon?: string, hierarchical?: bool, public?: bool, base?: ?string, code_types?: list<string>} $raw */
        return new self(
            key: $raw['key'],
            singular: $raw['singular'],
            plural: $raw['plural'],
            segment: $raw['segment'],
            icon: $raw['icon'] ?? 'tag',
            hierarchical: $raw['hierarchical'] ?? false,
            public: $raw['public'] ?? true,
            base: $raw['base'] ?? null,
            codeTypes: $raw['code_types'] ?? [],
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

        $types = $raw['code_types'] ?? null;

        return new self(
            key: $text('key', ''),
            singular: $text('singular', ''),
            plural: $text('plural', ''),
            segment: $text('segment', ''),
            icon: $text('icon', 'tag'),
            hierarchical: $flag('hierarchical', false),
            public: $flag('public', true),
            base: is_string($raw['base'] ?? null) ? $raw['base'] : null,
            codeTypes: self::isListOfText($types) ? $types : [],
        );
    }

    /**
     * The definition in the file's key order.
     *
     * @return array{key: string, singular: string, plural: string, segment: string, icon: string, hierarchical: bool, public: bool, base: ?string, code_types: list<string>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'singular' => $this->singular,
            'plural' => $this->plural,
            'segment' => $this->segment,
            'icon' => $this->icon,
            'hierarchical' => $this->hierarchical,
            'public' => $this->public,
            'base' => $this->base,
            'code_types' => $this->codeTypes,
        ];
    }

    /**
     * A stored taxonomy whose terms the terms ability manages.
     */
    public function toTaxonomy(): Taxonomy
    {
        return new Taxonomy(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            hierarchical: $this->hierarchical,
            public: $this->public,
            ability: 'terms.manage',
            icon: $this->icon,
            base: $this->base,
            wordpress: null,
            stored: true,
        );
    }

    /**
     * A public taxonomy with no base gets its key as base; one that isn't
     * public gets none.
     */
    public function settled(): self
    {
        if (! $this->public) {
            return $this->withBase(null);
        }

        return $this->base === null ? $this->withBase($this->key) : $this;
    }

    public function withBase(?string $base): self
    {
        return new self(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            icon: $this->icon,
            hierarchical: $this->hierarchical,
            public: $this->public,
            base: $base,
            codeTypes: $this->codeTypes,
        );
    }

    /**
     * @param  list<string>  $codeTypes
     */
    public function withCodeTypes(array $codeTypes): self
    {
        return new self(
            key: $this->key,
            singular: $this->singular,
            plural: $this->plural,
            segment: $this->segment,
            icon: $this->icon,
            hierarchical: $this->hierarchical,
            public: $this->public,
            base: $this->base,
            codeTypes: $codeTypes,
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

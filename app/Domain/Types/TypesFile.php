<?php

/**
 * The teme-types.json file, version 1: the site's own content types,
 * taxonomies and fields as a portable list, with each own type's grants
 * (I-411, I-414).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Types;

use App\Domain\Fields\Stored\FieldsFile;
use App\Domain\Types\Exceptions\InvalidTaxonomyDefinition;
use App\Domain\Types\Exceptions\InvalidTypeDefinition;
use Illuminate\Validation\ValidationException;

final class TypesFile
{
    /** @var list<string> */
    private const array KNOWN = ['format', 'version', 'types', 'fields', 'taxonomies', 'grants'];

    /**
     * The site's own types, taxonomies and fields as a version 1 file, read from the
     * tables. A row that doesn't parse is left out; Status already names it.
     * So is one a plugin brought, which comes with the plugin.
     *
     * @return array{format: string, version: int, types: list<array<string, mixed>>, fields: list<array<string, mixed>>, taxonomies: list<mixed>, grants: list<array{type: string, role: string, abilities: list<string>}>}
     */
    public static function export(): array
    {
        $types = [];
        $owned = TypeOwners::of('types');

        foreach (app(StoredTypes::class)->definitions() as $raw) {
            if (isset($owned[$raw['key']])) {
                continue;
            }

            try {
                $types[] = TypeDefinition::fromArray($raw)->toArray();
            } catch (InvalidTypeDefinition) {
                // Status already lists why this row can't be used.
            }
        }

        $taxonomies = [];
        $owned = TypeOwners::of('taxonomies');

        foreach (app(StoredTaxonomies::class)->definitions() as $raw) {
            if (isset($owned[$raw['key'] ?? null])) {
                continue;
            }

            try {
                $taxonomies[] = TaxonomyDefinition::fromArray($raw)->toArray();
            } catch (InvalidTaxonomyDefinition) {
                // Status already lists why this row can't be used.
            }
        }

        $grants = [];

        foreach ($types as $type) {
            if ($type['permissions'] !== TypeDefinition::OWN) {
                continue;
            }

            foreach (TypePermissions::held(TypePermissions::names($type['key'])) as $role => $actions) {
                $grants[] = ['type' => $type['key'], 'role' => $role, 'abilities' => $actions];
            }
        }

        return [
            'format' => 'teme-types',
            'version' => 1,
            'types' => $types,
            'fields' => FieldsFile::export(),
            'taxonomies' => $taxonomies,
            'grants' => $grants,
        ];
    }

    /**
     * The version 1 file as `teme:types:export --output` writes it:
     * pretty-printed, slashes and Unicode unescaped, with a closing newline.
     */
    public static function json(): string
    {
        return json_encode(self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The types a file names, after its shape is checked. Collects every
     * problem into one ValidationException.
     *
     * @param  array<mixed>  $file
     *
     * @return list<TypeDefinition>
     *
     * @throws ValidationException
     */
    public static function definitions(array $file): array
    {
        $problems = [];

        if (($file['format'] ?? null) !== 'teme-types') {
            $problems['file'][] = __('This isn’t a teme-types.json file: it needs "format": "teme-types".');
        }

        if (! array_key_exists('version', $file)) {
            $problems['file'][] = __('This file doesn’t say its version: it needs "version": 1.');
        } elseif ($file['version'] !== 1) {
            $problems['file'][] = __('This file is version :version; this version of Teme reads version 1.', [
                'version' => is_scalar($file['version']) ? (string) $file['version'] : get_debug_type($file['version']),
            ]);
        }

        foreach (array_keys($file) as $key) {
            if (! in_array($key, self::KNOWN, true)) {
                $problems['file'][] = __('[:key] isn’t part of a version 1 file.', ['key' => $key]);
            }
        }

        foreach (['taxonomies', 'fields'] as $key) {
            if (array_key_exists($key, $file) && (! is_array($file[$key]) || ! array_is_list($file[$key]))) {
                $problems[$key][] = __('[:key] must be a list.', ['key' => $key]);
            }
        }

        $types = $file['types'] ?? null;

        if (! is_array($types) || ! array_is_list($types)) {
            $problems['types'][] = __('[types] must be a list of types.');
            self::refuse($problems);

            return [];
        }

        $definitions = [];
        $keys = [];
        $segments = [];

        foreach ($types as $i => $raw) {
            if (! is_array($raw)) {
                $problems["types.{$i}"][] = __('Type :n: A type must be an object of named values.', ['n' => (string) ($i + 1)]);

                continue;
            }

            try {
                $definition = TypeDefinition::fromArray($raw);
            } catch (InvalidTypeDefinition $failure) {
                $name = is_string($raw['key'] ?? null) ? $raw['key'] : 'Type '.($i + 1);
                $problems["types.{$i}"][] = __(':name: :problems', [
                    'name' => $name,
                    'problems' => implode(' ', $failure->problems),
                ]);

                continue;
            }

            if (isset($keys[$definition->key])) {
                $problems["types.{$i}.key"][] = __('The file has the key :key twice.', ['key' => $definition->key]);
            } else {
                $keys[$definition->key] = $i;
            }

            if (isset($segments[$definition->segment])) {
                $problems["types.{$i}.segment"][] = __('The file gives /admin/:segment to two types.', ['segment' => $definition->segment]);
            } else {
                $segments[$definition->segment] = $i;
            }

            $definitions[$i] = $definition;
        }

        $problems = array_merge($problems, self::taxonomyProblems($file, $segments));
        $problems = [...$problems, ...FieldsFile::problems($file, array_values(array_map(fn (TypeDefinition $type): string => $type->key, $definitions)))];

        if (is_array($file['grants'] ?? null) && array_is_list($file['grants'])) {
            $byKey = [];

            foreach ($definitions as $definition) {
                $byKey[$definition->key] = $definition;
            }

            $seen = [];

            foreach ($file['grants'] as $i => $entry) {
                foreach (self::grantEntryProblems($entry, $i, $byKey, $seen) as $message) {
                    $problems["grants.{$i}"][] = $message;
                }
            }
        } elseif (array_key_exists('grants', $file)) {
            $problems['grants'][] = __('[grants] must be a list.', ['key' => 'grants']);
        }

        self::refuse($problems);

        /** @var list<TypeDefinition> */
        return array_values($definitions);
    }

    /**
     * The taxonomies a file names, after definitions() has accepted it. A
     * file it refuses throws.
     *
     * @param  array<mixed>  $file
     *
     * @return list<TaxonomyDefinition>
     *
     * @throws ValidationException
     */
    public static function taxonomies(array $file): array
    {
        self::definitions($file);

        $definitions = [];

        foreach (is_array($file['taxonomies'] ?? null) ? $file['taxonomies'] : [] as $raw) {
            if (is_array($raw)) {
                $definitions[] = TaxonomyDefinition::fromArray($raw);
            }
        }

        return $definitions;
    }

    /**
     * What is wrong with the file's taxonomies: each must be an object that
     * parses, with a key and an admin segment of its own, apart from the
     * file's types' segments.
     *
     * @param  array<mixed>  $file
     * @param  array<string, int>  $typeSegments  the file's types' segments
     *
     * @return array<string, list<string>>
     */
    private static function taxonomyProblems(array $file, array $typeSegments): array
    {
        $taxonomies = $file['taxonomies'] ?? null;

        if (! is_array($taxonomies) || ! array_is_list($taxonomies)) {
            return [];
        }

        $problems = [];
        $keys = [];
        $segments = [];

        foreach ($taxonomies as $i => $raw) {
            if (! is_array($raw)) {
                $problems["taxonomies.{$i}"][] = __('Taxonomy :n: A taxonomy must be an object of named values.', ['n' => (string) ($i + 1)]);

                continue;
            }

            try {
                $definition = TaxonomyDefinition::fromArray($raw);
            } catch (InvalidTaxonomyDefinition $failure) {
                $name = is_string($raw['key'] ?? null) ? $raw['key'] : 'Taxonomy '.($i + 1);
                $problems["taxonomies.{$i}"][] = __(':name: :problems', [
                    'name' => $name,
                    'problems' => implode(' ', $failure->problems),
                ]);

                continue;
            }

            if (isset($keys[$definition->key])) {
                $problems["taxonomies.{$i}.key"][] = __('The file has the taxonomy key :key twice.', ['key' => $definition->key]);
            } else {
                $keys[$definition->key] = $i;
            }

            if (isset($segments[$definition->segment])) {
                $problems["taxonomies.{$i}.segment"][] = __('The file gives /admin/:segment to two taxonomies.', ['segment' => $definition->segment]);
            } else {
                $segments[$definition->segment] = $i;
            }

            if (isset($typeSegments[$definition->segment])) {
                $problems["taxonomies.{$i}.segment"][] = __('The file gives /admin/:segment to a type and a taxonomy.', ['segment' => $definition->segment]);
            }
        }

        return $problems;
    }

    /**
     * The file's grants, after definitions() has accepted it: type key, then
     * role name, then the actions. A file it refuses throws.
     *
     * @param  array<mixed>  $file
     *
     * @return array<string, array<string, list<string>>>
     *
     * @throws ValidationException
     */
    public static function grants(array $file): array
    {
        self::definitions($file);

        /** @var array<string, array<string, list<string>>> $grants */
        $grants = [];

        foreach ($file['grants'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null) || ! is_string($entry['role'] ?? null) || ! is_array($entry['abilities'] ?? null)) {
                continue;
            }

            /** @var list<string> $actions */
            $actions = array_values($entry['abilities']);
            $grants[$entry['type']][$entry['role']] = $actions;
        }

        return $grants;
    }

    /**
     * What is wrong with one grant. A problem with its shape skips the
     * checks that need a readable grant.
     *
     * @param  array<string, TypeDefinition>  $byKey
     * @param  array<string, true>  $seen
     *
     * @return list<string>
     */
    private static function grantEntryProblems(mixed $entry, int $index, array $byKey, array &$seen): array
    {
        $n = (string) ($index + 1);

        if (! is_array($entry) || array_is_list($entry)) {
            return [__('Grant :n: a grant must be an object of named values.', ['n' => $n])];
        }

        $problems = [];

        foreach (array_keys($entry) as $key) {
            if (! in_array($key, ['type', 'role', 'abilities'], true)) {
                $problems[] = __('Grant :n: [:key] isn’t part of a version 1 grant.', ['n' => $n, 'key' => (string) $key]);
            }
        }

        if (! is_string($entry['type'] ?? null)) {
            $problems[] = __('Grant :n: [type] must be text.', ['n' => $n]);
        }

        if (! is_string($entry['role'] ?? null)) {
            $problems[] = __('Grant :n: [role] must be text.', ['n' => $n]);
        }

        if (! self::abilitiesAreActions($entry['abilities'] ?? null)) {
            $problems[] = __('Grant :n: [abilities] must list a type’s actions, each once: :actions.', [
                'n' => $n,
                'actions' => implode(', ', array_keys(ContentType::ACTIONS)),
            ]);
        }

        if ($problems !== []) {
            return $problems;
        }

        /** @var array{type: string, role: string, abilities: list<string>} $entry */
        $type = $entry['type'];
        $role = $entry['role'];

        if (! isset($byKey[$type])) {
            return [__('Grant :n: the file has no type :type.', ['n' => $n, 'type' => $type])];
        }

        if ($byKey[$type]->permissions !== TypeDefinition::OWN) {
            return [__('Grant :n: :type uses the standard content permissions, so it has none of its own to grant.', ['n' => $n, 'type' => $type])];
        }

        $pair = $type."\0".$role;

        if (isset($seen[$pair])) {
            return [__('Grant :n: the file grants :type to :role twice.', ['n' => $n, 'type' => $type, 'role' => $role])];
        }

        $seen[$pair] = true;

        return [];
    }

    private static function abilitiesAreActions(mixed $abilities): bool
    {
        if (! is_array($abilities) || ! array_is_list($abilities)) {
            return false;
        }

        $allowed = array_keys(ContentType::ACTIONS);
        $seen = [];

        foreach ($abilities as $action) {
            if (! is_string($action) || ! in_array($action, $allowed, true) || isset($seen[$action])) {
                return false;
            }

            $seen[$action] = true;
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $problems
     *
     * @throws ValidationException
     */
    private static function refuse(array $problems): void
    {
        if ($problems === []) {
            return;
        }

        throw ValidationException::withMessages($problems);
    }
}

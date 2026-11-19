<?php

/**
 * What a translation starts from (I-3360, D8): a revision's title, document
 * (every block ID kept), excerpt and fields, the canonical cleared, its links
 * by ID pointed at the target language's versions where their groups have
 * one, live or not, and its terms mapped to their siblings, the missing ones
 * made. Call it inside TranslateContent's perform(), after its locks.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Contents;

use App\Domain\Cards\DocumentSchema;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Taxonomy\TermSiblings;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Cache\CacheKeysFor;

final class TranslationCopy
{
    /**
     * The copy for a version in $language (as a row stores it).
     *
     * @return array{title: string, document: array<mixed>, fields: array<string, mixed>, terms: list<string>, made: list<string>, purge: list<CacheKeysFor>}
     */
    public static function of(ContentRevision $revision, string $type, ?string $language): array
    {
        $document = (array) $revision->document;
        $fields = (array) $revision->fields;
        $terms = array_values(array_filter((array) $revision->terms, is_string(...)));
        $entries = array_map(fn ($field): string => $field->key, array_filter(app(FieldRegistry::class)->forType($type), fn ($field): bool => $field->type instanceof EntryField));

        // A canonical naming the source would drop the version out of search.
        unset($fields[BuiltInFields::CANONICAL]);

        // Every item and term linked, read at once: the kinds of Paths::KINDS a link can name, as PathLinkResolver's.
        $linked = ['content' => [], 'term' => []];
        self::collect($document, $linked);

        foreach ($entries as $key) {
            if (is_string($fields[$key] ?? null)) {
                $linked['content'][] = $fields[$key];
            }
        }

        $items = self::versions('contents', array_values(array_unique($linked['content'])), $language);
        $termLinks = self::versions('terms', array_values(array_unique($linked['term'])), $language);
        $siblings = TermSiblings::inLanguage($terms, $language);

        foreach ($entries as $key) {
            if (is_string($fields[$key] ?? null)) {
                $fields[$key] = $items[$fields[$key]] ?? $fields[$key];
            }
        }

        $primary = $fields[BuiltInFields::PRIMARY_CATEGORY] ?? null;

        if (is_string($primary)) {
            $fields[BuiltInFields::PRIMARY_CATEGORY] = $siblings['map'][$primary] ?? self::versions('terms', [$primary], $language)[$primary] ?? $primary;
        }

        return [
            'title' => (string) $revision->title,
            'document' => self::pointed($document, $items, $termLinks),
            'fields' => $fields,
            'terms' => array_values(array_unique(array_map(fn (string $id): string => $siblings['map'][$id] ?? $id, $terms))),
            'made' => $siblings['made'],
            'purge' => $siblings['purge'],
        ];
    }

    /**
     * Each of these items' (or terms') version in the language, by ID: the
     * member of its translation group in that language, not in the trash.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    private static function versions(string $table, array $ids, ?string $language): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table.' as linked')
            ->join($table.' as version', 'version.translation_group', '=', 'linked.translation_group')
            ->whereIn('linked.id', $ids)
            ->when($language === null, fn ($query) => $query->whereNull('version.language'), fn ($query) => $query->where('version.language', $language))
            ->when($table === 'contents', fn ($query) => $query->whereNull('version.deleted_at'))
            ->select(['linked.id as linked_id', 'version.id as version_id'])
            ->pluck('version_id', 'linked_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * The IDs a document links to: link marks' items and terms, buttons' items.
     *
     * @param  array<mixed>  $node
     * @param  array{content: list<string>, term: list<string>}  $linked
     */
    private static function collect(array $node, array &$linked): void
    {
        foreach ((array) ($node['marks'] ?? []) as $mark) {
            $target = is_array($mark) && ($mark['type'] ?? null) === 'link' && is_string($mark['attrs']['href'] ?? null) ? DocumentSchema::linkTarget($mark['attrs']['href']) : null;

            if ($target !== null && isset($linked[$target['kind']])) {
                $linked[$target['kind']][] = $target['id'];
            }
        }

        if (($node['type'] ?? null) === 'button' && is_string($node['attrs']['contentId'] ?? null)) {
            $linked['content'][] = $node['attrs']['contentId'];
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                self::collect($child, $linked);
            }
        }
    }

    /**
     * The document with its links pointed at the versions found.
     *
     * @param  array<mixed>  $node
     * @param  array<string, string>  $items
     * @param  array<string, string>  $terms
     *
     * @return array<mixed>
     */
    private static function pointed(array $node, array $items, array $terms): array
    {
        if (is_array($node['marks'] ?? null)) {
            foreach ($node['marks'] as $index => $mark) {
                $target = is_array($mark) && ($mark['type'] ?? null) === 'link' && is_string($mark['attrs']['href'] ?? null) ? DocumentSchema::linkTarget($mark['attrs']['href']) : null;
                $to = match ($target['kind'] ?? null) {
                    'content' => $items[$target['id']] ?? null,
                    'term' => $terms[$target['id']] ?? null,
                    default => null,
                };

                if ($target !== null && $to !== null) {
                    $node['marks'][$index]['attrs']['href'] = DocumentSchema::linkTo($target['kind'], $to, $target['place']);
                }
            }
        }

        if (($node['type'] ?? null) === 'button' && is_string($node['attrs']['contentId'] ?? null) && isset($items[$node['attrs']['contentId']])) {
            $node['attrs']['contentId'] = $items[$node['attrs']['contentId']];
        }

        if (is_array($node['content'] ?? null)) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = self::pointed($child, $items, $terms);
                }
            }
        }

        return $node;
    }
}

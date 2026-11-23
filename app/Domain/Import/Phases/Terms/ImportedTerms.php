<?php

/**
 * What an import's map says of Teme's terms: which Teme term a WordPress
 * term became, found by WordPress's own term ID, whether a source made a
 * given term, and which terms have a parent on WordPress (I-397).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Import\Phases\Terms;

use App\Domain\Import\Models\ImportMapEntry;

final readonly class ImportedTerms
{
    /**
     * The Teme term this source made of WordPress's term $termId in the
     * WordPress taxonomy $taxonomy (category), or null when it made none.
     */
    public function byWordPressTerm(string $sourceId, string $taxonomy, int $termId): ?string
    {
        $id = ImportMapEntry::query()
            ->where('source_id', $sourceId)
            ->where('object_type', 'term')
            ->whereNotNull('teme_id')
            ->whereRaw('facts @> ?::jsonb', [json_encode(['term_id' => $termId, 'taxonomy' => $taxonomy], JSON_THROW_ON_ERROR)])
            ->value('teme_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * The WordPress ID of the map row an import from this source's export
     * file made of a term with these facts, or null when it made none.
     *
     * @param  array<string, int|string>  $facts
     *
     * @since 1.4.0
     */
    public function fromFile(string $sourceId, array $facts): ?string
    {
        $id = ImportMapEntry::query()
            ->where('source_id', $sourceId)
            ->where('object_type', 'term')
            ->whereRaw("jsonb_exists(facts, 'file_term_id')")
            ->whereRaw('facts @> ?::jsonb', [json_encode($facts, JSON_THROW_ON_ERROR)])
            ->orderBy('object_id')
            ->value('object_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Whether this source made the Teme term $termId.
     */
    public function madeBy(string $sourceId, string $termId): bool
    {
        return ImportMapEntry::query()
            ->where('teme_type', 'term')
            ->where('teme_id', $termId)
            ->where('source_id', $sourceId)
            ->where('object_type', 'term')
            ->exists();
    }

    /**
     * This source's terms that have a parent on WordPress, after the
     * term_taxonomy_id $afterId, in WordPress's order: each one's ID there,
     * its Teme ID, its WordPress taxonomy and its parent's term ID.
     *
     * @return list<array{objectId: int, termId: string, taxonomy: string, parent: int}>
     */
    public function withParents(string $sourceId, int $afterId, int $limit): array
    {
        $after = (string) $afterId;

        return ImportMapEntry::query()
            ->where('source_id', $sourceId)
            ->where('object_type', 'term')
            ->whereNotNull('teme_id')
            ->whereRaw("jsonb_typeof(facts->'parent') = 'number' and facts->'parent' > '0'::jsonb")
            // Digits compared as text: the shorter number first, then by digit.
            ->whereRaw('(char_length(object_id), object_id) > (char_length(?::text), ?::text)', [$after, $after])
            ->orderByRaw('char_length(object_id), object_id')
            ->limit($limit)
            ->get(['object_id', 'teme_id', 'facts'])
            ->map(fn (ImportMapEntry $row): array => [
                'objectId' => (int) $row->object_id,
                'termId' => (string) $row->teme_id,
                'taxonomy' => (string) ($row->facts['taxonomy'] ?? ''),
                'parent' => (int) ($row->facts['parent'] ?? 0),
            ])
            ->values()
            ->all();
    }
}

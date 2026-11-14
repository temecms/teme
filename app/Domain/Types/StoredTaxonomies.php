<?php

declare(strict_types=1);

namespace App\Domain\Types;

use Illuminate\Support\Facades\DB;

/**
 * The site's own taxonomies: their table. The copy every process reads is
 * the stored types', which holds both tables under one version (I-3296).
 */
final class StoredTaxonomies
{
    public const string TABLE = 'taxonomy_definitions';

    /**
     * Every stored definition, its key first, oldest first, straight from the table.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return DB::table(self::TABLE)
            ->orderBy('created_at')
            ->orderBy('key')
            ->get(['key', 'definition'])
            ->map(fn (object $row): array => ['key' => (string) $row->key, ...(array) json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR)])
            ->values()
            ->all();
    }

    /**
     * The row for this key, under lockForUpdate when asked; null when there
     * is none. An unreadable row throws InvalidTaxonomyDefinition.
     */
    public function find(string $key, bool $forUpdate = false): ?TaxonomyDefinition
    {
        $query = DB::table(self::TABLE)->where('key', $key);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $row = $query->first(['key', 'definition']);

        if ($row === null) {
            return null;
        }

        return TaxonomyDefinition::fromArray([
            'key' => (string) $row->key,
            ...(array) json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The row for this key read as far as it can be, under lockForUpdate when
     * asked; null when there is none.
     */
    public function findLeniently(string $key, bool $forUpdate = false): ?TaxonomyDefinition
    {
        $query = DB::table(self::TABLE)->where('key', $key);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $row = $query->first(['key', 'definition']);

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR);

        return TaxonomyDefinition::lenient([
            'key' => (string) $row->key,
            ...(is_array($decoded) ? $decoded : []),
        ]);
    }

    /**
     * Writes a new row for this taxonomy.
     */
    public function insert(TaxonomyDefinition $taxonomy): void
    {
        $definition = $taxonomy->toArray();
        unset($definition['key']);

        DB::table(self::TABLE)->insert([
            'key' => $taxonomy->key,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Replaces the definition for an existing key.
     */
    public function update(TaxonomyDefinition $taxonomy): void
    {
        $definition = $taxonomy->toArray();
        unset($definition['key']);

        DB::table(self::TABLE)->where('key', $taxonomy->key)->update([
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    /**
     * Removes the row for this key.
     */
    public function delete(string $key): void
    {
        DB::table(self::TABLE)->where('key', $key)->delete();
    }

    /**
     * The row's plural label, or its key when it has none, under
     * lockForUpdate; null when there is no row: for DeleteTaxonomy on a row
     * that may not parse.
     */
    public function rawPlural(string $key): ?string
    {
        $plural = DB::table(self::TABLE)
            ->where('key', $key)
            ->lockForUpdate()
            ->selectRaw("COALESCE(definition ->> 'plural', key) AS plural")
            ->value('plural');

        return $plural === null ? null : (string) $plural;
    }

    /**
     * Whether a row has this key.
     */
    public function exists(string $key): bool
    {
        return DB::table(self::TABLE)->where('key', $key)->exists();
    }
}

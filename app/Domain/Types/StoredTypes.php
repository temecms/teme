<?php

declare(strict_types=1);

namespace App\Domain\Types;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The site's own content types and taxonomies: the types' table, and the
 * copy of both tables the cache store keeps with one version, which every
 * process reads instead of the tables, and a change puts again after it
 * commits (I-3296).
 */
final class StoredTypes
{
    public const string TABLE = 'content_type_definitions';

    public const string CACHE_KEY = 'types:stored';

    /**
     * How long the cache store keeps the copy: when it goes, the next read
     * makes it again from the table, with the same version unless the types
     * changed. So a copy a failed put left stale mends itself within five
     * minutes, for one table read every five minutes.
     */
    public const int CACHE_SECONDS = 300;

    /**
     * The stored types and taxonomies and their version: the cache store's
     * copy, or the tables when there is none.
     *
     * @return array{version: string, definitions: list<array<string, mixed>>, taxonomies: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $kept = $this->kept();

        if ($kept !== null) {
            return $kept;
        }

        // A migration may read the registries before these tables exist, as a
        // new site migrates. One statement asks for both: to_regclass answers
        // NULL rather than failing, so no transaction is aborted.
        $tables = DB::selectOne('SELECT to_regclass(?) AS types, to_regclass(?) AS taxonomies', [self::TABLE, StoredTaxonomies::TABLE]);

        if ($tables === null || $tables->types === null) {
            return ['version' => '', 'definitions' => [], 'taxonomies' => []];
        }

        $snapshot = $this->fresh($tables->taxonomies !== null);

        // add(), never put(): a change that committed while this read ran has
        // put the newer copy, and it must stay (I-3296).
        if (! Cache::add(self::CACHE_KEY, $snapshot, self::CACHE_SECONDS)) {
            return $this->kept() ?? $snapshot;
        }

        return $snapshot;
    }

    /**
     * After a change commits: puts the tables as they are now into the cache
     * store, under the types' lock, so two changes' copies land in the order
     * the changes committed.
     */
    public function remember(): void
    {
        DB::transaction(function (): void {
            self::lock();

            // As snapshot() asks: before the migrations have run there is
            // nothing to remember, and no table to read.
            $tables = DB::selectOne('SELECT to_regclass(?) AS types, to_regclass(?) AS taxonomies', [self::TABLE, StoredTaxonomies::TABLE]);

            if ($tables === null || $tables->types === null) {
                return;
            }

            Cache::put(self::CACHE_KEY, $this->fresh($tables->taxonomies !== null), self::CACHE_SECONDS);
        });
    }

    /**
     * Holds every other change to the stored types until this transaction ends.
     */
    public static function lock(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('teme.types'))");
    }

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
     * is none. An unreadable row throws InvalidTypeDefinition.
     */
    public function find(string $key, bool $forUpdate = false): ?TypeDefinition
    {
        $query = DB::table(self::TABLE)->where('key', $key);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $row = $query->first(['key', 'definition']);

        if ($row === null) {
            return null;
        }

        return TypeDefinition::fromArray([
            'key' => (string) $row->key,
            ...(array) json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The row for this key read as far as it can be, under lockForUpdate when
     * asked; null when there is none.
     */
    public function findLeniently(string $key, bool $forUpdate = false): ?TypeDefinition
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

        return TypeDefinition::lenient([
            'key' => (string) $row->key,
            ...(is_array($decoded) ? $decoded : []),
        ]);
    }

    /**
     * Whether a row has this key.
     */
    public function exists(string $key): bool
    {
        return DB::table(self::TABLE)->where('key', $key)->exists();
    }

    /**
     * Writes a new row for this type.
     */
    public function insert(TypeDefinition $type): void
    {
        $definition = $type->toArray();
        unset($definition['key']);

        DB::table(self::TABLE)->insert([
            'key' => $type->key,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Replaces the definition for an existing key.
     */
    public function update(TypeDefinition $type): void
    {
        $definition = $type->toArray();
        unset($definition['key']);

        DB::table(self::TABLE)->where('key', $type->key)->update([
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
     * The row's singular label, or its key when it has none, under
     * lockForUpdate; null when there is no row — for DeleteType on a row
     * that may not parse.
     */
    public function rawSingular(string $key): ?string
    {
        $singular = DB::table(self::TABLE)
            ->where('key', $key)
            ->lockForUpdate()
            ->selectRaw("COALESCE(definition ->> 'singular', key) AS singular")
            ->value('singular');

        return $singular === null ? null : (string) $singular;
    }

    /**
     * @return array{version: string, definitions: list<array<string, mixed>>, taxonomies: list<array<string, mixed>>}
     */
    private function fresh(bool $withTaxonomies): array
    {
        $definitions = $this->definitions();
        $taxonomies = $withTaxonomies ? app(StoredTaxonomies::class)->definitions() : [];

        return [
            'version' => hash('xxh128', json_encode([$definitions, $taxonomies], JSON_THROW_ON_ERROR)),
            'definitions' => $definitions,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * @return array{version: string, definitions: list<array<string, mixed>>, taxonomies: list<array<string, mixed>>}|null
     */
    private function kept(): ?array
    {
        $kept = Cache::get(self::CACHE_KEY);

        if (! is_array($kept) || ! is_string($kept['version'] ?? null) || ! is_array($kept['definitions'] ?? null)) {
            return null;
        }

        // A copy put before taxonomies were stored has none, which was true then.
        $taxonomies = $kept['taxonomies'] ?? [];

        if (! is_array($taxonomies)) {
            return null;
        }

        /** @var array{version: string, definitions: list<array<string, mixed>>, taxonomies: list<array<string, mixed>>} */
        return ['version' => $kept['version'], 'definitions' => $kept['definitions'], 'taxonomies' => $taxonomies];
    }
}

<?php

/**
 * The site's own fields: their table, and the copy of it the cache store
 * keeps with a version, which every process reads instead of the table, and
 * a change puts again after it commits, under the types' lock (I-3296).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Fields\Stored;

use App\Domain\Types\StoredTypes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

final class StoredFields
{
    public const string TABLE = 'field_definitions';

    public const string CACHE_KEY = 'fields:stored';

    /** As the types' copy: a stale copy mends itself within five minutes. */
    public const int CACHE_SECONDS = 300;

    /**
     * The stored fields and their version: the cache store's copy, or the
     * table when there is none.
     *
     * @return array{version: string, definitions: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $kept = $this->kept();

        if ($kept !== null) {
            return $kept;
        }

        // As the types' snapshot: a migration may ask before the table exists.
        if (DB::scalar('SELECT to_regclass(?)', [self::TABLE]) === null) {
            return ['version' => '', 'definitions' => []];
        }

        $snapshot = $this->fresh();

        // add(), never put(): a change that committed meanwhile put a newer copy (I-3296).
        if (! Cache::add(self::CACHE_KEY, $snapshot, self::CACHE_SECONDS)) {
            return $this->kept() ?? $snapshot;
        }

        return $snapshot;
    }

    /**
     * After a change commits: puts the table as it is now into the cache
     * store, under the types' lock, so copies land in the order changes committed.
     */
    public function remember(): void
    {
        DB::transaction(function (): void {
            StoredTypes::lock();
            Cache::put(self::CACHE_KEY, $this->fresh(), self::CACHE_SECONDS);
        });
    }

    /**
     * Every row, live and removed, by type, then position, then key,
     * straight from the table, each as its columns with the definition decoded.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return DB::table(self::TABLE)
            ->orderBy('content_type')
            ->orderBy('position')
            ->orderBy('key')
            ->get(['key', 'content_type', 'handle', 'position', 'definition', 'removed_at', 'removed_by'])
            ->map(fn (object $row): array => [
                'key' => (string) $row->key,
                'content_type' => (string) $row->content_type,
                'handle' => (string) $row->handle,
                'position' => (int) $row->position,
                'definition' => json_decode((string) $row->definition, true, flags: JSON_THROW_ON_ERROR),
                'removed_at' => $row->removed_at === null ? null : (string) $row->removed_at,
                'removed_by' => $row->removed_by === null ? null : (int) $row->removed_by,
            ])
            ->values()
            ->all();
    }

    /**
     * One type's rows, live and removed, in position order, straight from
     * the table; a row that doesn't read is left out.
     *
     * @return list<FieldDefinition>
     */
    public function forType(string $type): array
    {
        $definitions = [];

        foreach (DB::table(self::TABLE)->where('content_type', $type)->orderBy('position')->orderBy('key')->get() as $row) {
            try {
                $definitions[] = FieldDefinition::fromRow($row);
            } catch (InvalidArgumentException $unread) {
                report($unread);
            }
        }

        return $definitions;
    }

    /**
     * Every row of the type, live and removed, as its columns say, read or
     * not: a row that doesn't read still holds its handle.
     *
     * @return list<array{key: string, handle: string, removed: bool}>
     */
    public function handles(string $contentType): array
    {
        return DB::table(self::TABLE)
            ->where('content_type', $contentType)
            ->orderBy('key')
            ->get(['key', 'handle', 'removed_at'])
            ->map(fn (object $row): array => ['key' => (string) $row->key, 'handle' => (string) $row->handle, 'removed' => $row->removed_at !== null])
            ->values()
            ->all();
    }

    /**
     * Numbers the type's live fields 1..n: the keys in $first in that order,
     * then every other live field by position, then key. Called inside the
     * change's transaction, under the types' lock, after any change to which
     * fields a type has or their order.
     *
     * @param  list<string>  $first
     */
    public function renumber(string $contentType, array $first = []): void
    {
        DB::update(<<<'SQL'
            UPDATE field_definitions AS f
            SET position = o.n, updated_at = now()
            FROM (
                SELECT d.key, row_number() OVER (ORDER BY g.n NULLS LAST, d.position, d.key) AS n
                FROM field_definitions AS d
                LEFT JOIN unnest(?::text[]) WITH ORDINALITY AS g(key, n) ON g.key = d.key
                WHERE d.content_type = ? AND d.removed_at IS NULL
            ) AS o
            WHERE f.key = o.key AND f.position IS DISTINCT FROM o.n
            SQL, ['{'.implode(',', $first).'}', $contentType]);
    }

    /**
     * The row for this key, under lockForUpdate when asked; null when there
     * is none. A row that doesn't read throws InvalidArgumentException.
     */
    public function find(string $key, bool $forUpdate = false): ?FieldDefinition
    {
        $query = DB::table(self::TABLE)->where('key', $key);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row === null ? null : FieldDefinition::fromRow($row);
    }

    /**
     * Writes a new row for this field.
     */
    public function insert(FieldDefinition $field): void
    {
        DB::table(self::TABLE)->insert([
            'key' => $field->key,
            ...$this->columns($field),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Replaces the row for an existing key: its handle, position, definition and removal.
     */
    public function update(FieldDefinition $field): void
    {
        // A row never changes type: its type's items hold its values.
        DB::table(self::TABLE)->where('key', $field->key)->update([
            ...array_diff_key($this->columns($field), ['content_type' => true]),
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
     * Removes every row of the type, live and removed, and says how many.
     */
    public function deleteForType(string $contentType): int
    {
        return DB::table(self::TABLE)->where('content_type', $contentType)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(FieldDefinition $field): array
    {
        $definition = $field->toArray();
        unset($definition['key'], $definition['type'], $definition['handle']);

        // An empty PHP array is a JSON list; settings are an object (.ai/rules/models.md).
        if ($definition['settings'] === []) {
            $definition['settings'] = new stdClass;
        }

        return [
            'content_type' => $field->type,
            'handle' => $field->handle,
            'position' => $field->position,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'removed_at' => $field->removedAt,
            'removed_by' => $field->removedBy,
        ];
    }

    /**
     * @return array{version: string, definitions: list<array<string, mixed>>}
     */
    private function fresh(): array
    {
        $definitions = $this->definitions();

        return ['version' => hash('xxh128', json_encode($definitions, JSON_THROW_ON_ERROR)), 'definitions' => $definitions];
    }

    /**
     * @return array{version: string, definitions: list<array<string, mixed>>}|null
     */
    private function kept(): ?array
    {
        $kept = Cache::get(self::CACHE_KEY);

        if (! is_array($kept) || ! is_string($kept['version'] ?? null) || ! is_array($kept['definitions'] ?? null)) {
            return null;
        }

        /** @var array{version: string, definitions: list<array<string, mixed>>} $kept */
        return $kept;
    }
}

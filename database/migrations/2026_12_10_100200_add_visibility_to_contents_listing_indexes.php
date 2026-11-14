<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The author and pinned indexes, without private items (I-1700): both
 * listings now ask for visibility <> 'private', and a partial index serves
 * only a query that repeats its condition. Each is built under a new name
 * before the old one goes, concurrently, so nothing is ever unindexed and
 * the table stays writable; that cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const string PINNED = "(fields ->> '01K5Z0F1ED00000000000000P8') = 'true'";

    public function up(): void
    {
        $this->rebuild('contents_author_published', 'ON contents (type, author_id, published_at DESC, id DESC)', "status = 'published' AND deleted_at IS NULL AND visibility <> 'private'");
        $this->rebuild('contents_pinned_published', 'ON contents (type, published_at DESC, id DESC)', "status = 'published' AND deleted_at IS NULL AND visibility <> 'private' AND ".self::PINNED);
    }

    public function down(): void
    {
        $this->rebuild('contents_author_published', 'ON contents (type, author_id, published_at DESC, id DESC)', "status = 'published' AND deleted_at IS NULL");
        $this->rebuild('contents_pinned_published', 'ON contents (type, published_at DESC, id DESC)', "status = 'published' AND deleted_at IS NULL AND ".self::PINNED);
    }

    private function rebuild(string $name, string $columns, string $where): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name}_next {$columns} WHERE {$where}");
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        DB::statement("ALTER INDEX {$name}_next RENAME TO {$name}");
    }
};

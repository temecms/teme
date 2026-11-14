<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Title search on the lists (I-146). pg_trgm is a trusted extension, so the
 * application's non-superuser role can create it (SECURITY.md commitment 8).
 *
 * Titles and term names sort with the language-neutral ICU collation
 * (I-150), so the order is part of the schema rather than of whichever
 * operating system image the database runs on. Changing a column's collation
 * rebuilds the indexes on it. Slugs keep the C collation on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contents ALTER COLUMN title TYPE varchar(512) COLLATE "und-x-icu"');
        DB::statement('ALTER TABLE terms ALTER COLUMN name TYPE varchar(200) COLLATE "und-x-icu"');

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX contents_title_trgm ON contents USING gin (title gin_trgm_ops) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contents_title_trgm');
        DB::statement('ALTER TABLE terms ALTER COLUMN name TYPE varchar(200) COLLATE "default"');
        DB::statement('ALTER TABLE contents ALTER COLUMN title TYPE varchar(512) COLLATE "default"');
    }
};

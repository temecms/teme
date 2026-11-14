<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * docs/architecture/media.md, "Indexes": the library's and the picker's.
 * media_search_text is IMMUTABLE so an index can use it, and every query
 * searches through the same function, so the index always matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION media_search_text(filename text, title text, alt text, caption text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE
            RETURN lower(coalesce(filename, '') || ' ' || coalesce(title, '') || ' ' || coalesce(alt, '') || ' ' || coalesce(caption, ''))
            SQL);

        DB::statement('CREATE INDEX media_search ON media USING gin (media_search_text(original_filename, title, alt, caption) gin_trgm_ops) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX media_live_created ON media (created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX media_live_kind_created ON media (kind, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX media_live_uploader_created ON media (uploaded_by, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX media_live_unused ON media (created_at, id) WHERE deleted_at IS NULL AND working_uses = 0 AND published_uses = 0');
        DB::statement("CREATE INDEX media_live_missing_alt ON media (created_at, id) WHERE deleted_at IS NULL AND kind = 'image' AND (alt IS NULL OR alt = '')");
    }

    public function down(): void
    {
        foreach (['media_search', 'media_live_created', 'media_live_kind_created', 'media_live_uploader_created', 'media_live_unused', 'media_live_missing_alt'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement('DROP FUNCTION IF EXISTS media_search_text(text, text, text, text)');
    }
};

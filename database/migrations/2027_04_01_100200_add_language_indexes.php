<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The indexes languages read (I-3360): slugs unique per language, one item
 * a language in a group, lists in another language, a group's members and
 * a language's counts. Built concurrently, which cannot run inside a
 * transaction. A build cancelled half way leaves its index invalid, which
 * IF NOT EXISTS would then skip: such an index is dropped and built again.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['contents_type_slug_language_live_unique', 'contents_translation_group_language_unique', 'terms_taxonomy_slug_language_unique', 'terms_translation_group_language_unique', 'content_revisions_source_revision', 'contents_other_language_published', 'contents_translation_group', 'contents_language_type'] as $index) {
            $this->dropIfInvalid($index);
        }

        // One live item per type, slug and language. Slug before language, so the previous image's slug search still walks it.
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_type_slug_language_live_unique ON contents (type, slug, language) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
        // One live item per language in a group (I-3360).
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contents_translation_group_language_unique ON contents (translation_group, language) NULLS NOT DISTINCT WHERE translation_group IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS terms_taxonomy_slug_language_unique ON terms (taxonomy, slug, language) NULLS NOT DISTINCT');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS terms_translation_group_language_unique ON terms (translation_group, language) NULLS NOT DISTINCT WHERE translation_group IS NOT NULL');
        // A permanent delete sets these to NULL: without it, each deleted revision scans the table.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS content_revisions_source_revision ON content_revisions (source_revision_id) WHERE source_revision_id IS NOT NULL');
        // Lists in another language: only the other languages' rows, newest first.
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_other_language_published ON contents (language, type, published_at DESC, id DESC) WHERE status = 'published' AND deleted_at IS NULL AND language IS NOT NULL");
        // A group's members, trash included (GroupLock, memberIds()): the unique index above leaves the trash out.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_translation_group ON contents (translation_group) WHERE translation_group IS NOT NULL');
        // Counts of a language's or a type's items, trash included (RemoveSiteLanguage, ChangeTranslatedTypes): other languages' rows only.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_language_type ON contents (language, type) WHERE language IS NOT NULL');
    }

    public function down(): void
    {
        foreach (['contents_language_type', 'contents_translation_group', 'contents_other_language_published', 'content_revisions_source_revision', 'terms_translation_group_language_unique', 'terms_taxonomy_slug_language_unique', 'contents_translation_group_language_unique', 'contents_type_slug_language_live_unique'] as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    /**
     * Drops the index when a cancelled build left it invalid, so the build below makes it again.
     */
    private function dropIfInvalid(string $index): void
    {
        $invalid = DB::selectOne('SELECT 1 FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND n.nspname = current_schema() AND NOT i.indisvalid', [$index]);

        if ($invalid !== null) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};

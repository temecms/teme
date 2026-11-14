<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.5·0 (I-410, I-414, I-417). The two taxonomy CHECKs go: a CHECK
 * cannot know which taxonomies a plugin will register or which of them nest,
 * so the taxonomy registry enforces both rules on every write, as the
 * content-type registry does for contents.type, which never had a CHECK.
 *
 * Pages lose their categories and tags (I-417): their
 * content_terms rows go, and the term counts are recomputed with the same
 * statements RebuildCounts uses. Revisions are not touched; the projection
 * ignores a taxonomy the type doesn't use.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_taxonomy_check');
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_parent_categories_only');

        DB::statement("DELETE FROM content_terms ct USING contents c WHERE c.id = ct.content_id AND c.type = 'page'");

        DB::statement(<<<'SQL'
            UPDATE terms t SET published_count = COALESCE(x.n, 0)
            FROM terms t2 LEFT JOIN (SELECT term_id, count(*) AS n FROM content_terms WHERE scope = 'published' GROUP BY term_id) x ON x.term_id = t2.id
            WHERE t.id = t2.id AND t.published_count <> COALESCE(x.n, 0)
            SQL);

        DB::statement(<<<'SQL'
            UPDATE terms t SET working_count = COALESCE(x.n, 0)
            FROM terms t2 LEFT JOIN (SELECT term_id, count(*) AS n FROM content_terms WHERE scope = 'working' GROUP BY term_id) x ON x.term_id = t2.id
            WHERE t.id = t2.id AND t.working_count <> COALESCE(x.n, 0)
            SQL);
    }

    public function down(): void
    {
        // The pages' term links are not restored: nothing records them outside
        // the revisions, and the projection would rebuild them only if the
        // I-417 rule were reverted in the registry too.
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_taxonomy_check CHECK (taxonomy IN ('category', 'tag'))");
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_parent_categories_only CHECK (taxonomy = 'category' OR parent_id IS NULL)");
    }
};

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Export;

use Illuminate\Support\Facades\DB;

/**
 * Many published items written straight into contents and their revisions,
 * each with one paragraph of about $kilobytes of text, so an export has a
 * large site to read without the Operations' cost. IDs start x and y, so
 * they never meet a test's own.
 */
final class LargeSite
{
    public static function seed(int $items, int $kilobytes): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO contents (id, type, slug, status, published_at, fields, title, created_at, updated_at)
            SELECT 'x' || lpad(g::text, 25, '0'), 'post', 'large-' || g, 'published', now() - interval '1 day', '{}', 'Large ' || g, now(), now()
            FROM generate_series(1, ?) g
            SQL, [$items]);

        DB::statement(<<<'SQL'
            INSERT INTO content_revisions (id, content_id, document, fields, title, origin, created_at)
            SELECT 'y' || lpad(g::text, 25, '0'), 'x' || lpad(g::text, 25, '0'),
                   jsonb_build_object('type', 'doc', 'content', jsonb_build_array(jsonb_build_object(
                       'type', 'paragraph', 'content', jsonb_build_array(jsonb_build_object('type', 'text', 'text', repeat('word ', ?) || g))))),
                   '{}', 'Large ' || g, 'human', now()
            FROM generate_series(1, ?) g
            SQL, [intdiv($kilobytes * 1024, 5), $items]);

        DB::statement("UPDATE contents SET published_revision_id = 'y' || substr(id, 2), working_revision_id = 'y' || substr(id, 2) WHERE id LIKE 'x%'");
    }
}

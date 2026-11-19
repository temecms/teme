<?php

/**
 * The month rows of content_counts for the items already there, in the
 * site's zone (I-3131), in a migration of its own so this INSERT never holds
 * the column's ALTER lock. teme:counts:rebuild after deploying covers what
 * the old code wrote meanwhile (CHANGELOG.md's Deploy note).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The site's zone as Settings › General holds it, UTC when never set.
        $zone = (string) DB::scalar("SELECT COALESCE((SELECT overrides->>'timezone' FROM settings WHERE group_key = 'general'), 'UTC')");

        DB::statement(<<<'SQL'
            INSERT INTO content_counts (type, bucket, author_id, count)
            WITH items AS (
                SELECT type, author_id,
                    CASE WHEN deleted_at IS NOT NULL THEN 'trash' WHEN status = 'published' AND visibility = 'private' THEN 'private' ELSE status END AS state,
                    to_char(updated_at AT TIME ZONE ?, 'YYYY-MM') AS updated_month,
                    to_char(published_at AT TIME ZONE ?, 'YYYY-MM') AS published_month
                FROM contents
            )
            SELECT type, 'u:' || state || ':' || updated_month, 0, count(*) FROM items WHERE updated_month IS NOT NULL GROUP BY 1, 2
            UNION ALL
            SELECT type, 'u:' || state || ':' || updated_month, author_id, count(*) FROM items WHERE updated_month IS NOT NULL AND author_id IS NOT NULL GROUP BY 1, 2, 3
            UNION ALL
            SELECT type, 'p:' || published_month, 0, count(*) FROM items WHERE state = 'published' AND published_month IS NOT NULL GROUP BY 1, 2
            SQL, [$zone, $zone]);
    }

    public function down(): void
    {
        DB::statement("DELETE FROM content_counts WHERE bucket LIKE '%:%'");
    }
};

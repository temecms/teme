<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Comments screen's reads (1.8c's D8, the design pass's C10): each view
 * newest first, site-wide, from an index of its own statuses; Spam and
 * Trash counted as index-only scans; and the menu's badge and Home's figure
 * from the items with comments waiting alone. Partial, so each holds only
 * the rows it serves. Not built concurrently: comments arrived in 1.8a, and
 * no site holds enough of them yet for a lock to matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX comments_waiting ON comments (id) WHERE status = 'pending'");
        DB::statement("CREATE INDEX comments_inbox ON comments (id) WHERE status IN ('pending', 'approved')");
        DB::statement("CREATE INDEX comments_parked ON comments (status, id) INCLUDE (content_id) WHERE status IN ('spam', 'trash')");
        DB::statement('CREATE INDEX comment_stats_waiting ON comment_stats (content_id) INCLUDE (pending_count) WHERE pending_count > 0');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comment_stats_waiting');
        DB::statement('DROP INDEX IF EXISTS comments_parked');
        DB::statement('DROP INDEX IF EXISTS comments_inbox');
        DB::statement('DROP INDEX IF EXISTS comments_waiting');
    }
};

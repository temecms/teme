<?php

/**
 * One index per dashboard panel, so each panel is an index range scan with a
 * LIMIT, whatever the size of the site (study P §3, I-161).
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
        // My drafts: a person's own rows in one status, newest change first.
        DB::statement('CREATE INDEX contents_mine_status_updated ON contents (author_id, status, updated_at DESC, id DESC) WHERE deleted_at IS NULL');

        // Scheduled: every item with a pending schedule, soonest first, and one
        // person's own. It reads contents.scheduled_for, which 1.5e keeps equal
        // to the pending row's run_at, so a scheduled update to a live article
        // (still `published`) counts as well as a scheduled new one.
        DB::statement('CREATE INDEX contents_scheduled_for ON contents (scheduled_for, id) WHERE deleted_at IS NULL AND scheduled_for IS NOT NULL');
        DB::statement('CREATE INDEX contents_mine_scheduled_for ON contents (author_id, scheduled_for, id) WHERE deleted_at IS NULL AND scheduled_for IS NOT NULL');

        // My recent edits: a person's revisions, newest first. Nothing earlier
        // indexes content_revisions by author.
        DB::statement('CREATE INDEX content_revisions_author_created ON content_revisions (author_id, created_at DESC, id DESC)');

        // Waiting for review, oldest first; and one person's pending submissions.
        DB::statement('CREATE INDEX IF NOT EXISTS content_reviews_pending ON content_reviews (submitted_at) WHERE decided_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS content_reviews_pending_by_submitter ON content_reviews (submitted_by, submitted_at DESC) WHERE decided_at IS NULL');

        // Returned to me: a person's returned submissions, newest first; and
        // each candidate item's latest review, to drop what was submitted again.
        DB::statement("CREATE INDEX IF NOT EXISTS content_reviews_returned_by_submitter ON content_reviews (submitted_by, decided_at DESC) WHERE decision = 'returned'");
        DB::statement('CREATE INDEX IF NOT EXISTS content_reviews_content_submitted ON content_reviews (content_id, submitted_at DESC, id DESC)');
    }

    public function down(): void
    {
        foreach (['contents_mine_status_updated', 'contents_scheduled_for', 'contents_mine_scheduled_for', 'content_revisions_author_created', 'content_reviews_pending', 'content_reviews_pending_by_submitter', 'content_reviews_returned_by_submitter', 'content_reviews_content_submitted'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};

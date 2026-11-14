<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * When each comment's status last changed (1.8c′'s D2, the design pass's
 * C4, I-645), to the microsecond: Empty spam and Empty trash delete only what
 * was there when the list was opened, and teme:trash:purge deletes what has
 * sat in the trash longer than "Keep trash for". A column, never a meta row
 * (rule 2; WordPress keeps _wp_trash_meta_time in one). Added, filled from
 * when each comment was last updated, then required, with no default: every
 * Operation that writes a status writes this too, and an insert that forgets
 * it fails. Not built concurrently: no site holds enough comments yet for the
 * update's lock to matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE comments ADD COLUMN status_changed_at timestamptz(6)');
        DB::update('UPDATE comments SET status_changed_at = COALESCE(updated_at, created_at, ?::timestamptz)', [now()->format('Y-m-d H:i:s.uP')]);
        DB::statement('ALTER TABLE comments ALTER COLUMN status_changed_at SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comments DROP COLUMN IF EXISTS status_changed_at');
    }
};

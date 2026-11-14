<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A person who turned an email off keeps it off under its kind's key
        // (I-110); one who left it on needs nothing, the default is on. The old
        // keys stay for the release before, which still reads them.
        foreach (['notifications.comment_waiting' => 'notifications.comments.waiting', 'notifications.comment_approved' => 'notifications.comments.approved'] as $old => $new) {
            DB::update(
                "UPDATE users SET preferences = preferences || jsonb_build_object(?::text, '[]'::jsonb)
                 WHERE preferences -> ?::text = 'false'::jsonb AND preferences -> ?::text IS NULL",
                [$new, $old, $new],
            );
        }
    }

    public function down(): void
    {
        DB::update("UPDATE users SET preferences = preferences - 'notifications.comments.waiting' - 'notifications.comments.approved'");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens paths.target_id to 32 characters, so a content type's key, which
 * may be that long, can own the old address of its archive page (I-3304).
 * Raising a varchar's limit rewrites no row and rebuilds no index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Inside the migration's own transaction: a busy table makes it give up, not queue every read behind it.
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE paths ALTER COLUMN target_id TYPE varchar(32)');
    }

    public function down(): void
    {
        DB::table('paths')->where('target_type', 'type')->delete();
        DB::statement('ALTER TABLE paths ALTER COLUMN target_id TYPE varchar(26)');
    }
};

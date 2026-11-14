<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The daily erasure of commenters' addresses (I-270) reads the comments that
 * arrived keep_ip_days ago or earlier and still keep one. No other index on
 * comments has created_at, so without this each run would read every
 * comment. This one holds only the comments that keep an address, about
 * keep_ip_days of arrivals however many comments there are, and its
 * condition is the erasure's own, so the planner may use it. The comments
 * table is new in this release, so building it takes no time on any site.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX comments_addresses ON comments (created_at) WHERE ip IS NOT NULL OR user_agent IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS comments_addresses');
    }
};

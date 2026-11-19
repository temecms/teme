<?php

/**
 * Migration: numbers page cache purges.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Numbers the page cache's recent purges (slice 1.7d): PostgreSQL gives
 * each row the next number of a sequence as it is written, so the race
 * guard (I-264) compares the order purges were noted in, never two
 * servers' clocks. A drawing notes the latest number as it begins; a purge
 * of one of its tags numbered higher came after it. `purged_at` stays, for
 * the prune. Additive: code still running from before writes and reads as
 * it did, and the rows already here are numbered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_cache_purges', function (Blueprint $table): void {
            $table->dropPrimary(['tag', 'purged_at']);
        });

        Schema::table('page_cache_purges', function (Blueprint $table): void {
            $table->id();
            $table->index(['tag', 'id']);
        });
    }

    public function down(): void
    {
        // Cache data. Two purges of one tag in one microsecond, which numbers
        // allow, can't share the old key.
        DB::table('page_cache_purges')->delete();

        Schema::table('page_cache_purges', function (Blueprint $table): void {
            $table->dropIndex(['tag', 'id']);
            $table->dropColumn('id');
        });

        Schema::table('page_cache_purges', function (Blueprint $table): void {
            $table->primary(['tag', 'purged_at']);
        });
    }
};

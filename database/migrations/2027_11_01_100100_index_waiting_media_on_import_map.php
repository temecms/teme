<?php

/**
 * Migration: indexes what the import's outside pictures wait for.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A post brought before its pictures keeps, in its map row's facts, what
 * each outside picture waits for (#716). As the picture pass brings a page
 * of files, it finds the posts those files belong in through this index,
 * which holds only the rows still waiting. Built concurrently, so the map
 * stays writable; that cannot run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS import_map_waiting_media ON import_map USING gin ((facts -> 'waiting_media')) WHERE (facts -> 'waiting_media') <> '[]'::jsonb");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS import_map_waiting_media');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A parent's children in their order (I-3363): the walks down, the API's
 * ?parent= and the foreign key's SET NULL. Built concurrently, which cannot
 * run inside a transaction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS contents_parent ON contents (parent_id, position, id) WHERE parent_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS contents_parent');
    }
};

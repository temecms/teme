<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A source's attachments by their WordPress parent: the media uploaded for
 * a post, which a [gallery] without IDs shows (I-338).
 */
return new class extends Migration
{
    // CONCURRENTLY can't run inside the transaction Laravel wraps a migration in, and an existing table keeps taking writes.
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS import_map_attachment_parent_index ON import_map (source_id, (facts->'parent')) WHERE object_type = 'attachment'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS import_map_attachment_parent_index');
    }
};

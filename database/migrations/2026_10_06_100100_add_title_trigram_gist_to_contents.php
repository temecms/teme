<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The palette's title search: a trigram GiST index both filters by
 * `title ILIKE` and returns titles in word-similarity order (`<<->`), so the
 * first twenty come from one index scan however many titles match (B-199).
 * The GIN index beside it stays for the lists, which order by other columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX contents_title_trgm_gist ON contents USING gist (title gist_trgm_ops) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contents_title_trgm_gist');
    }
};

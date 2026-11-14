<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_status_check');
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_status_check CHECK (status IN ('draft', 'review', 'scheduled', 'published'))");

        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'review', 'scheduled', 'published', 'trash'))");
    }

    public function down(): void
    {
        // Fails while any item is in review, deliberately: a rollback must not
        // quietly change what an item is.
        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'scheduled', 'published', 'trash'))");

        DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_status_check');
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_status_check CHECK (status IN ('draft', 'scheduled', 'published'))");
    }
};

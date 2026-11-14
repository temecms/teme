<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who may read an item (I-1700): Public, Private or Password protected,
 * the password sealed with the application key, and published private
 * items counted apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A constant default: PostgreSQL writes no row to add it.
        DB::statement("ALTER TABLE contents ADD COLUMN visibility varchar(16) NOT NULL DEFAULT 'public'");
        DB::statement('ALTER TABLE contents ADD COLUMN reader_password text NULL');
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_visibility_check CHECK (visibility IN ('public', 'private', 'protected'))");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_reader_password_check CHECK ((visibility = 'protected') = (reader_password IS NOT NULL))");

        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'review', 'scheduled', 'published', 'private', 'trash'))");
    }

    public function down(): void
    {
        // Private items count as published again; teme:counts:rebuild makes the counts exact.
        DB::statement("DELETE FROM content_counts WHERE bucket = 'private'");
        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'review', 'scheduled', 'published', 'trash'))");

        DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_reader_password_check');
        DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_visibility_check');
        DB::statement('ALTER TABLE contents DROP COLUMN reader_password');
        DB::statement('ALTER TABLE contents DROP COLUMN visibility');
    }
};

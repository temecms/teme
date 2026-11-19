<?php

/**
 * Room in content_counts for month rows (I-3131): every item by the site
 * month of its last change, for everyone and for its author, as
 * u:{state}:{YYYY-MM}, and a live public item by the month it was published,
 * for everyone, as p:{YYYY-MM}; and an index for reading a tab's months by
 * their bucket's prefix. The rows themselves come in the next migration.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content_counts ALTER COLUMN bucket TYPE varchar(24)');
        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement(<<<'SQL'
            ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (
                bucket IN ('draft', 'review', 'scheduled', 'published', 'private', 'trash')
                OR bucket ~ '^u:(draft|review|scheduled|published|private|trash):[0-9]{4}-(0[1-9]|1[0-2])$'
                OR bucket ~ '^p:[0-9]{4}-(0[1-9]|1[0-2])$'
            )
            SQL);
        // The key's bucket sorts by the database's collation, so it can't serve a LIKE prefix; this one can.
        DB::statement('CREATE INDEX content_counts_months ON content_counts (type, author_id, bucket varchar_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS content_counts_months');
        DB::statement("DELETE FROM content_counts WHERE bucket LIKE '%:%'");
        DB::statement('ALTER TABLE content_counts DROP CONSTRAINT content_counts_bucket_check');
        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'review', 'scheduled', 'published', 'private', 'trash'))");
        DB::statement('ALTER TABLE content_counts ALTER COLUMN bucket TYPE varchar(16)');
    }
};

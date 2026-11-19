<?php

/**
 * Migration: adds content lifecycle schema.
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
 * docs/architecture/content-management.md: the status set, the title
 * projection, live-only slug uniqueness (I-142), the list indexes and the
 * maintained status counts (I-141).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_status_check CHECK (status IN ('draft', 'scheduled', 'published'))");

        Schema::table('contents', function (Blueprint $table): void {
            $table->string('title', 512)->default('');
        });

        DB::statement('UPDATE contents SET title = r.title FROM content_revisions r WHERE r.id = contents.working_revision_id');

        // Trash frees a slug: uniqueness applies to live rows only.
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropUnique(['type', 'slug']);
        });
        DB::statement('CREATE UNIQUE INDEX contents_type_slug_live_unique ON contents (type, slug) WHERE deleted_at IS NULL');

        // One index per tab and sort, so every list is an index range scan.
        DB::statement('CREATE INDEX contents_list_updated ON contents (type, updated_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX contents_list_status_updated ON contents (type, status, updated_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX contents_list_title ON contents (type, title, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX contents_list_status_title ON contents (type, status, title, id) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX contents_list_published ON contents (type, published_at, id) WHERE deleted_at IS NULL AND status = 'published'");
        DB::statement('CREATE INDEX contents_list_author_updated ON contents (type, author_id, updated_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX contents_list_trash ON contents (type, deleted_at, id) WHERE deleted_at IS NOT NULL');

        Schema::create('content_counts', function (Blueprint $table): void {
            $table->string('type', 64);
            $table->string('bucket', 16);
            // 0 is the all-authors row; any other value is one author's row.
            $table->unsignedBigInteger('author_id')->default(0);
            $table->bigInteger('count')->default(0);

            $table->primary(['type', 'bucket', 'author_id']);
        });

        DB::statement("ALTER TABLE content_counts ADD CONSTRAINT content_counts_bucket_check CHECK (bucket IN ('draft', 'scheduled', 'published', 'trash'))");

        DB::statement(<<<'SQL'
            INSERT INTO content_counts (type, bucket, author_id, count)
            SELECT type, CASE WHEN deleted_at IS NOT NULL THEN 'trash' ELSE status END, 0, count(*)
            FROM contents GROUP BY 1, 2
            UNION ALL
            SELECT type, CASE WHEN deleted_at IS NOT NULL THEN 'trash' ELSE status END, author_id, count(*)
            FROM contents WHERE author_id IS NOT NULL GROUP BY 1, 2, 3
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_counts');

        foreach (['contents_list_updated', 'contents_list_status_updated', 'contents_list_title', 'contents_list_status_title', 'contents_list_published', 'contents_list_author_updated', 'contents_list_trash', 'contents_type_slug_live_unique'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        Schema::table('contents', function (Blueprint $table): void {
            $table->unique(['type', 'slug']);
            $table->dropColumn('title');
        });

        DB::statement('ALTER TABLE contents DROP CONSTRAINT contents_status_check');
    }
};

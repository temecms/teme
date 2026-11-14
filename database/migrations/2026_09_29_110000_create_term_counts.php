<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 1.5·0 (I-410): each term's counts per content type, beside the
 * totals on terms, so a term shared by posts and a later type can show each
 * type's own count. TermProjection keeps both; RebuildCounts checks both.
 * Backfilled here from content_terms, which already holds the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_counts', function (Blueprint $table): void {
            $table->foreignUlid('term_id')->constrained('terms')->cascadeOnDelete();
            $table->string('type', 64);
            $table->integer('published')->default(0);
            $table->integer('working')->default(0);

            $table->primary(['term_id', 'type']);
        });

        DB::statement('ALTER TABLE term_counts ADD CONSTRAINT term_counts_not_negative CHECK (published >= 0 AND working >= 0)');

        DB::statement(<<<'SQL'
            INSERT INTO term_counts (term_id, type, published, working)
            SELECT ct.term_id, c.type,
                count(*) FILTER (WHERE ct.scope = 'published'),
                count(*) FILTER (WHERE ct.scope = 'working')
            FROM content_terms ct
            JOIN contents c ON c.id = ct.content_id
            GROUP BY ct.term_id, c.type
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('term_counts');
    }
};

<?php

/**
 * Migration: creates taxonomy tables.
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
 * docs/architecture/content-management.md, "Terms" and
 * "TermProjection::sync": categories and tags (I-145), assignments versioned
 * in each revision and projected into content_terms (I-144).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('taxonomy', 32);
            $table->string('name', 200);
            $table->string('slug', 200)->collation('C');
            $table->ulid('parent_id')->nullable();
            $table->text('description')->nullable();
            $table->jsonb('fields')->default('{}');
            $table->unsignedBigInteger('published_count')->default(0);
            $table->timestampsTz();

            $table->unique(['taxonomy', 'slug']);
            $table->index(['taxonomy', 'parent_id']);
            $table->index(['taxonomy', 'name', 'id']);
        });

        // A second step, not part of create(): the primary key is added after
        // the create statement, and PostgreSQL refuses a foreign key to a
        // column that is not yet unique. DeleteTerm moves children up before
        // deleting, so this only guards against a bug.
        Schema::table('terms', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('terms')->nullOnDelete();
        });

        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_taxonomy_check CHECK (taxonomy IN ('category', 'tag'))");
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_parent_categories_only CHECK (taxonomy = 'category' OR parent_id IS NULL)");
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_fields_object CHECK (jsonb_typeof(fields) = 'object')");

        // The picker searches names by prefix, case-insensitively.
        DB::statement('CREATE INDEX terms_name_prefix ON terms (taxonomy, lower(name) text_pattern_ops)');

        Schema::create('content_terms', function (Blueprint $table): void {
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('term_id')->constrained('terms')->cascadeOnDelete();
            $table->string('scope', 16);
            $table->unsignedSmallInteger('position');

            $table->primary(['content_id', 'scope', 'term_id']);
            $table->index(['term_id', 'scope', 'content_id']);
        });

        DB::statement("ALTER TABLE content_terms ADD CONSTRAINT content_terms_scope_check CHECK (scope IN ('working', 'published'))");

        Schema::table('content_revisions', function (Blueprint $table): void {
            $table->jsonb('terms')->default('[]');
        });

        DB::statement("ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_terms_list CHECK (jsonb_typeof(terms) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_revisions DROP CONSTRAINT IF EXISTS content_revisions_terms_list');

        Schema::table('content_revisions', function (Blueprint $table): void {
            $table->dropColumn('terms');
        });

        Schema::dropIfExists('content_terms');
        Schema::dropIfExists('terms');
    }
};

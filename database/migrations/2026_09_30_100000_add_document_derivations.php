<?php

/**
 * Migration: adds document derivations.
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
 * Slice 1.5b: what a revision knows about itself, and what search and links
 * are built from. A revision carries its plain text, word count and kind;
 * contents carries the published revision's search text; content_links
 * records which article links to which, in the working and published scopes.
 * All of it is derived from revisions by the content Operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_revisions', function (Blueprint $table): void {
            $table->unsignedInteger('word_count')->default(0);
            $table->string('kind', 16)->default('checkpoint');
            $table->text('plain_text')->nullable();
        });

        DB::statement("ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_kind_check CHECK (kind IN ('checkpoint', 'publish', 'restore', 'import', 'ai'))");

        Schema::table('contents', function (Blueprint $table): void {
            $table->text('search_text')->nullable();
        });

        // A plain tsvector column, written by the Operations. Not a generated
        // column: adding one rewrites the whole table under ACCESS EXCLUSIVE
        // (.ai/rules/migrations.md).
        DB::statement('ALTER TABLE contents ADD COLUMN search_tsv tsvector');

        Schema::create('content_links', function (Blueprint $table): void {
            $table->foreignUlid('from_content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('to_content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('scope', 16);

            $table->primary(['from_content_id', 'scope', 'to_content_id']);
            $table->index(['to_content_id', 'scope']);
        });

        DB::statement("ALTER TABLE content_links ADD CONSTRAINT content_links_scope_check CHECK (scope IN ('working', 'published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('content_links');

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['search_text', 'search_tsv']);
        });

        DB::statement('ALTER TABLE content_revisions DROP CONSTRAINT IF EXISTS content_revisions_kind_check');

        Schema::table('content_revisions', function (Blueprint $table): void {
            $table->dropColumn(['word_count', 'kind', 'plain_text']);
        });
    }
};

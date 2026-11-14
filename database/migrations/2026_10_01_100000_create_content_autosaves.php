<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Slice 1.5c (I-181, decision 1A): where a draft lives between keystrokes.
 * One row per person per article, holding the whole draft — title, document,
 * fields and terms — so a keystroke updates a row instead of writing a
 * revision. Revisions are made at checkpoints. The row is also presence:
 * someone with unsaved work in an article is who others need to know about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_autosaves', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 512);
            $table->jsonb('document');
            $table->jsonb('fields');
            $table->jsonb('terms');
            $table->foreignUlid('based_on_revision_id')->nullable()->constrained('content_revisions')->nullOnDelete();
            $table->unsignedInteger('word_count')->default(0);
            $table->timestampTz('saved_at');

            // One row per person per article: a keystroke updates, never inserts.
            $table->unique(['content_id', 'user_id']);
        });

        // Presence is read from this table, so the sweep needs the time alone.
        DB::statement('CREATE INDEX content_autosaves_saved_at ON content_autosaves (saved_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_autosaves');
    }
};

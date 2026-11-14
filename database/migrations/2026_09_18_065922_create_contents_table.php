<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 64);
            // C collation: slugs are lowercase ASCII, and byte ordering lets the
            // (type, slug) index answer prefix searches — see CreateDraft::uniqueSlug().
            $table->string('slug', 255)->collation('C');
            $table->string('status', 32)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Pointers added in a later migration: circular FK with content_revisions.
            $table->ulid('published_revision_id')->nullable();
            $table->ulid('working_revision_id')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('scheduled_for')->nullable();

            $table->jsonb('fields')->default('{}');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['type', 'slug']);
            $table->index(['type', 'status', 'published_at']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};

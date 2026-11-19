<?php

/**
 * Migration: creates content revisions table.
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

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();

            $table->jsonb('document');
            $table->jsonb('fields')->default('{}');

            $table->string('title', 512);
            $table->text('excerpt')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('origin', 16)->default('human');

            $table->timestampTz('created_at');

            $table->index(['content_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_origin_check
             CHECK (origin IN ('human', 'ai', 'import'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
    }
};

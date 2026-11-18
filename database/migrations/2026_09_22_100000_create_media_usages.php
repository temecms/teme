<?php

/**
 * Migration: creates media usages.
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
 * docs/architecture/media.md, "Where media is used": the media_usages
 * projection (spec §5.3) and the two use counters it maintains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unsignedBigInteger('working_uses')->default(0);
            $table->unsignedBigInteger('published_uses')->default(0);
        });

        Schema::create('media_usages', function (Blueprint $table): void {
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('ref', 64);

            $table->primary(['content_id', 'scope', 'ref']);
            $table->index(['media_id', 'content_id', 'scope']);
        });

        DB::statement("ALTER TABLE media_usages ADD CONSTRAINT media_usages_scope_check CHECK (scope IN ('working', 'published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usages');

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn(['working_uses', 'published_uses']);
        });
    }
};

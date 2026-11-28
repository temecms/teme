<?php

/**
 * Migration: lets an uploads copy be uploaded in pieces, and counts the
 * pictures an import brought from it and from the old site.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A copy uploaded in the browser is written in pieces while it is `uploading`,
 * and becomes the site's copy when it is `ready`. A site keeps one ready copy,
 * so the uniqueness of a site's copy moves to the ready rows alone. A copy
 * Teme itself stored is marked `kept_by_teme`: only that file is ever deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->dropUnique(['source_id']);
            $table->string('state', 16)->default('ready');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->boolean('kept_by_teme')->default(false);
            $table->unsignedInteger('copied_pictures')->default(0);
            $table->unsignedInteger('site_pictures')->default(0);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_uploads
                ADD CONSTRAINT import_uploads_state_check CHECK (state IN ('uploading', 'ready')),
                ADD CONSTRAINT import_uploads_received_check CHECK (received_bytes >= 0 AND received_bytes <= size_bytes)
            SQL);

        DB::statement("CREATE UNIQUE INDEX import_uploads_ready_source ON import_uploads (source_id) WHERE state = 'ready'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS import_uploads_ready_source');

        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->dropColumn(['state', 'received_bytes', 'kept_by_teme', 'copied_pictures', 'site_pictures']);
            $table->unique('source_id');
        });
    }
};

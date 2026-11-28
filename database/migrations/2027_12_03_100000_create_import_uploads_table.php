<?php

/**
 * Migration: the copy of an old site's uploads folder kept for its import.
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
 * The uploads folder (or a zip of it) an import reads its pictures from
 * before the old site. One copy belongs to one site's record, so a
 * catch-up reuses it. A zip uploaded in the browser is kept on the private
 * disk; a folder or zip named by the operator is read in place and is
 * never deleted by Teme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('source_id')->unique();
            $table->string('kind', 16);
            $table->text('path');
            $table->string('name', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('attached_by')->nullable();
            $table->timestampsTz();

            $table->foreign('source_id')->references('id')->on('import_sources');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_uploads
                ADD CONSTRAINT import_uploads_kind_check CHECK (kind IN ('zip', 'folder')),
                ADD CONSTRAINT import_uploads_path_check CHECK (char_length(path) BETWEEN 1 AND 4096),
                ADD CONSTRAINT import_uploads_name_check CHECK (char_length(name) BETWEEN 1 AND 255)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_uploads');
    }
};

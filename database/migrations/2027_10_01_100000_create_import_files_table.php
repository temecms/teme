<?php

/**
 * Migration: creates import files, the WordPress export files uploaded to import from.
 *
 * @package Teme
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A WordPress export file uploaded in pieces, read once into import file rows,
 * then imported like a site connected live (docs/architecture/import.md). The
 * bytes live on the private local disk, never in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_id')->nullable()->constrained('import_sources')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('name');
            $table->bigInteger('size_bytes');
            $table->bigInteger('received_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->text('state')->default('uploading');
            $table->text('refused')->nullable();
            $table->integer('read_items')->default(0);
            $table->text('site_address')->nullable();
            $table->text('wxr_version')->nullable();
            $table->timestampTz('made_at')->nullable();
            $table->jsonb('report')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_files
                ADD CONSTRAINT import_files_name_check CHECK (char_length(name) BETWEEN 1 AND 255),
                ADD CONSTRAINT import_files_size_check CHECK (size_bytes > 0),
                ADD CONSTRAINT import_files_received_check CHECK (received_bytes BETWEEN 0 AND size_bytes),
                ADD CONSTRAINT import_files_state_check CHECK (state IN ('uploading', 'uploaded', 'reading', 'ready', 'refused')),
                ADD CONSTRAINT import_files_refused_check CHECK ((state = 'refused') = (refused IS NOT NULL)),
                ADD CONSTRAINT import_files_read_items_check CHECK (read_items >= 0),
                ADD CONSTRAINT import_files_report_check CHECK (report IS NULL OR jsonb_typeof(report) = 'object')
            SQL);

        DB::statement("CREATE INDEX import_files_unfinished_index ON import_files (uploaded_by, name, size_bytes) WHERE state = 'uploading'");

        Schema::create('import_file_rows', function (Blueprint $table): void {
            $table->foreignUlid('file_id')->constrained('import_files')->cascadeOnDelete();
            $table->text('list');
            $table->bigInteger('object_id');
            $table->jsonb('payload');
            $table->primary(['file_id', 'list', 'object_id']);
        });

        DB::statement("ALTER TABLE import_file_rows ADD CONSTRAINT import_file_rows_payload_check CHECK (jsonb_typeof(payload) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_file_rows');
        Schema::dropIfExists('import_files');
    }
};

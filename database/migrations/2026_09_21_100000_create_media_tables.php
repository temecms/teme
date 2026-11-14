<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * docs/architecture/media.md, "The schema": one row per item, variants in a
 * JSONB column on it (study K, §4), and the maintained counts behind the
 * library's tabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->string('mime_type', 127);
            $table->string('extension', 10);
            $table->string('original_filename', 255);
            $table->string('name', 80);
            $table->string('original_path', 255);
            $table->unsignedInteger('file_version')->default(1);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('animated')->default(false);
            $table->string('title', 255);
            $table->text('alt')->nullable();
            $table->text('caption')->nullable();
            $table->string('credit', 255)->nullable();
            $table->unsignedSmallInteger('focal_x')->default(50);
            $table->unsignedSmallInteger('focal_y')->default(50);
            $table->jsonb('variants')->default('{}');
            $table->unsignedInteger('variant_set')->default(0);
            $table->string('processing', 16)->default('pending');
            $table->text('processing_error')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement("ALTER TABLE media ADD CONSTRAINT media_kind_check CHECK (kind IN ('image', 'video', 'audio', 'document'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_processing_check CHECK (processing IN ('pending', 'ready', 'failed'))");
        DB::statement('ALTER TABLE media ADD CONSTRAINT media_focal_check CHECK (focal_x <= 100 AND focal_y <= 100)');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_variants_object CHECK (jsonb_typeof(variants) = 'object')");

        // Duplicate detection and regeneration. The library's own indexes
        // arrive with the queries that use them, in 1.4b and 1.4c.
        DB::statement('CREATE INDEX media_sha256 ON media (sha256) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX media_variant_set ON media (variant_set, id) WHERE kind = 'image'");
        DB::statement('CREATE INDEX media_trash ON media (deleted_at, id) WHERE deleted_at IS NOT NULL');

        Schema::create('media_counts', function (Blueprint $table): void {
            $table->string('kind', 16);
            $table->string('bucket', 8);
            $table->bigInteger('count')->default(0);

            $table->primary(['kind', 'bucket']);
        });

        DB::statement("ALTER TABLE media_counts ADD CONSTRAINT media_counts_bucket_check CHECK (bucket IN ('live', 'trash'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('media_counts');
        Schema::dropIfExists('media');
    }
};

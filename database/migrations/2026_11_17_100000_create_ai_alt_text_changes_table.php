<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The one exception to the AI activity record keeping figures only
 * (I-1312): when a person uses AI's alt text for a library image, the alt
 * text from before and after, so an administrator can put the old one back.
 * One row per used suggestion, gone with its activity row (12 months) or
 * with the image. Written only by RecordAltTextChange.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_alt_text_changes', function (Blueprint $table): void {
            $table->ulid('activity_id');
            $table->primary('activity_id');
            $table->foreign('activity_id')->references('id')->on('ai_activity')->cascadeOnDelete();
            $table->foreignUlid('media_id')->constrained('media')->cascadeOnDelete();
            $table->text('alt_before')->nullable();
            $table->text('alt_after');
            $table->timestampTz('created_at');

            // Deleting an image finds its changes without a scan.
            $table->index('media_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_alt_text_changes
                ADD CONSTRAINT ai_alt_text_changes_alt_check CHECK (
                    char_length(alt_after) BETWEEN 1 AND 1000
                    AND (alt_before IS NULL OR char_length(alt_before) BETWEEN 1 AND 1000)
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_alt_text_changes');
    }
};

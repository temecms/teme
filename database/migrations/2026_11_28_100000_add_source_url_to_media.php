<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Where a downloaded file came from, kept as provenance (I-453): another
 * site's address without its query, never drawn as a link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->text('source_url')->nullable();
        });

        DB::statement("ALTER TABLE media ADD CONSTRAINT media_source_url_check CHECK (source_url IS NULL OR (source_url ~ '^https?://[^/?#]' AND length(source_url) <= 2048))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_source_url_check');

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('source_url');
        });
    }
};

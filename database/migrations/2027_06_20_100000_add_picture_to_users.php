<?php

/**
 * An account's picture (I-3410): the ID of an image in Media, never an
 * address, so a new domain changes nothing. Deleting the image for good
 * clears it; until then a trashed image is simply not shown.
 *
 * @package Teme
 * @since   0.8.0-alpha.1
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('picture_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->index('picture_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['picture_media_id']);
            $table->dropIndex(['picture_media_id']);
            $table->dropColumn('picture_media_id');
        });
    }
};

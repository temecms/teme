<?php

/**
 * Migration: adds restored from to revisions.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
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
        Schema::table('content_revisions', function (Blueprint $table): void {
            // Which revision this one was taken from, when kind is 'restore'.
            $table->foreignUlid('restored_from_id')->nullable()->constrained('content_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restored_from_id');
        });
    }
};

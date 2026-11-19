<?php

/**
 * Migration: adds uploaded for to media.
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
 * The item a file was uploaded for (I-553), apart from where it is used:
 * the importer sets it from WordPress's attachment parent. Deleting the
 * item leaves its files, no longer attached to anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignUlid('uploaded_for')->nullable()->constrained('contents')->nullOnDelete();
        });

        // An item's own uploads, and the foreign key's deletes.
        DB::statement('CREATE INDEX media_uploaded_for ON media (uploaded_for) WHERE uploaded_for IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_for');
        });
    }
};

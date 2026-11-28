<?php

/**
 * Migration: the folder inside a copy's zip (or folder) that holds the uploads,
 * found when the copy is finished or attached, so pictures are matched beneath it.
 *
 * @package Teme
 * @since   next
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
        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->string('prefix', 512)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('import_uploads', function (Blueprint $table): void {
            $table->dropColumn('prefix');
        });
    }
};

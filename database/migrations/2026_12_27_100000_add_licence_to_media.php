<?php

/**
 * Migration: adds licence to media.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A file's licence, beside its credit (I-236): the terms it may be used
 * under, as the person who added it wrote them. A typed column, never a
 * meta row. Adding a nullable column rewrites nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('licence', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('licence');
        });
    }
};

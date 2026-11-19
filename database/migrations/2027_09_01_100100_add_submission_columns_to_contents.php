<?php

/**
 * Migration: adds the submission marks to contents.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What marks an item a visitor sent from a plugin's public form: the code of
 * the plugin it came through, and who sent it as the visitor gave it. Both
 * are null for every other item, so adding them rewrites nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('submitted_via', 64)->nullable();
            $table->string('submitted_from', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['submitted_via', 'submitted_from']);
        });
    }
};

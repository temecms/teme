<?php

/**
 * Migration: adds account states and restrict authors.
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
    private const array AUTHORED = ['contents', 'content_revisions'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
        });

        // I-102: deleting a person must never silently strip their name from
        // what they wrote. DeleteUser reassigns first; the database refuses
        // anything that tries otherwise.
        foreach (self::AUTHORED as $authored) {
            Schema::table($authored, function (Blueprint $table): void {
                $table->dropForeign(['author_id']);
                $table->foreign('author_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::AUTHORED as $authored) {
            Schema::table($authored, function (Blueprint $table): void {
                $table->dropForeign(['author_id']);
                $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['invited_at', 'suspended_at']);
        });
    }
};

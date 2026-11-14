<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people an item can credit, and each item's bylines in order (I-3366).
 * Two new tables and no row: the release before reads neither, and nothing
 * it reads changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The account this person is, or null: a guest, a news agency, a former member of staff (I-3366).
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            // A person with no account's own; null for an account's person, whose come from the account.
            $table->string('name', 255)->nullable();
            $table->string('slug', 190)->nullable()->unique();
            $table->text('bio')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE people ADD CONSTRAINT people_named CHECK (user_id IS NOT NULL OR (name IS NOT NULL AND slug IS NOT NULL))');

        Schema::create('content_bylines', function (Blueprint $table): void {
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            // Restrict: a person stays while anything credits them (DeletePerson refuses first).
            $table->foreignUlid('person_id')->constrained('people')->restrictOnDelete();
            $table->smallInteger('position');

            $table->primary(['content_id', 'person_id']);
            $table->unique(['content_id', 'position']);
            $table->index(['person_id', 'content_id']);
        });

        DB::statement('ALTER TABLE content_bylines ADD CONSTRAINT content_bylines_position CHECK (position >= 0 AND position < 20)');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_bylines');
        Schema::dropIfExists('people');
    }
};

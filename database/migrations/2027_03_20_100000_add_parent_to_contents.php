<?php

/**
 * Migration: adds parent to contents.
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
 * A parent and a position on every item (I-3363). A nullable column and one
 * with a constant default rewrite nothing; NOT VALID keeps the constraints'
 * first check off the brief ACCESS EXCLUSIVE lock, and the next migration
 * validates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ADD COLUMN takes ACCESS EXCLUSIVE briefly; on a busy site fail fast rather than queue every reader behind it.
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('contents', function (Blueprint $table): void {
            // The item it sits under (I-3363); null at the top, and for every type that doesn't nest.
            $table->ulid('parent_id')->nullable();
            // Its place among its parent's children, lowest first, then by ID.
            $table->integer('position')->default(0);
        });

        DB::statement('ALTER TABLE contents ADD CONSTRAINT contents_parent_fk FOREIGN KEY (parent_id) REFERENCES contents (id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE contents ADD CONSTRAINT contents_parent_not_self CHECK (parent_id IS NULL OR parent_id <> id) NOT VALID');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_parent_not_self');
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_parent_fk');

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn(['parent_id', 'position']);
        });
    }
};

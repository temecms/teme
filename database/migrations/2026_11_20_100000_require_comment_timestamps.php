<?php

/**
 * Migration: requires comment timestamps.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A comment always has a creation and an update time. An item's
 * `comment_stats.last_comment_at` copies its newest approved comment's
 * `created_at`, and that table's check requires `last_comment_id` and
 * `last_comment_at` to be both set or both null, so an approved comment with
 * no `created_at` would make its item's counts refuse to update. Every
 * Operation sets both columns, and no row holds a null in either, so this
 * changes no data; PostgreSQL refuses to add the constraint if one ever did.
 * It closes the way a bulk load, such as the importer's, could slip one in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE comments ALTER COLUMN created_at SET NOT NULL, ALTER COLUMN updated_at SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE comments ALTER COLUMN created_at DROP NOT NULL, ALTER COLUMN updated_at DROP NOT NULL');
    }
};

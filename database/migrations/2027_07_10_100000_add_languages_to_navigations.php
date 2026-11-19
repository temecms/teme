<?php

/**
 * Migration: adds languages to navigations.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * docs/proposals/multilingual-readers.md: a menu carries the language it is
 * written in, NULL for the site's default, as items and terms do (I-3360);
 * and a menu item may be a language switcher, which expands into one link
 * per version of the page being drawn, with the few options a theme reads.
 * Each column is nullable with no default, so no existing row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Inside the migration's own transaction: a busy table makes it give up, not queue every read behind it.
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE navigations ADD COLUMN language varchar(35) NULL');
        DB::statement("ALTER TABLE navigations ADD CONSTRAINT navigations_language_check CHECK (language ~ '^[a-z]{2,3}(-[A-Z]{2})?$')");
        DB::statement('ALTER TABLE navigation_items ADD COLUMN options jsonb NULL');
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_kind_check');
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_kind_check CHECK (kind IN ('content', 'term', 'home', 'url', 'heading', 'languages'))");
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_languages_check CHECK (kind <> 'languages' OR (target_id IS NULL AND url IS NULL))");
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_options_check CHECK (options IS NULL OR (kind = 'languages' AND jsonb_typeof(options) = 'object'))");
    }

    public function down(): void
    {
        // PostgreSQL refuses the old check while a switcher is stored, so a
        // rollback never drops one unasked.
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_options_check');
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_languages_check');
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_kind_check');
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_kind_check CHECK (kind IN ('content', 'term', 'home', 'url', 'heading'))");
        DB::statement('ALTER TABLE navigation_items DROP COLUMN options');
        DB::statement('ALTER TABLE navigations DROP CONSTRAINT navigations_language_check');
        DB::statement('ALTER TABLE navigations DROP COLUMN language');
    }
};

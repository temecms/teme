<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * I-2628: a menu item may be a heading, which leads nowhere and opens the
 * items under it. As for every other kind, its shape is held here: a label
 * of its own, and no target or address.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_kind_check');
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_kind_check CHECK (kind IN ('content', 'term', 'home', 'url', 'heading'))");
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_heading_check CHECK (kind <> 'heading' OR (target_id IS NULL AND url IS NULL AND label IS NOT NULL AND btrim(label) <> ''))");
    }

    public function down(): void
    {
        // PostgreSQL refuses the old check while a heading is stored, so a
        // rollback never drops headings, and the items under them, unasked.
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_heading_check');
        DB::statement('ALTER TABLE navigation_items DROP CONSTRAINT navigation_items_kind_check');
        DB::statement("ALTER TABLE navigation_items ADD CONSTRAINT navigation_items_kind_check CHECK (kind IN ('content', 'term', 'home', 'url'))");
    }
};

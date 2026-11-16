<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A fourth place for site code (I-3127): "placed", code drawn nowhere by
 * itself, only where a region's Custom HTML card places it. Additive: the
 * check accepts one more value and every existing row still fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE site_code_entries DROP CONSTRAINT site_code_entries_placement_check');
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_placement_check CHECK (placement IN ('head', 'body-start', 'body-end', 'placed'))");
    }

    public function down(): void
    {
        // A placed entry goes off, at the end of the body, so the narrower check never fails on data.
        DB::table('site_code_entries')->where('placement', 'placed')->update(['placement' => 'body-end', 'enabled' => false]);
        DB::statement('ALTER TABLE site_code_entries DROP CONSTRAINT site_code_entries_placement_check');
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_placement_check CHECK (placement IN ('head', 'body-start', 'body-end'))");
    }
};

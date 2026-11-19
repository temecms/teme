<?php

/**
 * Migration: adds origins to site code entries.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The hosts a site-code entry also connects to (I-3375), as its administrator
 * declared them: a JSONB list on the entry's own row, deleted with it, which
 * the public site's content security policy allows beside what the code
 * names itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A constant default: PostgreSQL 18 adds the column without rewriting the table.
        DB::statement("ALTER TABLE site_code_entries ADD COLUMN origins jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement("ALTER TABLE site_code_entries ADD CONSTRAINT site_code_entries_origins_list CHECK (jsonb_typeof(origins) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE site_code_entries DROP CONSTRAINT IF EXISTS site_code_entries_origins_list');
        DB::statement('ALTER TABLE site_code_entries DROP COLUMN IF EXISTS origins');
    }
};

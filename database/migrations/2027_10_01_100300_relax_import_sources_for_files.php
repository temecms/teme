<?php

/**
 * Migration: lets a site to import come from an uploaded export file, with
 * no API root, plugin, API version, probe or password until it is connected live.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A site read from an export file has only its address until someone connects
 * it live, which fills the four together (docs/architecture/import.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE import_sources
                ALTER COLUMN api_root DROP NOT NULL,
                ALTER COLUMN plugin_version DROP NOT NULL,
                ALTER COLUMN api_version DROP NOT NULL,
                ALTER COLUMN probed_at DROP NOT NULL,
                DROP CONSTRAINT import_sources_forgotten_check,
                ADD CONSTRAINT import_sources_forgotten_check CHECK (api_root IS NULL OR ((credential IS NULL) = (forgotten_at IS NOT NULL))),
                ADD CONSTRAINT import_sources_live_check CHECK (
                    (api_root IS NULL) = (plugin_version IS NULL)
                    AND (api_root IS NULL) = (api_version IS NULL)
                    AND (api_root IS NULL) = (probed_at IS NULL)
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE import_sources
                DROP CONSTRAINT import_sources_live_check,
                DROP CONSTRAINT import_sources_forgotten_check,
                ADD CONSTRAINT import_sources_forgotten_check CHECK ((credential IS NULL) = (forgotten_at IS NOT NULL)),
                ALTER COLUMN api_root SET NOT NULL,
                ALTER COLUMN plugin_version SET NOT NULL,
                ALTER COLUMN api_version SET NOT NULL,
                ALTER COLUMN probed_at SET NOT NULL
            SQL);
    }
};

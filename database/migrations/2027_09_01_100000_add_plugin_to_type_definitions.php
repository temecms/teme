<?php

/**
 * Migration: adds plugin to the type, taxonomy and field definitions.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A plugin's own content types (docs/architecture/plugins.md, *Content types
 * from a plugin*): each stored type, taxonomy and field names the plugin it
 * came from by its Composer name, or none for the site's own. A row whose
 * plugin isn't enabled is kept, items and values with it, and left out of the
 * registries until the plugin is enabled again. The plugin's row keeps the
 * types file it last applied and what that changed. Only additions: every
 * row already written is the site's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['content_type_definitions', 'taxonomy_definitions', 'field_definitions'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN plugin varchar(214) NULL");
            DB::statement("CREATE INDEX {$table}_plugin_index ON {$table} (plugin) WHERE plugin IS NOT NULL");
        }

        DB::statement("ALTER TABLE plugins ADD COLUMN types jsonb NOT NULL DEFAULT '{}'");
        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_types_object CHECK (jsonb_typeof(types) = 'object')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE plugins DROP COLUMN IF EXISTS types');

        foreach (['content_type_definitions', 'taxonomy_definitions', 'field_definitions'] as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_plugin_index");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS plugin");
        }
    }
};

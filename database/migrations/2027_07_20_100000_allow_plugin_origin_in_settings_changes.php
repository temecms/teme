<?php

/**
 * Migration: allows plugin origin in settings changes.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A plugin writes its own settings for a person (docs/proposals/plugin-sockets-1.md),
 * and History says through which plugin: origin "plugin:{code}", beside the
 * origins there were, in a column wide enough for any plugin's code. No
 * row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE settings_changes ALTER COLUMN origin TYPE varchar(64)');
        DB::statement('ALTER TABLE settings_changes DROP CONSTRAINT settings_changes_origin_check');
        DB::statement("ALTER TABLE settings_changes ADD CONSTRAINT settings_changes_origin_check CHECK (origin IN ('human', 'ai', 'import', 'system') OR origin ~ '^plugin:[a-z0-9][a-z0-9-]{1,39}$')");
    }

    public function down(): void
    {
        DB::statement("UPDATE settings_changes SET origin = 'system' WHERE origin LIKE 'plugin:%'");
        DB::statement('ALTER TABLE settings_changes DROP CONSTRAINT settings_changes_origin_check');
        DB::statement("ALTER TABLE settings_changes ADD CONSTRAINT settings_changes_origin_check CHECK (origin IN ('human', 'ai', 'import', 'system'))");
        DB::statement('ALTER TABLE settings_changes ALTER COLUMN origin TYPE varchar(16)');
    }
};

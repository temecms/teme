<?php

/**
 * Migration: records which plugin added a redirect.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A redirect a plugin added carries the plugin's code, so a plugin lists and
 * removes only its own and Tools › Redirects can say who added it. The code is
 * kept as written, with no foreign key: a plugin's redirects outlive it being
 * removed from the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table): void {
            $table->text('plugin')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE redirects
                DROP CONSTRAINT redirects_origin_check,
                ADD CONSTRAINT redirects_origin_check CHECK (origin IN ('manual', 'import', 'plugin')),
                ADD CONSTRAINT redirects_plugin_check CHECK ((origin = 'plugin') = (plugin IS NOT NULL))
            SQL);

        DB::statement('CREATE INDEX redirects_plugin_index ON redirects (plugin) WHERE plugin IS NOT NULL');
    }

    public function down(): void
    {
        // The older check allows no plugin's redirect, so those go first.
        DB::table('redirects')->where('origin', 'plugin')->delete();
        DB::statement('DROP INDEX IF EXISTS redirects_plugin_index');
        DB::statement(<<<'SQL'
            ALTER TABLE redirects
                DROP CONSTRAINT redirects_plugin_check,
                DROP CONSTRAINT redirects_origin_check,
                ADD CONSTRAINT redirects_origin_check CHECK (origin IN ('manual', 'import'))
            SQL);

        Schema::table('redirects', function (Blueprint $table): void {
            $table->dropColumn('plugin');
        });
    }
};

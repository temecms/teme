<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Other old addresses of a WordPress site (staging hosts and former domains),
 * kept on its import source so every run converts links and images on them
 * as it does on the home host (docs/proposals/import-v1-fixes.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sources', function (Blueprint $table): void {
            $table->jsonb('other_hosts')->default('[]');
        });

        DB::statement("ALTER TABLE import_sources ADD CONSTRAINT import_sources_other_hosts_array CHECK (jsonb_typeof(other_hosts) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_sources DROP CONSTRAINT IF EXISTS import_sources_other_hosts_array');

        Schema::table('import_sources', function (Blueprint $table): void {
            $table->dropColumn('other_hosts');
        });
    }
};

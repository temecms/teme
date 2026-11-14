<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The text-search configuration each item's search_tsv was built with
 * (I-435), so a change of site language knows which rows to rebuild. Rows
 * already searchable were built with `english`, config/search.php's default,
 * which the site language replaces; a site that set another dictionary sets
 * its language afterwards, which rebuilds search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('search_config', 32)->nullable();
        });

        DB::table('contents')->whereNotNull('search_tsv')->update(['search_config' => 'english']);
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('search_config');
        });
    }
};

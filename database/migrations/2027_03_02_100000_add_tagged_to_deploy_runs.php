<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Whether each deploy's version is a release (I-?? draft), so a roll back and a backup's manifest can tell 0.1.0 untagged from v0.1.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_runs', function (Blueprint $table): void {
            $table->boolean('tagged')->default(false);
        });

        // Until v0.1.0 is tagged, 0.1.0 only ever meant an untagged build.
        DB::statement("UPDATE deploy_runs SET tagged = (version <> '0.1.0')");
    }

    public function down(): void
    {
        Schema::table('deploy_runs', function (Blueprint $table): void {
            $table->dropColumn('tagged');
        });
    }
};

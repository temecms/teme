<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How many live items carry each term, maintained like published_count. The
 * list query uses it to choose its plan for a category or tag filter
 * (docs/architecture/content-management.md, "Lists").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->unsignedBigInteger('working_count')->default(0);
        });

        DB::statement(<<<'SQL'
            UPDATE terms t SET working_count = x.n
            FROM (SELECT term_id, count(*) AS n FROM content_terms WHERE scope = 'working' GROUP BY term_id) x
            WHERE x.term_id = t.id
            SQL);
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->dropColumn('working_count');
        });
    }
};

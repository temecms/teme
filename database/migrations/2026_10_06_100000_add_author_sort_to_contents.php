<?php

/**
 * The author's name folded for sorting, so the lists can sort by author with
 * an index range scan like every other list sort (I-204, study I).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('author_sort', 255)->default('');
        });

        DB::statement('UPDATE contents SET author_sort = left(lower(trim(u.name)), 255) FROM users u WHERE u.id = contents.author_id');

        DB::statement('CREATE INDEX contents_list_author_sort ON contents (type, author_sort, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX contents_list_status_author_sort ON contents (type, status, author_sort, id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contents_list_status_author_sort');
        DB::statement('DROP INDEX IF EXISTS contents_list_author_sort');

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('author_sort');
        });
    }
};

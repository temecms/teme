<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Each person's slug, for their author address (`author/{slug}`), made from
 * their name as the User model's creating event makes it, and unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('slug', 200)->collation('C')->nullable();
        });

        $taken = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $base = Str::slug((string) $user->name) ?: 'author';
            $slug = $base;

            for ($n = 2; isset($taken[$slug]); $n++) {
                $slug = $base.'-'.$n;
            }

            $taken[$slug] = true;
            DB::table('users')->where('id', $user->id)->update(['slug' => $slug]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('slug', 200)->collation('C')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};

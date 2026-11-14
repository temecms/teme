<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per person, typed values in one JSONB column (rule 2): no
        // key-value table.
        Schema::table('users', function (Blueprint $table): void {
            $table->jsonb('preferences')->default('{}');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_preferences_object CHECK (jsonb_typeof(preferences) = 'object')");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * One row per plugin that has ever been enabled (I-3353): whether it is
 * enabled now, why not, and what the person agreed to for which version.
 * No row means never enabled. Only the two reasons a write makes are
 * stored; every other state is worked out from the plugin on disk. Its
 * listeners' recent faults and stopped parts are JSONB objects on the row,
 * never rows of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 214)->unique();              // the Composer package name
            $table->string('code', 40)->unique();
            $table->string('state', 16);                         // enabled | disabled
            $table->string('disabled_reason', 16)->nullable();   // by_user | quarantined
            $table->string('version', 64);                       // the version consent was given for
            $table->jsonb('consent');                            // {"capabilities": [...], "hosts": [...], "hooks": [...]}
            $table->foreignId('consented_by')->nullable()->constrained('users')->nullOnDelete(); // null: the operator
            $table->timestampTz('consented_at');
            $table->timestampTz('enabled_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete(); // null: the operator, or Teme
            $table->jsonb('faults')->default('{}');              // listener id => {hook, count, first_at, last_at, error}
            $table->jsonb('quarantine')->default('{}');          // listener id => {hook, version, at, error}
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_state CHECK (state IN ('enabled', 'disabled'))");
        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_reason CHECK ((state = 'enabled' AND disabled_reason IS NULL) OR (state = 'disabled' AND disabled_reason IN ('by_user', 'quarantined')))");
        DB::statement("ALTER TABLE plugins ADD CONSTRAINT plugins_objects CHECK (jsonb_typeof(consent) = 'object' AND jsonb_typeof(faults) = 'object' AND jsonb_typeof(quarantine) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};

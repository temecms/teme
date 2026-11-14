<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The WordPress sites Teme imports from: one row a site, keyed by
 * WordPress's own home address, with the API root that answered, the
 * plugin's version, and the application password, encrypted, until the
 * site is forgotten (I-1708).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Compared byte for byte, as it was stored.
            $table->text('address')->collation('C')->unique();
            $table->text('api_root');
            $table->string('user_name', 60)->nullable();
            // Laravel's encrypted cast: an encrypted string, never serialized.
            $table->text('credential')->nullable();
            $table->string('plugin_version', 40);
            $table->smallInteger('api_version');
            $table->timestampTz('probed_at');
            $table->timestampTz('forgotten_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_sources
                ADD CONSTRAINT import_sources_address_check CHECK (char_length(address) BETWEEN 1 AND 2000),
                ADD CONSTRAINT import_sources_api_root_check CHECK (char_length(api_root) BETWEEN 1 AND 2100),
                ADD CONSTRAINT import_sources_api_version_check CHECK (api_version > 0),
                ADD CONSTRAINT import_sources_forgotten_check CHECK ((credential IS NULL) = (forgotten_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_sources');
    }
};

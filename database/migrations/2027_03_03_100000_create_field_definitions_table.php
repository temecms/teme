<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The fields a site adds to its types, in the admin or by teme:types:import
 * (docs/studies/content-and-fields.md §5.3): one row per field, keyed by the
 * ULID its values are stored under. A removed field keeps its row, so its
 * values keep a meaning. Code's fields are never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_definitions', function (Blueprint $table): void {
            $table->char('key', 26)->primary();
            $table->string('content_type', 32);
            $table->string('handle', 64);
            $table->integer('position');
            $table->jsonb('definition');
            $table->timestampTz('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['content_type', 'position']);
        });

        DB::statement("ALTER TABLE field_definitions ADD CONSTRAINT field_definitions_key_check CHECK (key ~ '^[0-9A-HJKMNP-TV-Z]{26}$')");
        DB::statement("ALTER TABLE field_definitions ADD CONSTRAINT field_definitions_handle_check CHECK (handle ~ '^[a-z][a-z0-9_]{0,63}$')");
        DB::statement("ALTER TABLE field_definitions ADD CONSTRAINT field_definitions_definition_check CHECK (jsonb_typeof(definition) = 'object' AND COALESCE(jsonb_typeof(definition -> 'fieldType'), '') = 'string')");
        // A live field's handle is unique on its type; a removed one's is checked by the Operations.
        DB::statement('CREATE UNIQUE INDEX field_definitions_live_handle_unique ON field_definitions (content_type, handle) WHERE removed_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }
};

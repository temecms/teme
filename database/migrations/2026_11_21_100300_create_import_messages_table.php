<?php

/**
 * Migration: creates import messages table.
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

/*
 * The warnings and errors a run met: each tied to its object's map row, or
 * to the run alone, with a code, the words people read and a flat context
 * that never holds personal data. An object's are replaced each time it is
 * imported again (I-345).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('import_runs')->cascadeOnDelete();
            $table->foreignUlid('map_id')->nullable()->constrained('import_map')->cascadeOnDelete();
            $table->string('level', 8);
            $table->string('code', 80);
            $table->text('message');
            $table->jsonb('context')->default('{}');
            $table->timestampTz('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_messages
                ADD CONSTRAINT import_messages_level_check CHECK (level IN ('warning', 'error')),
                ADD CONSTRAINT import_messages_code_check CHECK (code ~ '^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$'),
                ADD CONSTRAINT import_messages_message_check CHECK (char_length(message) BETWEEN 1 AND 1000),
                ADD CONSTRAINT import_messages_context_check CHECK (jsonb_typeof(context) = 'object')
            SQL);

        DB::statement('CREATE INDEX import_messages_run_index ON import_messages (run_id, level)');
        DB::statement('CREATE INDEX import_messages_map_index ON import_messages (map_id) WHERE map_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_messages');
    }
};

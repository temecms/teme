<?php

/**
 * Migration: the engine ID each import reader item keeps, by the site it
 * came from and the reader that read it, so a re-import of a reordered
 * export still finds each item's rows and mapped objects.
 *
 * @package Teme
 * @since   1.8.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the table, with the checks that keep an ID inside its counter.
     */
    public function up(): void
    {
        Schema::create('import_reader_ids', function (Blueprint $table): void {
            $table->text('address');
            $table->text('reader');
            $table->string('object_type', 40);
            $table->text('source_id');
            $table->string('counter', 40);
            $table->bigInteger('object_id');
            $table->primary(['address', 'reader', 'object_type', 'source_id']);
            $table->unique(['address', 'reader', 'counter', 'object_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE import_reader_ids
                ADD CONSTRAINT import_reader_ids_address_check CHECK (char_length(address) BETWEEN 1 AND 2048),
                ADD CONSTRAINT import_reader_ids_reader_check CHECK (char_length(reader) BETWEEN 1 AND 200),
                ADD CONSTRAINT import_reader_ids_source_id_check CHECK (char_length(source_id) BETWEEN 1 AND 500),
                ADD CONSTRAINT import_reader_ids_object_id_check CHECK (object_id <> 0)
            SQL);
    }

    /**
     * Drops the table.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_reader_ids');
    }
};

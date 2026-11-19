<?php

/**
 * Migration: creates content type definitions table.
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
 * The content types a site makes for itself, in the admin or by
 * teme:types:import (I-411): one row per type, its definition in JSONB.
 * Code's types are never stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_type_definitions', function (Blueprint $table): void {
            $table->string('key', 32)->primary();
            $table->jsonb('definition');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE content_type_definitions ADD CONSTRAINT content_type_definitions_key_check CHECK (key ~ '^[a-z][a-z0-9_]{0,31}$')");
        // COALESCE: a CHECK that comes out NULL passes, and a missing segment's type is NULL.
        DB::statement("ALTER TABLE content_type_definitions ADD CONSTRAINT content_type_definitions_definition_check CHECK (jsonb_typeof(definition) = 'object' AND COALESCE(jsonb_typeof(definition -> 'segment'), '') = 'string')");
        DB::statement("CREATE UNIQUE INDEX content_type_definitions_segment_unique ON content_type_definitions ((definition ->> 'segment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('content_type_definitions');
    }
};

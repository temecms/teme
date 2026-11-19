<?php

/**
 * Migration: creates media moves table.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every run of teme:media:move (I-3456): one row a run, never one a file,
 * with its direction, how far it got and what it found. Only the three move
 * Operations write it, for the operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_moves', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->string('from_location', 32);
            $table->string('to_location', 32);
            $table->jsonb('roles');
            $table->string('scope', 16);
            $table->timestampTz('changed_since')->nullable();
            $table->boolean('deep')->default(false);
            $table->jsonb('site_disks');
            $table->string('state', 16);
            $table->char('cursor', 26)->nullable();
            $table->integer('items_total');
            $table->integer('items_done')->default(0);
            $table->integer('files_copied')->default(0);
            $table->integer('files_present')->default(0);
            $table->integer('files_deleted')->default(0);
            $table->bigInteger('bytes_copied')->default(0);
            $table->integer('holes')->default(0);
            $table->integer('failures')->default(0);
            $table->jsonb('problems')->default(DB::raw("'[]'::jsonb"));
            $table->text('message')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['kind', 'from_location', 'to_location', 'started_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE media_moves
                ADD CONSTRAINT media_moves_kind_check CHECK (kind IN ('copy', 'delete_source')),
                ADD CONSTRAINT media_moves_scope_check CHECK (scope IN ('all', 'changed')),
                ADD CONSTRAINT media_moves_state_check CHECK (state IN ('running', 'stopped', 'finished')),
                ADD CONSTRAINT media_moves_changed_check CHECK ((scope = 'changed') = (changed_since IS NOT NULL)),
                ADD CONSTRAINT media_moves_direction_check CHECK (from_location <> to_location),
                ADD CONSTRAINT media_moves_roles_check CHECK (jsonb_typeof(roles) = 'array'),
                ADD CONSTRAINT media_moves_problems_check CHECK (jsonb_typeof(problems) = 'array'),
                ADD CONSTRAINT media_moves_site_disks_check CHECK (jsonb_typeof(site_disks) = 'object'),
                ADD CONSTRAINT media_moves_finished_check CHECK ((state = 'finished') = (finished_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('media_moves');
    }
};

<?php

/**
 * Migration: creates inbox items table.
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
 * The inbox (I-221): each person's background results, read and unread,
 * kept 90 days. The body is JSONB (rule 4): a title, a text and a link kept
 * as a route's name, never an address (rule 9). Only the inbox Operations
 * write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('owner', 64)->default('core');
            $table->jsonb('body');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inbox_items
                ADD CONSTRAINT inbox_items_body_check CHECK (jsonb_typeof(body) = 'object' AND jsonb_typeof(body -> 'title') IS NOT DISTINCT FROM 'string')
            SQL);

        // A person's inbox, newest first, a keyset page at a time (I-140); and their removal without a scan.
        DB::statement('CREATE INDEX inbox_items_user_index ON inbox_items (user_id, created_at DESC, id DESC)');
        // A person's unread count.
        DB::statement('CREATE INDEX inbox_items_unread_index ON inbox_items (user_id) WHERE read_at IS NULL');
        // The daily prune of items that arrived 90 days ago.
        DB::statement('CREATE INDEX inbox_items_created_index ON inbox_items (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};

<?php

/**
 * Migration: adds save kind to content revisions.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Slice 1.5c: a revision written by a plain save is a `save`; a `checkpoint`
 * is now one gathered from an autosave. 1.5b added `kind` with the default
 * `checkpoint`, before checkpoints existed, so every revision so far is
 * really a save.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE content_revisions DROP CONSTRAINT content_revisions_kind_check');

        // Every revision so far came from CreateDraft or SaveDraft, which never
        // checkpointed: they took the old default.
        DB::statement("UPDATE content_revisions SET kind = 'save' WHERE kind = 'checkpoint'");
        DB::statement("ALTER TABLE content_revisions ALTER COLUMN kind SET DEFAULT 'save'");
        DB::statement("ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_kind_check CHECK (kind IN ('save', 'checkpoint', 'publish', 'restore', 'import', 'ai'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE content_revisions DROP CONSTRAINT content_revisions_kind_check');
        DB::statement("UPDATE content_revisions SET kind = 'checkpoint' WHERE kind = 'save'");
        DB::statement("ALTER TABLE content_revisions ALTER COLUMN kind SET DEFAULT 'checkpoint'");
        DB::statement("ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_kind_check CHECK (kind IN ('checkpoint', 'publish', 'restore', 'import', 'ai'))");
    }
};

<?php

/**
 * Migration: adds plugin to ai activity.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A plugin's AI requests in the record (docs/plans/plugin-sockets-3.md): the
 * plugin that asked, a request about no item (subject none, no subject ID),
 * and the outcome answered, which only a plugin's request ends with. Only
 * additions: every row already written passes the wider checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_activity ADD COLUMN plugin varchar(64) NULL');
        DB::statement('ALTER TABLE ai_activity ALTER COLUMN subject_id DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_activity
                DROP CONSTRAINT ai_activity_subject_type_check,
                ADD CONSTRAINT ai_activity_subject_type_check CHECK (
                    subject_type IN ('content', 'media', 'none') AND ((subject_type = 'none') = (subject_id IS NULL))
                ),
                DROP CONSTRAINT ai_activity_outcome_check,
                ADD CONSTRAINT ai_activity_outcome_check CHECK (outcome IN ('suggested', 'applied', 'discarded', 'failed', 'refused', 'stale', 'answered'))
            SQL);

        // One plugin's requests, newest first: its hourly limit, its filter on Tools › AI activity.
        DB::statement('CREATE INDEX ai_activity_plugin_index ON ai_activity (plugin, created_at DESC) WHERE plugin IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_activity_plugin_index');

        DB::statement(<<<'SQL'
            ALTER TABLE ai_activity
                DROP CONSTRAINT ai_activity_subject_type_check,
                ADD CONSTRAINT ai_activity_subject_type_check CHECK (subject_type IN ('content', 'media')),
                DROP CONSTRAINT ai_activity_outcome_check,
                ADD CONSTRAINT ai_activity_outcome_check CHECK (outcome IN ('suggested', 'applied', 'discarded', 'failed', 'refused', 'stale'))
            SQL);

        DB::statement('ALTER TABLE ai_activity ALTER COLUMN subject_id SET NOT NULL');
        DB::statement('ALTER TABLE ai_activity DROP COLUMN plugin');
    }
};

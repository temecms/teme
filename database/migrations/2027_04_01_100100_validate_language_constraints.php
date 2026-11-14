<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Checks the existing rows against the constraints the previous migration
 * added NOT VALID (I-3360). Each takes SHARE UPDATE EXCLUSIVE, so reads and
 * writes go on while it scans; every existing value is NULL, so each passes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contents VALIDATE CONSTRAINT contents_language_check');
        DB::statement('ALTER TABLE contents VALIDATE CONSTRAINT contents_translation_group_check');
        DB::statement('ALTER TABLE terms VALIDATE CONSTRAINT terms_language_check');
        DB::statement('ALTER TABLE terms VALIDATE CONSTRAINT terms_translation_group_check');
        DB::statement('ALTER TABLE content_revisions VALIDATE CONSTRAINT content_revisions_source_revision_fk');
    }

    public function down(): void
    {
        // A validated constraint needs nothing undone: the previous migration's down() drops it.
    }
};

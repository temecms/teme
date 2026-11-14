<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A language and a translation group on items and terms, the revision a
 * translation came from, and words per language on a settings row (I-3360).
 * Every column is nullable with no default, so no row is rewritten; NULL is
 * the site's default language. NOT VALID keeps each constraint's first check
 * off the brief ACCESS EXCLUSIVE lock, and the next migration validates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Inside the migration's own transaction: a busy table makes it give up, not queue every read behind it.
        DB::statement("SET LOCAL lock_timeout = '5s'");
        // Nullable, with no default: PostgreSQL writes no row to add any of them.
        DB::statement('ALTER TABLE contents ADD COLUMN language varchar(35) NULL');
        DB::statement('ALTER TABLE contents ADD COLUMN translation_group char(26) NULL');
        DB::statement('ALTER TABLE terms ADD COLUMN language varchar(35) NULL');
        DB::statement('ALTER TABLE terms ADD COLUMN translation_group char(26) NULL');
        DB::statement('ALTER TABLE content_revisions ADD COLUMN source_revision_id char(26) NULL');
        DB::statement('ALTER TABLE settings ADD COLUMN translations jsonb NULL');
        // NOT VALID: checked for new rows now, for existing rows by the next migration, without blocking writes.
        // A CHECK that comes out NULL passes: a NULL language is the default (I-3360).
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_language_check CHECK (language ~ '^[a-z]{2,3}(-[A-Z]{2})?$') NOT VALID");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_translation_group_check CHECK (translation_group ~ '^[0-9a-hjkmnp-tv-z]{26}$') NOT VALID");
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_language_check CHECK (language ~ '^[a-z]{2,3}(-[A-Z]{2})?$') NOT VALID");
        DB::statement("ALTER TABLE terms ADD CONSTRAINT terms_translation_group_check CHECK (translation_group ~ '^[0-9a-hjkmnp-tv-z]{26}$') NOT VALID");
        DB::statement('ALTER TABLE content_revisions ADD CONSTRAINT content_revisions_source_revision_fk FOREIGN KEY (source_revision_id) REFERENCES content_revisions (id) ON DELETE SET NULL NOT VALID');
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_translations_object CHECK (translations IS NULL OR (jsonb_typeof(translations) = 'object' AND pg_column_size(translations) <= 65536))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_translations_object');
        DB::statement('ALTER TABLE content_revisions DROP CONSTRAINT IF EXISTS content_revisions_source_revision_fk');
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_translation_group_check');
        DB::statement('ALTER TABLE terms DROP CONSTRAINT IF EXISTS terms_language_check');
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_translation_group_check');
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_language_check');
        DB::statement('ALTER TABLE settings DROP COLUMN IF EXISTS translations');
        DB::statement('ALTER TABLE content_revisions DROP COLUMN IF EXISTS source_revision_id');
        DB::statement('ALTER TABLE terms DROP COLUMN IF EXISTS translation_group');
        DB::statement('ALTER TABLE terms DROP COLUMN IF EXISTS language');
        DB::statement('ALTER TABLE contents DROP COLUMN IF EXISTS translation_group');
        DB::statement('ALTER TABLE contents DROP COLUMN IF EXISTS language');
    }
};

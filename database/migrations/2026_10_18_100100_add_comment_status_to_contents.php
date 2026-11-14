<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Types\ContentType;
use App\Domain\Types\ContentTypeRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Whether each item takes comments: open, closed or hidden (I-274,
 * docs/studies/comments.md §3.3). The column is added with 'open' as its
 * default, which PostgreSQL gives every existing row without rewriting the
 * table; then the rows of types whose new items don't start open, pages and
 * regions today (few rows), are closed; then new rows default to closed, since
 * CreateDraft::start() names each item's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE contents ADD COLUMN comment_status varchar(16) NOT NULL DEFAULT 'open'");

        $open = array_values(array_map(
            fn (ContentType $type): string => $type->key,
            array_filter(app(ContentTypeRegistry::class)->all(), fn (ContentType $type): bool => $type->comments === Discussion::Open),
        ));

        DB::table('contents')
            ->when($open !== [], fn ($query) => $query->whereNotIn('type', $open))
            ->update(['comment_status' => 'closed']);

        DB::statement("ALTER TABLE contents ALTER COLUMN comment_status SET DEFAULT 'closed'");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT contents_comment_status_check CHECK (comment_status IN ('open', 'closed', 'hidden'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contents DROP CONSTRAINT IF EXISTS contents_comment_status_check');
        DB::statement('ALTER TABLE contents DROP COLUMN IF EXISTS comment_status');
    }
};

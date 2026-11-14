<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Reader comments (docs/studies/comments.md §3.1, §3.2; spec §5.3 as amended
 * 2026-09-22). One row per comment. Its place in its thread is `path`: its
 * ancestors' IDs and its own, each followed by `/`, in the C collation, so
 * ORDER BY path is display order, oldest first, with no lock and no counter
 * (I-272). `comment_stats` is one row per item with typed counts, kept by
 * the comment Operations in their own transactions (I-273), never a
 * key-value table (rule 2).
 *
 * `moderation` is what judged the comment as it arrived:
 * {"by": "Teme", "status": "pending", "reason": "…", "signals": {…}}.
 * `body` is a restricted Tiptap document (I-267), null only in a tombstone,
 * which keeps its place in the thread and nothing else (I-278).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->ulid('id');
            // Before the self-reference below: a fluent primary() is added last.
            $table->primary('id');
            $table->ulid('content_id');
            $table->ulid('parent_id')->nullable();
            $table->text('path')->collation('C');
            $table->smallInteger('depth');
            $table->string('kind', 16)->default('comment');
            $table->string('status', 32);
            $table->string('previous_status', 32)->nullable();
            $table->string('author_name', 255)->nullable();
            $table->string('author_email', 254)->nullable();
            $table->string('author_url', 200)->nullable();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('body')->nullable();
            $table->char('commenter_token_hash', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->bigInteger('legacy_id')->nullable();
            $table->jsonb('moderation')->nullable();
            $table->timestampsTz();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            // No action: a comment with replies is never deleted, only made
            // a tombstone (I-278), and an item's cascade removes a whole
            // thread in one statement, checked at its end.
            $table->foreign('parent_id')->references('id')->on('comments');
        });

        // The kinds are a closed set; statuses are a registry, with no CHECK,
        // as contents.type follows the type registry (I-224).
        DB::statement(<<<'SQL'
            ALTER TABLE comments
                ADD CONSTRAINT comments_kind_check CHECK (kind IN ('comment', 'pingback', 'trackback')),
                ADD CONSTRAINT comments_path_check CHECK (
                    path ~ '^([0-9a-hjkmnp-tv-z]{26}/)+$'
                    AND length(path) = depth * 27
                    AND right(path, 27) COLLATE "C" = (id || '/') COLLATE "C"
                ),
                ADD CONSTRAINT comments_body_check CHECK (body IS NULL OR jsonb_typeof(body) = 'object'),
                ADD CONSTRAINT comments_moderation_check CHECK (moderation IS NULL OR jsonb_typeof(moderation) = 'object'),
                ADD CONSTRAINT comments_words_check CHECK (status = 'removed' OR body IS NOT NULL),
                ADD CONSTRAINT comments_tombstone_check CHECK (status <> 'removed' OR (
                    body IS NULL AND author_name IS NULL AND author_email IS NULL AND author_url IS NULL
                    AND author_user_id IS NULL AND ip IS NULL AND user_agent IS NULL
                    AND commenter_token_hash IS NULL AND moderation IS NULL AND previous_status IS NULL
                ))
            SQL);

        DB::statement("CREATE INDEX comments_thread ON comments (content_id, path) WHERE status IN ('approved', 'removed')");
        DB::statement('CREATE INDEX comments_item_status ON comments (content_id, status, id)');
        DB::statement('CREATE INDEX comments_parent ON comments (parent_id) WHERE parent_id IS NOT NULL');
        DB::statement('CREATE INDEX comments_email ON comments (author_email, content_id) WHERE author_email IS NOT NULL');
        DB::statement('CREATE INDEX comments_user ON comments (author_user_id) WHERE author_user_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX comments_legacy ON comments (legacy_id) WHERE legacy_id IS NOT NULL');

        Schema::create('comment_stats', function (Blueprint $table): void {
            $table->ulid('content_id')->primary();
            $table->integer('approved_count')->default(0);
            $table->integer('pending_count')->default(0);
            $table->ulid('last_comment_id')->nullable();
            $table->timestampTz('last_comment_at')->nullable();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
            // No action, never set null: deleting an item for good cascades to
            // its comments and to this row in one statement, and a set-null
            // would touch a row that statement is deleting, which PostgreSQL
            // refuses (DeleteContentPermanently meets it with revisions). The
            // last comment is always an approved one, never deleted as such.
            $table->foreign('last_comment_id')->references('id')->on('comments');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE comment_stats
                ADD CONSTRAINT comment_stats_counts_check CHECK (approved_count >= 0 AND pending_count >= 0),
                ADD CONSTRAINT comment_stats_last_check CHECK ((last_comment_id IS NULL) = (last_comment_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_stats');
        Schema::dropIfExists('comments');
    }
};

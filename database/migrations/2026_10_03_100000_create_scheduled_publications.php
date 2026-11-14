<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('revision_id')->constrained('content_revisions')->cascadeOnDelete();
            $table->string('action', 16);
            $table->timestampTz('run_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('held_reason', 32)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 16)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE scheduled_publications ADD CONSTRAINT scheduled_publications_action_check CHECK (action IN ('publish', 'update'))");
        DB::statement("ALTER TABLE scheduled_publications ADD CONSTRAINT scheduled_publications_cancel_reason_check CHECK (cancel_reason IN ('cancelled', 'replaced', 'published', 'trashed', 'unpublished'))");
        DB::statement("ALTER TABLE scheduled_publications ADD CONSTRAINT scheduled_publications_held_reason_check CHECK (held_reason IN ('scheduler_removed', 'scheduler_cannot_publish'))");

        // The scheduler asks one question every minute: what is due? This index
        // is the whole answer, and it stays small because finished rows leave it.
        DB::statement('CREATE INDEX scheduled_publications_due ON scheduled_publications (run_at) WHERE completed_at IS NULL AND failed_at IS NULL AND cancelled_at IS NULL AND held_reason IS NULL');

        // One pending schedule per article. A held row is still pending.
        DB::statement('CREATE UNIQUE INDEX scheduled_publications_one_pending ON scheduled_publications (content_id) WHERE completed_at IS NULL AND failed_at IS NULL AND cancelled_at IS NULL');

        // The Scheduled tab, soonest first, as an index range scan like every
        // other list sort (mirrors contents_list_published).
        DB::statement("CREATE INDEX contents_list_scheduled ON contents (type, scheduled_for, id) WHERE deleted_at IS NULL AND status = 'scheduled'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contents_list_scheduled');
        Schema::dropIfExists('scheduled_publications');
    }
};

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
        Schema::create('content_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('revision_id')->constrained('content_revisions')->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision', 16)->nullable();
            $table->text('note')->nullable();
        });

        DB::statement("ALTER TABLE content_reviews ADD CONSTRAINT content_reviews_decision_check CHECK (decision IN ('approved', 'returned', 'published', 'withdrawn'))");
        DB::statement('ALTER TABLE content_reviews ADD CONSTRAINT content_reviews_decided_check CHECK ((decision IS NULL) = (decided_at IS NULL))');

        // One pending review per item: the editor, the list and 1.5g's panels
        // all read "the" pending review.
        DB::statement('CREATE UNIQUE INDEX content_reviews_one_pending ON content_reviews (content_id) WHERE decided_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reviews');
    }
};

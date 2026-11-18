<?php

/**
 * The fediverse's tables (I-058): the site's own actors and their keys, the
 * remote accounts it hears from, who follows whom, the objects it has
 * published or received, what it sent and to whom, and the blocked servers.
 * Seven new tables and no row: nothing the release before reads changes.
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

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fediverse_actors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->foreignUlid('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            // Stored verbatim and never changed: an imported actor keeps its old address (proposal, Settled 3).
            $table->text('uri')->unique();
            $table->string('username', 100);
            $table->jsonb('urls');
            $table->jsonb('aliases')->default(DB::raw("'[]'::jsonb"));
            $table->text('public_key_pem');
            // Encrypted under APP_KEY, registered with EncryptedValues so ReencryptSecrets covers it.
            $table->text('private_key');
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE fediverse_actors ADD CONSTRAINT fediverse_actors_kind CHECK (kind IN ('blog', 'person', 'application'))");
        DB::statement('CREATE UNIQUE INDEX fediverse_actors_person_id_unique ON fediverse_actors (person_id) WHERE person_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX fediverse_actors_username_unique ON fediverse_actors (lower(username))');

        Schema::create('fediverse_remote_actors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('uri')->unique();
            $table->string('handle', 255)->nullable();
            $table->string('host', 253)->index();
            $table->text('name')->nullable();
            $table->text('profile_url')->nullable();
            $table->text('avatar_url')->nullable();
            $table->text('inbox_url');
            $table->text('shared_inbox_url')->nullable();
            $table->text('key_id');
            $table->text('public_key_pem');
            $table->jsonb('document');
            $table->timestampTz('fetched_at');
            $table->timestampTz('failing_since')->nullable();
            $table->timestampTz('gone_at')->nullable();
        });

        Schema::create('fediverse_followers', function (Blueprint $table): void {
            $table->foreignUlid('local_actor_id')->constrained('fediverse_actors')->cascadeOnDelete();
            $table->foreignUlid('remote_actor_id')->constrained('fediverse_remote_actors')->cascadeOnDelete();
            $table->text('follow_uri')->nullable();
            $table->timestampTz('since');

            $table->primary(['local_actor_id', 'remote_actor_id']);
            $table->index('remote_actor_id');
        });

        Schema::create('fediverse_objects', function (Blueprint $table): void {
            $table->text('uri')->primary();
            $table->string('kind', 16);
            $table->foreignUlid('content_id')->nullable()->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('comment_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignUlid('remote_actor_id')->nullable()->constrained('fediverse_remote_actors')->nullOnDelete();
            $table->timestampTz('deleted_at')->nullable();

            $table->index('content_id');
            $table->index('comment_id');
            $table->index('remote_actor_id');
        });

        DB::statement("ALTER TABLE fediverse_objects ADD CONSTRAINT fediverse_objects_kind CHECK (kind IN ('post', 'reply'))");

        Schema::create('fediverse_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('local_actor_id')->constrained('fediverse_actors')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('object_uri');
            $table->jsonb('payload');
            $table->timestampTz('created_at');
        });

        DB::statement('CREATE INDEX fediverse_activities_actor_latest ON fediverse_activities (local_actor_id, created_at DESC)');

        Schema::create('fediverse_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('activity_id')->constrained('fediverse_activities')->cascadeOnDelete();
            $table->text('inbox_url');
            $table->string('host', 253);
            $table->string('state', 8);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->smallInteger('last_status')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('sent_at')->nullable();

            $table->index(['state', 'next_attempt_at']);
            $table->unique(['activity_id', 'inbox_url']);
        });

        DB::statement("ALTER TABLE fediverse_deliveries ADD CONSTRAINT fediverse_deliveries_state CHECK (state IN ('queued', 'sent', 'failed', 'skipped'))");

        Schema::create('fediverse_blocked_hosts', function (Blueprint $table): void {
            $table->string('host', 253)->primary();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fediverse_blocked_hosts');
        Schema::dropIfExists('fediverse_deliveries');
        Schema::dropIfExists('fediverse_activities');
        Schema::dropIfExists('fediverse_objects');
        Schema::dropIfExists('fediverse_followers');
        Schema::dropIfExists('fediverse_remote_actors');
        Schema::dropIfExists('fediverse_actors');
    }
};

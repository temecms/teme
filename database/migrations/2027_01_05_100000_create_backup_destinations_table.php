<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The places backups are kept (I-360): one row a place, each its own restic
 * repository. The location is never a secret; the credentials are Laravel's
 * encrypted cast over JSON, never serialized, and a removed place keeps
 * none. Only the backup Operations write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('kind', 16);
            $table->jsonb('location');
            $table->text('credentials')->nullable();
            $table->boolean('off_server');
            $table->char('repository_id', 64);
            $table->string('state', 16)->default('ready');
            $table->timestampTz('last_answered_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();
        });

        // No `?` operators below: PDO would read them as placeholders.
        DB::statement(<<<'SQL'
            ALTER TABLE backup_destinations
                ADD CONSTRAINT backup_destinations_name_check CHECK (btrim(name) <> ''),
                ADD CONSTRAINT backup_destinations_kind_check CHECK (kind IN ('folder', 's3', 'sftp')),
                ADD CONSTRAINT backup_destinations_location_check CHECK (
                    jsonb_typeof(location) = 'object'
                    AND (kind <> 'folder' OR COALESCE(location ->> 'path', '') <> '')
                    AND (kind <> 's3' OR (COALESCE(location ->> 'endpoint', '') <> '' AND COALESCE(location ->> 'bucket', '') <> ''))
                    AND (kind <> 'sftp' OR (COALESCE(location ->> 'host', '') <> '' AND COALESCE(location ->> 'user', '') <> '' AND COALESCE(location ->> 'path', '') <> ''))
                ),
                ADD CONSTRAINT backup_destinations_off_server_check CHECK (off_server = (kind <> 'folder')),
                ADD CONSTRAINT backup_destinations_credentials_check CHECK ((kind = 'folder' OR state = 'removed') = (credentials IS NULL)),
                ADD CONSTRAINT backup_destinations_repository_check CHECK (repository_id ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT backup_destinations_state_check CHECK (state IN ('ready', 'failing', 'removed')),
                ADD CONSTRAINT backup_destinations_removed_check CHECK (
                    (state = 'removed') = (removed_at IS NOT NULL) AND (removed_by IS NULL OR removed_at IS NOT NULL)
                ),
                ADD CONSTRAINT backup_destinations_error_check CHECK ((state = 'failing') = (last_error IS NOT NULL))
            SQL);

        // A name, and a repository, once among the places in use.
        DB::statement("CREATE UNIQUE INDEX backup_destinations_name_unique ON backup_destinations (lower(name)) WHERE state <> 'removed'");
        DB::statement("CREATE UNIQUE INDEX backup_destinations_repository_unique ON backup_destinations (repository_id) WHERE state <> 'removed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_destinations');
    }
};

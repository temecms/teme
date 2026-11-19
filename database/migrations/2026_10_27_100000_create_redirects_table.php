<?php

/**
 * Migration: creates redirects table.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Manual and imported redirects (I-297): one row a redirect, its source in
 * the path table's own form, exact or a pattern compiled once (I-287), and a
 * target that is an address, another site's address, an item or a media
 * file by ID, or nothing for 410 and 451 (I-860). The checks keep a row
 * whole whoever writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Compared byte for byte, as paths.path is.
            $table->text('source')->collation('C');
            $table->text('kind');
            $table->text('pattern')->nullable();
            $table->smallInteger('status');
            $table->text('target_kind')->nullable();
            $table->text('target')->nullable();
            $table->text('target_path')->nullable()->collation('C');
            $table->text('origin')->default('manual');
            $table->boolean('enabled')->default(true);
            $table->string('note', 200)->default('');
            $table->bigInteger('hits')->default(0);
            $table->timestampTz('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique('source');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE redirects
                ADD CONSTRAINT redirects_source_check CHECK (source <> ''),
                ADD CONSTRAINT redirects_kind_check CHECK (kind IN ('exact', 'pattern')),
                ADD CONSTRAINT redirects_pattern_check CHECK ((kind = 'pattern') = (pattern IS NOT NULL)),
                ADD CONSTRAINT redirects_status_check CHECK (status IN (301, 302, 307, 308, 410, 451)),
                ADD CONSTRAINT redirects_target_kind_check CHECK (target_kind IN ('address', 'outside', 'content', 'media')),
                ADD CONSTRAINT redirects_nowhere_check CHECK ((status IN (410, 451)) = (target_kind IS NULL)),
                ADD CONSTRAINT redirects_target_check CHECK ((target_kind IS NULL) = (target IS NULL)),
                ADD CONSTRAINT redirects_target_path_check CHECK ((target_kind = 'address' AND target_path IS NOT NULL) OR (target_kind IS DISTINCT FROM 'address' AND target_path IS NULL)),
                ADD CONSTRAINT redirects_origin_check CHECK (origin IN ('manual', 'import')),
                ADD CONSTRAINT redirects_hits_check CHECK (hits >= 0)
            SQL);

        // Who sends readers to this address: the chain check (I-859).
        DB::statement('CREATE INDEX redirects_target_path_index ON redirects (target_path) WHERE target_path IS NOT NULL');
        // Redirects to an item or a file: their states, and the trash offer (I-857).
        DB::statement("CREATE INDEX redirects_targets_index ON redirects (target_kind, target) WHERE target_kind IN ('content', 'media')");
        // The few patterns, read whole into the memo.
        DB::statement("CREATE INDEX redirects_patterns_index ON redirects (id) WHERE kind = 'pattern'");
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};

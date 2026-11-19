<?php

/**
 * Migration: creates not found log table.
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
 * The 404 log (I-298): one row per address readers asked for that nothing
 * answered, in the path table's form, with how often, when first and last,
 * and the host they last came from; never an IP address or a browser.
 * Written a minute at a time by RecordAddressCounts, capped by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('not_found_log', function (Blueprint $table): void {
            // Compared byte for byte, as paths.path and redirects.source are.
            $table->text('path')->collation('C')->primary();
            $table->bigInteger('count');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->string('referrer_host', 253)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE not_found_log
                ADD CONSTRAINT not_found_log_count_check CHECK (count > 0),
                ADD CONSTRAINT not_found_log_path_check CHECK (char_length(path) <= 2000)
            SQL);

        // The least recently seen make room (I-298).
        DB::statement('CREATE INDEX not_found_log_last_seen_index ON not_found_log (last_seen_at, path)');
        // The log, the most asked for first, a page at a time.
        DB::statement('CREATE INDEX not_found_log_most_asked_index ON not_found_log (count DESC, path)');
    }

    public function down(): void
    {
        Schema::dropIfExists('not_found_log');
    }
};

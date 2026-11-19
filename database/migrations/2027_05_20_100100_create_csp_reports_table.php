<?php

/**
 * Migration: creates csp reports table.
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

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csp_reports', function (Blueprint $table): void {
            $table->id();
            // What the policy blocked or would block: the directive, the origin
            // or keyword, and the page's path; one row a triple (I-3375).
            $table->string('directive', 32);
            $table->string('blocked', 255);
            $table->string('page', 512);
            // enforce or report, as the last report said.
            $table->string('disposition', 8);
            $table->bigInteger('count');
            // The first report's script sample and where it came from, when it had one.
            $table->jsonb('sample')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unique(['directive', 'blocked', 'page']);
            $table->index('last_seen_at');
        });

        DB::statement("ALTER TABLE csp_reports ADD CONSTRAINT csp_reports_disposition CHECK (disposition IN ('enforce', 'report'))");
        DB::statement('ALTER TABLE csp_reports ADD CONSTRAINT csp_reports_count CHECK (count > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_reports');
    }
};

<?php

/**
 * Migration: creates heartbeats table.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row a name, overwritten in place: the scheduler's, and one for
     * each queue a worker has finished a job on (I-3112).
     */
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->timestampTz('beaten_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};

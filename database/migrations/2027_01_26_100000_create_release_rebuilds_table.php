<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row a rebuild a release has asked for, waiting until teme:deploy
     * queues it (I-3113).
     */
    public function up(): void
    {
        Schema::create('release_rebuilds', function (Blueprint $table): void {
            $table->string('rebuild', 64)->primary();
            $table->timestampTz('asked_at');
            $table->timestampTz('queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_rebuilds');
    }
};

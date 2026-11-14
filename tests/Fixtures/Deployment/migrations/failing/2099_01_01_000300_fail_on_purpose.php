<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A migration that fails after it has begun, so its transaction leaves
     * nothing of it behind.
     */
    public function up(): void
    {
        Schema::create('deploy_failed', function (Blueprint $table): void {
            $table->id();
        });

        throw new RuntimeException('This migration fails on purpose.');
    }
};

<?php

/**
 * Migration: creates ai monthly spend table.
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
 * What each UTC month of AI cost (I-320): the priced requests' total in
 * micro-dollars, every request, and those whose model had no price.
 * Kept by RecordAiActivity in the request's own transaction, so the budget
 * never sums the record.
 * Kept when the record is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_monthly_spend', function (Blueprint $table): void {
            $table->date('month')->primary();
            $table->bigInteger('cost_microunits')->default(0);
            $table->integer('requests')->default(0);
            $table->integer('unpriced_requests')->default(0);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_monthly_spend
                ADD CONSTRAINT ai_monthly_spend_month_check CHECK (EXTRACT(DAY FROM month) = 1),
                ADD CONSTRAINT ai_monthly_spend_counts_check CHECK (
                    cost_microunits >= 0 AND requests >= 0 AND unpriced_requests >= 0 AND unpriced_requests <= requests
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_monthly_spend');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * What each plugin's AI requests cost each UTC month, beside the site's own
 * month (docs/plans/plugin-sockets-3.md): the priced total in micro-dollars,
 * every request, and those whose model had no price. Kept by
 * RecordAiActivity in the request's own transaction, so a plugin's budget
 * never sums the record. Kept when the record is pruned, and when the plugin's
 * data is removed: it is the site's record of spending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_plugin_monthly_spend', function (Blueprint $table): void {
            $table->string('plugin', 64);
            $table->date('month');
            $table->bigInteger('cost_microunits')->default(0);
            $table->integer('requests')->default(0);
            $table->integer('unpriced_requests')->default(0);
            $table->primary(['plugin', 'month']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_plugin_monthly_spend
                ADD CONSTRAINT ai_plugin_monthly_spend_month_check CHECK (EXTRACT(DAY FROM month) = 1),
                ADD CONSTRAINT ai_plugin_monthly_spend_counts_check CHECK (
                    cost_microunits >= 0 AND requests >= 0 AND unpriced_requests >= 0 AND unpriced_requests <= requests
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_plugin_monthly_spend');
    }
};

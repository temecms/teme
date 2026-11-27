<?php

/**
 * Creates the table that records which plugin an administrator chose to fill
 * a provider slot (docs/guides/developers/plugins/provider-slots.md): one row
 * per slot, present only while a choice stands. A row whose plugin was turned
 * off keeps the notice saying so, until an administrator chooses again.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_slot_choices', function (Blueprint $table): void {
            $table->string('slot', 40)->primary();
            $table->string('plugin', 214);
            $table->unsignedBigInteger('chosen_by')->nullable();
            $table->timestamp('chosen_at');
            $table->string('notice', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_slot_choices');
    }
};

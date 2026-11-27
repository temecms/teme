<?php

/**
 * Adds the notice a slot keeps when its chosen plugin is turned off: the
 * choice is cleared and the notice says so until an administrator chooses again
 * (docs/guides/developers/plugins/provider-slots.md).
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
        Schema::table('plugin_slot_choices', function (Blueprint $table): void {
            $table->string('notice', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plugin_slot_choices', function (Blueprint $table): void {
            $table->dropColumn('notice');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guid WordPress gave an imported item, which its feed keeps giving so
 * readers see nothing new (I-922); null for every item Teme made itself. A
 * nullable column with no default: PostgreSQL adds it without a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('guid', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('guid');
        });
    }
};

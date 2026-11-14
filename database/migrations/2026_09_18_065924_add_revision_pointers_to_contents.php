<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->foreign('published_revision_id')
                ->references('id')->on('content_revisions')
                ->nullOnDelete();

            $table->foreign('working_revision_id')
                ->references('id')->on('content_revisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropForeign(['published_revision_id']);
            $table->dropForeign(['working_revision_id']);
        });
    }
};

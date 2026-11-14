<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignUlid('revision_id')->constrained('content_revisions')->cascadeOnDelete();

            // sha256 of the token, hex. The plain token is never stored.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Microseconds, so a view is recorded at the instant it happened.
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('revoked_at', 6)->nullable();
            $table->timestampTz('last_viewed_at', 6)->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_links');
    }
};

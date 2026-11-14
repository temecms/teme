<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A telemetry sink (docs/architecture/operations.md). Noisy types are
        // counted per subject, address and hour; bots probe constantly.
        Schema::create('security_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64);
            $table->string('subject', 80);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->default('');
            $table->timestampTz('hour');
            $table->boolean('counted')->default(false);
            $table->unsignedInteger('count')->default(1);
            $table->jsonb('context')->default('{}');
            $table->timestampTz('first_at');
            $table->timestampTz('last_at');

            $table->index(['user_id', 'type', 'last_at']);
            $table->index('last_at');
        });

        DB::statement('CREATE UNIQUE INDEX security_events_counted_unique ON security_events (type, subject, ip, hour) WHERE counted');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};

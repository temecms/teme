<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placard_ads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->jsonb('data')->default('{}');
            $table->timestampsTz();
        });
    }
};

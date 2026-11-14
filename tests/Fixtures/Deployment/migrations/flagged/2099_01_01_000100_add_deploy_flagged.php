<?php

declare(strict_types=1);

use App\Domain\Deployment\BreaksPreviousRelease;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration implements BreaksPreviousRelease
{
    /**
     * A migration its release flags as breaking the release before (I-383).
     */
    public function up(): void
    {
        Schema::create('deploy_flagged', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_flagged');
    }
};

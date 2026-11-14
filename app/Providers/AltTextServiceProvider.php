<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Tasks\AltText\AltTextTask;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the alt-text task (I-319) with the AI task registry.
 */
final class AltTextServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([AltTextTask::class], AiTasks::TAG);

        // Later (I-322): the queued run over the library, which only records suggestions for review, registers here.
    }
}

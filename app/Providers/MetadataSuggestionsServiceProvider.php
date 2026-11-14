<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Tasks\Metadata\MetadataTask;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the metadata task among the AI tasks (I-1313), in register(), so
 * Settings › AI offers it a model wherever this provider sits.
 */
final class MetadataSuggestionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([MetadataTask::class], AiTasks::TAG);
    }
}

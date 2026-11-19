<?php

/**
 * Registers the rewrite task (I-1309) among the AI tasks, from register(),
 * so the AI settings group built at boot holds its model and fallback.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiTasks;
use App\Domain\Ai\Tasks\Rewrite\RewriteTask;
use Illuminate\Support\ServiceProvider;

final class RewriteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([RewriteTask::class], AiTasks::TAG);
    }
}

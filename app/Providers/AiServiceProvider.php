<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiSettings;
use App\Domain\Ai\ModelChoices;
use App\Domain\Ai\Plugins\PluginAiSection;
use App\Domain\Ai\UsageLimits;
use App\Domain\Settings\SettingsCheck;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Teme\Contracts\Settings\SettingsRegistrar;

/**
 * Registers Settings › AI: its Models, Plugins and Limits sections, the check on the
 * models chosen, and, once every provider has registered its own tasks and
 * sections, the group they make; and the per-person hourly limit (I-320).
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ModelChoices::class, UsageLimits::class, PluginAiSection::class], AiSettings::SECTIONS);
        $this->app->tag([ModelChoices::class, PluginAiSection::class], SettingsCheck::TAG);
    }

    public function boot(): void
    {
        // Every provider's register() has run by now, so every task and section is tagged.
        $this->app->make(SettingsRegistrar::class)->group($this->app->make(AiSettings::class)->group());

        RateLimiter::for('ai', fn (Request $request): Limit => Limit::perHour(UsageLimits::hourly())->by('ai:'.$request->user()?->id));
    }
}

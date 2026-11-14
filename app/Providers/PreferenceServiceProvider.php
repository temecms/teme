<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Preferences\BuiltInPreferences;
use App\Domain\Preferences\PreferenceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the per-person preference registry, and declares the built-in
 * preferences into it, as every other registry has its own provider.
 */
final class PreferenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PreferenceRegistry::class, function (): PreferenceRegistry {
            $registry = new PreferenceRegistry;
            BuiltInPreferences::register($registry);

            return $registry;
        });
    }
}

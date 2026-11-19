<?php

/**
 * Registers the AI services Teme can ask (I-315). Each is at once the
 * provider, its section of Settings › AI and that section's check.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Providers\AnthropicProvider;
use App\Domain\Ai\Providers\DeepSeekProvider;
use App\Domain\Ai\Providers\OpenAiProvider;
use App\Domain\Ai\Providers\OpenRouterProvider;
use App\Domain\Settings\SettingsCheck;
use Illuminate\Support\ServiceProvider;

final class AiProvidersServiceProvider extends ServiceProvider
{
    private const array PROVIDERS = [AnthropicProvider::class, OpenAiProvider::class, DeepSeekProvider::class, OpenRouterProvider::class];

    public function register(): void
    {
        $this->app->tag(self::PROVIDERS, AiProviders::TAG);
        $this->app->tag(self::PROVIDERS, AiSettings::SECTIONS);
        $this->app->tag(self::PROVIDERS, SettingsCheck::TAG);
    }
}

<?php

declare(strict_types=1);

// I-311: laravel/ai stays inside the AI providers' folder, so a release of
// it touches that folder and nothing else.
arch('only the AI providers use laravel/ai')
    ->expect('App')
    ->not->toUse('Laravel\Ai')
    ->ignoring('App\Domain\Ai\Providers');

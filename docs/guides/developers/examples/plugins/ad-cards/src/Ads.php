<?php

declare(strict_types=1);

namespace TemeExamples\AdCards;

use Teme\Contracts\Cards\HasCards;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Plugins\Plugin;

/**
 * The handbook's cards example: one card, an ad slot, drawn from the ads
 * the plugin keeps.
 */
final class Ads implements HasCards, Plugin
{
    /**
     * @param  array<string, array{name: string, size: string, html: string}>  $ads  by ID; a real plugin reads its own table
     */
    public function __construct(private array $ads = ['spring' => ['name' => 'Spring sale', 'size' => '300x250', 'html' => '<a href="https://ads.example.net/spring">Spring sale</a>']]) {}

    public function cards(): array
    {
        return ['ad-slot' => new AdSlot($this->ads)];
    }

    public function register(Hooks $hooks): void {}
}

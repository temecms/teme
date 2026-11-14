<?php

declare(strict_types=1);

use App\Domain\Cards\CardManifest;
use App\Domain\Cards\CardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The card views offer an attribute's allowed values as a choice, so the
 * manifest carries them from the attribute's `in:` rule and the browser never
 * restates the list.
 */
it('gives the browser each attribute\'s allowed values, from its in: rule', function (): void {
    $cards = collect(CardManifest::forBrowser(app(CardRegistry::class))['cards'])->keyBy('name');
    $attribute = fn (string $card, string $name): array => collect($cards[$card]['attributes'])->firstWhere('name', $name);

    expect($attribute('callout', 'tone')['options'])->toBe(['note', 'tip', 'warning'])
        ->and($attribute('snippet', 'language')['options'])->toBe(array_keys(config('code.languages')))
        ->and($attribute('callout', 'text')['options'])->toBeNull();
});

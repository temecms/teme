<?php

declare(strict_types=1);

use App\Domain\Cards\CardRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('documents every registered card', function (): void {
    foreach (app(CardRegistry::class)->all() as $card) {
        expect([$card->label(), $card->description(), $card->since()])->each->not->toBe('');
        expect($card->name())->toMatch('/^[a-z][a-z0-9-]*$/');
    }
});

it('gives every card attribute a rule', function (): void {
    foreach (app(CardRegistry::class)->all() as $card) {
        foreach ($card->attributes() as $name => $attribute) {
            expect($attribute->rules)->not->toBeEmpty("{$card->name()}.{$name} has no rules");
        }
    }
});

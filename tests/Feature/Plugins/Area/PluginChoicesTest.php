<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaValidator;

// A select may take its options from the plugin, when its form opens, instead of
// declaring them. The manifest says so with "choices": "plugin", and no other value.

it('accepts a select whose choices come from the plugin, with no options of its own', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin'];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toBe([]);
});

it('refuses a select that names its options and asks the plugin for them', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin', 'options' => ['a' => 'A']];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toContain('A select takes either its options or "choices": "plugin", not both.');
});

it('refuses a choices value other than plugin, and choices on a field that is not a select', function (): void {
    $bad = ['key' => 'account', 'type' => 'select', 'label' => 'Account', 'choices' => 'site', 'options' => ['a' => 'A']];
    $text = ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'choices' => 'plugin'];

    expect(app(AreaValidator::class)->fieldErrors($bad, [], true))->toContain('A field’s choices can only be "plugin", or left out.')
        ->and(app(AreaValidator::class)->fieldErrors($text, [], true))->toContain('Only a select field may take its choices from the plugin.');
});

it('still refuses a select of no options and no plugin choices', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Account'];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toContain('The field account needs its options.');
});

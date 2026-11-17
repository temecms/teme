<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\VisibleWhen;

it('shows a field when every condition holds, a list matching any of its values', function (array $when, array $values, bool $shows): void {
    expect(VisibleWhen::shows($when, $values))->toBe($shows);
})->with([
    'no condition' => [[], [], true],
    'a scalar that matches' => [['location' => 'after_paragraph'], ['location' => 'after_paragraph'], true],
    'a scalar that doesn’t' => [['location' => 'after_paragraph'], ['location' => 'between_items'], false],
    'a list matching one' => [['location' => ['region', 'between_items']], ['location' => 'region'], true],
    'a list matching none' => [['location' => ['region', 'between_items']], ['location' => 'after_paragraph'], false],
    'a chosen list against a value' => [['types' => 'post'], ['types' => ['page', 'post']], true],
    'every key must match' => [['a' => 'x', 'b' => 'y'], ['a' => 'x', 'b' => 'z'], false],
    'every key matching' => [['a' => 'x', 'b' => 'y'], ['a' => 'x', 'b' => 'y'], true],
    'a ticked box' => [['on' => true], ['on' => true], true],
    'an unticked box' => [['on' => true], ['on' => false], false],
    'a box named as text' => [['on' => 'true'], ['on' => true], true],
    'a number as text' => [['paragraph' => '3'], ['paragraph' => 3], true],
    'an empty value' => [['provider' => 'adsense'], [], false],
    'asking for empty' => [['provider' => ''], ['provider' => null], true],
]);

it('drops hidden fields in declared order, a hidden field counting as empty for the fields after it', function (): void {
    $fields = [
        ['key' => 'provider'],
        ['key' => 'mode', 'visibleWhen' => ['provider' => 'adsense']],
        ['key' => 'auto', 'visibleWhen' => ['mode' => 'auto']],
        ['key' => 'label'],
    ];

    expect(VisibleWhen::visibleValues($fields, ['provider' => 'adsense', 'mode' => 'auto', 'auto' => 'yes', 'label' => 'Ad']))
        ->toBe(['provider' => 'adsense', 'mode' => 'auto', 'auto' => 'yes', 'label' => 'Ad'])
        ->and(VisibleWhen::visibleValues($fields, ['provider' => 'none', 'mode' => 'auto', 'auto' => 'yes', 'label' => 'Ad']))
        ->toBe(['provider' => 'none', 'label' => 'Ad'])
        ->and(VisibleWhen::hiddenKeys($fields, ['provider' => 'none', 'mode' => 'auto']))
        ->toBe(['mode', 'auto']);
});

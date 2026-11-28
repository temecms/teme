<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use Teme\Contracts\Plugins\Capability;

beforeEach(function (): void {
    $this->validator = app(ContributionsValidator::class);
    $this->filter = fn (array $changes = []): array => array_replace([
        'id' => 'review-state',
        'label' => 'Review state',
        'types' => ['post'],
        'options' => [['value' => 'ready', 'label' => 'Ready'], ['value' => 'stale', 'label' => 'Stale']],
        'table' => 'listfilters_scores',
        'column' => 'state',
        'item' => 'content_id',
    ], $changes);
});

it('accepts a declared filter with its options, table, column and item', function (): void {
    expect($this->validator->errors(['listFilters' => [($this->filter)()]], '1.0.0', [Capability::ContentRead->value]))->toBe([]);
});

it('refuses more than two filters from one plugin', function (): void {
    $filters = [($this->filter)(), ($this->filter)(['id' => 'second']), ($this->filter)(['id' => 'third'])];

    expect($this->validator->errors(['listFilters' => $filters], '1.0.0'))
        ->toBe(['A plugin can add at most 2 list filters.']);
});

it('refuses two filters that share an id', function (): void {
    $filters = [($this->filter)(), ($this->filter)()];

    expect($this->validator->errors(['listFilters' => $filters], '1.0.0'))
        ->toBe(['Two list filters can’t share the id review-state.']);
});

it('refuses a filter with no options, or more than twenty', function (): void {
    $one = [['value' => 'ready', 'label' => 'Ready']];
    $many = array_map(fn (int $n): array => ['value' => "option-{$n}", 'label' => "Option {$n}"], range(1, 21));

    expect($this->validator->errors(['listFilters' => [($this->filter)(['options' => $one])]], '1.0.0'))
        ->toBe(['A list filter needs 2 to 20 options.'])
        ->and($this->validator->errors(['listFilters' => [($this->filter)(['options' => $many])]], '1.0.0'))
        ->toBe(['A list filter needs 2 to 20 options.']);
});

it('refuses two options with the same value', function (): void {
    $options = [['value' => 'ready', 'label' => 'Ready'], ['value' => 'ready', 'label' => 'Ready again']];

    expect($this->validator->errors(['listFilters' => [($this->filter)(['options' => $options])]], '1.0.0'))
        ->toBe(['Two options of a list filter can’t share the value ready.']);
});

it('refuses a table, column or item name that is not lower-case letters, digits and underscores', function (): void {
    expect($this->validator->errors(['listFilters' => [($this->filter)(['table' => 'Scores; drop'])]], '1.0.0'))
        ->toContain('A list filter’s table, column and item must be lower-case letters, digits and underscores, starting with a letter.')
        ->and($this->validator->errors(['listFilters' => [($this->filter)(['column' => 'state-x'])]], '1.0.0'))
        ->toContain('A list filter’s table, column and item must be lower-case letters, digits and underscores, starting with a letter.');
});

it('refuses a filter with no content type, or a key it does not know', function (): void {
    expect($this->validator->errors(['listFilters' => [($this->filter)(['types' => []])]], '1.0.0'))
        ->toContain('A list filter needs at least one content type.')
        ->and($this->validator->errors(['listFilters' => [($this->filter)(['sortable' => true])]], '1.0.0'))
        ->toContain('A list filter can’t have sortable.');
});

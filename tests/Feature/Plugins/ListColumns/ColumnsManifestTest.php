<?php

declare(strict_types=1);

use App\Domain\Plugins\ManifestValidator;
use Tests\Fixtures\Plugins\PluginFixtures;

beforeEach(function (): void {
    config(['teme.version' => PluginFixtures::TEME]);

    $this->errors = function (array $manifest = []): array {
        $copy = PluginFixtures::copy('columns', $manifest);
        $read = json_decode((string) file_get_contents($copy.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer = json_decode((string) file_get_contents($copy.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        return app(ManifestValidator::class)->errors($read, $copy, $composer);
    };
});

afterEach(fn () => PluginFixtures::forget());

it("accepts the fixture's three columns", function (): void {
    expect(($this->errors)())->toBe([]);
});

it("refuses columns from a plugin that doesn't ask for content.read", function (): void {
    expect(($this->errors)(['capabilities' => []]))
        ->toContain('It adds columns to the content lists, but the plugin doesn’t ask for content.read.');
});

it('refuses a column that …', function (Closure $break, string $words): void {
    $base = PluginFixtures::manifest('columns');
    $contributes = $base['contributes'];
    $broken = $break(is_array($contributes['columns'] ?? null) ? $contributes['columns'] : []);

    expect(($this->errors)([
        'contributes' => [
            ...$contributes,
            'columns' => $broken,
        ],
    ]))->toContain($words);
})->with([
    'a bad id' => [
        fn (array $columns): array => [['id' => 'Score', 'label' => 'Score', 'kind' => 'badge']],
        'A column’s id must be 2 to 40 lower-case letters, digits and hyphens, starting with a letter.',
    ],
    'two columns with one id' => [
        fn (array $columns): array => [
            ['id' => 'score', 'label' => 'One', 'kind' => 'badge'],
            ['id' => 'score', 'label' => 'Two', 'kind' => 'badge'],
        ],
        'Two columns can’t share the id score.',
    ],
    'an empty label' => [
        fn (array $columns): array => [['id' => 'score', 'label' => ' ', 'kind' => 'badge']],
        'A column’s label must be 1 to 40 characters.',
    ],
    'a label over 40 characters' => [
        fn (array $columns): array => [['id' => 'score', 'label' => str_repeat('a', 41), 'kind' => 'badge']],
        'A column’s label must be 1 to 40 characters.',
    ],
    'an unknown kind' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'chart']],
        'A column’s kind must be text, number or badge.',
    ],
    'a width that isn’t a string' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'badge', 'width' => ['wide']]],
        'A column’s width must be narrow, medium or wide.',
    ],
    'an unknown width' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'badge', 'width' => 'huge']],
        'A column’s width must be narrow, medium or wide.',
    ],
    'a type key Post' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'badge', 'types' => ['Post']]],
        'A column’s type must be a lower-case content type key.',
    ],
    'a sortable key' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'badge', 'sortable' => true]],
        'Teme doesn’t sort a list by a plugin’s column yet.',
    ],
    'a fourth column' => [
        fn (array $columns): array => [
            ...$columns,
            ['id' => 'extra', 'label' => 'Extra', 'kind' => 'text'],
        ],
        'A plugin can add at most 3 columns.',
    ],
    'an unknown key' => [
        fn (array $columns): array => [['id' => 'score', 'label' => 'Score', 'kind' => 'badge', 'foo' => true]],
        'A column can’t have foo.',
    ],
]);

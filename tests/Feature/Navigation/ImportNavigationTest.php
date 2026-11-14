<?php

declare(strict_types=1);

use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\Operations\ImportNavigation;
use App\Domain\Menus\Operations\Input\ImportNavigationInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\MenuImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->about = addrPublished($this->admin, 'About', 'page');
});

/**
 * ImportNavigation run with these fields over a new menu called Main that
 * holds the home page, as $actor, or as the operator at the shell.
 *
 * @param  array<string, mixed>  $fields
 *
 * @return array{navigationId: string, created: bool, changedHere: bool, fingerprint: string}
 */
function inImport(array $fields = [], ?User $actor = null, bool $elevated = true): array
{
    $import = fn (): array => app(ImportNavigation::class)->execute(new ImportNavigationInput(...[
        'navigationId' => null,
        'name' => 'Main',
        'tree' => [['kind' => 'home']],
        'expectedFingerprint' => null,
        'actorId' => $actor === null ? null : (string) $actor->id,
        ...$fields,
    ]));

    return $actor === null && $elevated ? scElevated($import) : $import();
}

it('makes a menu from WordPress’s name and tree, by SaveNavigationItems’ own rules', function (): void {
    $saved = inImport(['name' => 'Main & more', 'tree' => [
        ['kind' => 'home', 'label' => 'Start'],
        ['kind' => 'url', 'url' => '/about/', 'children' => [
            ['kind' => 'url', 'url' => 'https://partner.example/', 'label' => 'Partner', 'opens_new_tab' => true],
        ]],
        ['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'home']]],
    ]], $this->admin);
    $navigation = Navigation::query()->findOrFail($saved['navigationId']);

    expect($saved['created'])->toBeTrue()
        ->and($saved['changedHere'])->toBeFalse()
        ->and($saved['fingerprint'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($navigation->only(['name', 'handle']))->toBe(['name' => 'Main & more', 'handle' => 'main-more'])
        ->and(MenuImports::stored($navigation))->toBe([
            ['kind' => 'home', 'target_id' => null, 'url' => null, 'label' => 'Start', 'opens_new_tab' => false, 'children' => []],
            ['kind' => 'content', 'target_id' => $this->about->id, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => [
                ['kind' => 'url', 'target_id' => null, 'url' => 'https://partner.example/', 'label' => 'Partner', 'opens_new_tab' => true, 'children' => []],
            ]],
            ['kind' => 'heading', 'target_id' => null, 'url' => null, 'label' => 'Visit', 'opens_new_tab' => false, 'children' => [
                ['kind' => 'home', 'target_id' => null, 'url' => null, 'label' => null, 'opens_new_tab' => false, 'children' => []],
            ]],
        ]);
});

it('replaces a menu’s name and tree while it is as the import left it, keeping its handle', function (): void {
    $first = inImport([], $this->admin);
    $again = inImport([
        'navigationId' => $first['navigationId'],
        'name' => 'Footer',
        'tree' => [['kind' => 'url', 'url' => 'https://partner.example/']],
        'expectedFingerprint' => $first['fingerprint'],
    ], $this->admin);
    $navigation = Navigation::query()->findOrFail($first['navigationId']);

    expect($again)->toMatchArray(['navigationId' => $first['navigationId'], 'created' => false, 'changedHere' => false])
        ->and($again['fingerprint'])->not->toBe($first['fingerprint'])
        ->and($navigation->only(['name', 'handle']))->toBe(['name' => 'Footer', 'handle' => 'main'])
        ->and(array_column(MenuImports::stored($navigation), 'url'))->toBe(['https://partner.example/']);
});

it('leaves a menu changed here since as it is, saying so', function (): void {
    $first = inImport([], $this->admin);
    $navigation = Navigation::query()->findOrFail($first['navigationId']);
    navSave($this->admin, $navigation, [['kind' => 'home', 'label' => 'Ours']]);

    $again = inImport([
        'navigationId' => $navigation->id,
        'tree' => [['kind' => 'url', 'url' => 'https://partner.example/']],
        'expectedFingerprint' => $first['fingerprint'],
    ], $this->admin);

    expect($again['changedHere'])->toBeTrue()
        ->and($again['created'])->toBeFalse()
        ->and(array_column(MenuImports::stored($navigation), 'label'))->toBe(['Ours']);
});

it('counts a save here that changed nothing as no change', function (): void {
    $first = inImport(['tree' => [['kind' => 'content', 'target_id' => $this->about->id]]], $this->admin);
    $navigation = Navigation::query()->findOrFail($first['navigationId']);
    navSave($this->admin, $navigation, MenuImports::stored($navigation));

    $again = inImport(['navigationId' => $navigation->id, 'tree' => [['kind' => 'home']], 'expectedFingerprint' => $first['fingerprint']], $this->admin);

    expect($again['changedHere'])->toBeFalse()
        ->and(array_column(MenuImports::stored($navigation), 'kind'))->toBe(['home']);
});

it('refuses a tree SaveNavigationItems refuses, naming the item, and makes no menu', function (): void {
    $tree = [['kind' => 'home', 'children' => [['kind' => 'home', 'children' => [['kind' => 'home', 'children' => [['kind' => 'home']]]]]]]];

    expect(fn () => inImport(['tree' => $tree], $this->admin))->toThrow(ValidationException::class, 'Item 1 › 1 › 1 › 1')
        ->and(Navigation::query()->count())->toBe(0);
});

it('refuses a menu that isn’t there any more, and a name a menu can’t have', function (array $fields, string $message): void {
    expect(fn () => inImport($fields, $this->admin))->toThrow(ValidationException::class, $message);
})->with([
    'a menu deleted here' => [['navigationId' => '01jb0000000000000000000000'], 'The menu isn’t there to update.'],
    'no name' => [['name' => '  '], 'The name field is required.'],
    'a long name' => [['name' => str_repeat('a', 121)], 'The name field must not be greater than 120 characters.'],
]);

it('writes nothing for anyone who may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => inImport([], $editor))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(fn () => inImport([], null, elevated: false))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(Navigation::query()->count())->toBe(0)
        ->and(NavigationItem::query()->count())->toBe(0);
});

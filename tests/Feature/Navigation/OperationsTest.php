<?php

declare(strict_types=1);

use App\Domain\Addresses\Models\Path;
use App\Domain\Addresses\PathProjection;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\NavigationTree;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Domain\Menus\Operations\Input\RenameNavigationInput;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Menus\Operations\RenameNavigation;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('creates a menu with a handle from its name', function (): void {
    $nav = navCreate($this->editor, 'Main menu');

    expect($nav->handle)->toBe('main-menu');
});

it('saves a tree of items pointing at content, terms, the home page and an outside address', function (): void {
    $page = addrPublished($this->editor, 'About', 'page');
    $news = taxonomyTerm('category', 'News');
    $nav = navCreate($this->editor);

    navSave($this->editor, $nav, [
        ['kind' => 'home'],
        ['kind' => 'content', 'target_id' => $page->id, 'children' => [
            ['kind' => 'url', 'url' => 'https://example.org/', 'label' => 'Partner', 'opens_new_tab' => true],
        ]],
        ['kind' => 'term', 'target_id' => $news->id, 'label' => 'Latest news'],
    ]);

    $items = NavigationItem::query()->where('navigation_id', $nav->id)->orderBy('position')->get();
    expect($items)->toHaveCount(4)
        ->and($items->firstWhere('kind', 'url')->parent_id)->toBe($items->firstWhere('kind', 'content')->id);
});

it('replaces the whole tree on each save', function (): void {
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home'], ['kind' => 'home']]);
    navSave($this->editor, $nav, [['kind' => 'home']]);

    expect(NavigationItem::query()->where('navigation_id', $nav->id)->count())->toBe(1);
});

it('saves a heading over its items, storing its own label and no target, address or new tab', function (): void {
    $nav = navCreate($this->editor);

    navSave($this->editor, $nav, [
        ['kind' => 'heading', 'label' => ' Visit ', 'target_id' => '01j00000000000000000000000', 'url' => '/visit/', 'opens_new_tab' => true, 'children' => [
            ['kind' => 'home'],
        ]],
    ]);

    $heading = NavigationItem::query()->where('navigation_id', $nav->id)->where('kind', 'heading')->sole();

    expect($heading->only(['label', 'target_id', 'url', 'opens_new_tab', 'parent_id']))->toBe(['label' => 'Visit', 'target_id' => null, 'url' => null, 'opens_new_tab' => false, 'parent_id' => null])
        ->and(NavigationItem::query()->where('parent_id', $heading->id)->sole()->kind)->toBe('home');
});

it('refuses a heading with no label, nothing under it, or at the third level, naming it', function (array $tree, string $message): void {
    $nav = navCreate($this->editor);

    expect(fn () => navSave($this->editor, $nav, $tree))->toThrow(ValidationException::class, $message);
    expect(NavigationItem::query()->where('navigation_id', $nav->id)->count())->toBe(0);
})->with([
    'no label' => [[['kind' => 'heading', 'label' => '  ', 'children' => [['kind' => 'home']]]], 'Item 1: A heading needs a label.'],
    'nothing under it' => [[['kind' => 'home'], ['kind' => 'heading', 'label' => 'Visit']], 'Item 2: A heading needs at least one item under it.'],
    'the third level' => [[['kind' => 'home', 'children' => [['kind' => 'home', 'children' => [['kind' => 'heading', 'label' => 'Visit']]]]]], 'Item 1 › 1 › 1: A heading can’t be at the third level, where nothing can go under it.'],
]);

it('refuses a fourth level, a bad address and an unknown target, naming the item', function (array $tree, string $message): void {
    $nav = navCreate($this->editor);

    expect(fn () => navSave($this->editor, $nav, $tree))->toThrow(ValidationException::class, $message);
})->with([
    'too deep' => [[['kind' => 'home', 'children' => [['kind' => 'home', 'children' => [['kind' => 'home', 'children' => [['kind' => 'home']]]]]]]], 'Item 1 › 1 › 1 › 1'],
    'bad url' => [[['kind' => 'url', 'url' => 'javascript:alert(1)']], 'Item 1'],
    'missing content' => [[['kind' => 'content', 'target_id' => '01j00000000000000000000000']], 'Item 1'],
]);

it('saves a cleared label as the target\'s title, and takes a path on this site but not another site\'s //address', function (): void {
    $nav = navCreate($this->editor);

    navSave($this->editor, $nav, [['kind' => 'home', 'label' => '  '], ['kind' => 'url', 'url' => ' /about/ ']]);

    $items = NavigationItem::query()->where('navigation_id', $nav->id)->orderBy('position')->get();
    expect($items[0]->label)->toBeNull()
        ->and($items[1]->url)->toBe('/about/')
        ->and(fn () => navSave($this->editor, $nav, [['kind' => 'url', 'url' => '//elsewhere.example/']]))->toThrow(ValidationException::class, 'Item 1');
});

/**
 * What one url item is stored as after a save, as [kind, target_id, url].
 *
 * @return array{0: string, 1: string|null, 2: string|null}
 */
function navStoredAs(object $test, string $address): array
{
    $nav = navCreate($test->editor);
    navSave($test->editor, $nav, [['kind' => 'url', 'url' => $address, 'label' => 'As typed']]);
    $item = NavigationItem::query()->where('navigation_id', $nav->id)->sole();

    expect($item->label)->toBe('As typed');

    return [(string) $item->kind, $item->target_id, $item->url];
}

it('stores the site\'s own home page address as a home item', function (string $address): void {
    expect(navStoredAs($this, $address))->toBe(['home', null, null]);
})->with(['absolute' => 'http://localhost/', 'bare' => 'http://localhost', 'relative' => '/']);

it('stores an article\'s current or old path as a content item', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    Path::query()->create(['path' => 'old-about', 'target_type' => 'content', 'target_id' => $about->id, 'is_current' => false]);

    expect(navStoredAs($this, 'http://localhost/about/'))->toBe(['content', $about->id, null])
        ->and(navStoredAs($this, '/about'))->toBe(['content', $about->id, null])
        ->and(navStoredAs($this, 'http://localhost/old-about/'))->toBe(['content', $about->id, null]);
});

it('stores a category\'s current path as a term item', function (): void {
    $news = taxonomyTerm('category', 'News');
    app(PathProjection::class)->syncTerm($news);
    $path = addrCurrent('term', $news->id);

    expect(navStoredAs($this, "http://localhost/{$path}/"))->toBe(['term', $news->id, null]);
});

it('keeps an unknown path on this site as a path, its query and place kept', function (): void {
    expect(navStoredAs($this, 'http://localhost/nowhere/page/?a=1#top'))->toBe(['url', null, '/nowhere/page/?a=1#top'])
        ->and(navStoredAs($this, 'http://localhost'.'?a=1'))->toBe(['url', null, '/?a=1']);
});

it('knows the site\'s address by host alone, in any case and with www.', function (string $address): void {
    expect(navStoredAs($this, $address))->toBe(['url', null, '/nowhere/']);
})->with(['www' => 'https://www.localhost/nowhere/', 'capitals' => 'HTTP://LOCALHOST/nowhere/']);

it('leaves an outside address, an email and a phone link as typed', function (string $address): void {
    expect(navStoredAs($this, $address))->toBe(['url', null, $address]);
})->with(['outside' => 'https://example.org/about/', 'lookalike' => 'https://localhost.example.org/about/', 'email' => 'mailto:hi@example.org', 'phone' => 'tel:+441234']);

it('draws each own-site address at the right place', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [
        ['kind' => 'url', 'url' => 'http://localhost/', 'label' => 'Start'],
        ['kind' => 'url', 'url' => 'http://localhost/about/'],
        ['kind' => 'url', 'url' => 'http://localhost/nowhere/?a=1', 'label' => 'Elsewhere'],
    ]);

    $tree = NavigationTree::for($nav, '/');

    expect(array_column($tree, 'url'))->toBe(['/', '/about/', '/nowhere/?a=1'])
        ->and($tree[1]['label'])->toBe('About');
});

it('writes nothing when the person may not manage navigation', function (): void {
    $author = User::factory()->create()->assignRole('author');

    expect(fn () => app(CreateNavigation::class)->execute(new CreateNavigationInput('Main', (string) $author->id)))
        ->toThrow(AuthorizationException::class);
    expect(Navigation::query()->count())->toBe(0);
});

it('renames, saves and deletes nothing for a person who may not manage navigation', function (): void {
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home']]);
    $author = (string) User::factory()->create()->assignRole('author')->id;

    expect(fn () => app(RenameNavigation::class)->execute(new RenameNavigationInput($nav->id, 'Renamed', $author)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($nav->id, [], $author)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteNavigation::class)->execute(new DeleteNavigationInput($nav->id, $author)))->toThrow(AuthorizationException::class);

    expect($nav->refresh()->name)->toBe('Main')
        ->and(NavigationItem::query()->where('navigation_id', $nav->id)->count())->toBe(1);
});

it('writes no heading for a person who may not manage navigation', function (): void {
    $nav = navCreate($this->editor);
    $author = (string) User::factory()->create()->assignRole('author')->id;

    expect(fn () => app(SaveNavigationItems::class)->execute(new SaveNavigationItemsInput($nav->id, [['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'home']]]], $author)))
        ->toThrow(AuthorizationException::class);
    expect(NavigationItem::query()->where('navigation_id', $nav->id)->count())->toBe(0);
});

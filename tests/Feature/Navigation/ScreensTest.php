<?php

declare(strict_types=1);

use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Menus\NavigationTree;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('lists menus with where each is shown', function (): void {
    navCreate($this->editor, 'Main');

    $this->actingAs($this->editor)->get('/admin/navigation')->assertInertia(fn (Assert $page) => $page
        ->component('navigation/index')
        ->where('navigations.0.name', 'Main')
        ->where('navigations.0.shownIn', []));
});

it('saves a tree from the editor', function (): void {
    $nav = navCreate($this->editor);

    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$nav->id}/items", ['tree' => [['kind' => 'home']]])
        ->assertRedirect();

    expect(NavigationItem::query()->count())->toBe(1);
});

it('saves a home item from the screen\'s request, and the menu draws a link to the front page', function (): void {
    $nav = navCreate($this->editor);

    $this->actingAs($this->editor)
        ->put("/admin/navigation/{$nav->id}/items", ['tree' => [['kind' => 'home', 'label' => '']]])
        ->assertRedirect();

    $item = NavigationItem::query()->where('navigation_id', $nav->id)->sole();
    $drawn = NavigationTree::for($nav, '/about/');

    expect($item->kind)->toBe('home')
        ->and($item->target_id)->toBeNull()
        ->and($item->url)->toBeNull()
        ->and($drawn[0]['url'])->toBe('/')
        ->and($drawn[0]['label'])->toBe('Home');
});

it('describes a heading on the menu screen, and says when nothing under it is shown', function (): void {
    $gone = addrPublished($this->editor, 'Gone', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [
        ['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'home']]],
        ['kind' => 'heading', 'label' => 'Archive', 'children' => [['kind' => 'content', 'target_id' => $gone->id]]],
    ]);
    pageTrash($gone, $this->editor);

    $this->actingAs($this->editor)->get("/admin/navigation/{$nav->id}")->assertInertia(fn (Assert $page) => $page
        ->component('navigation/edit')
        ->where('items.0.kind', 'heading')
        ->where('items.0.label', 'Visit')
        ->where('items.0.targetId', null)
        ->where('items.0.url', null)
        ->where('items.0.target', 'Heading')
        ->where('items.0.warning', null)
        ->where('items.1.target', 'Heading')
        ->where('items.1.warning', 'Not shown: nothing under it is shown')
        ->where('items.1.children.0.warning', 'Page is in the trash'));
});

it('refuses the screens to people without the permission', function (): void {
    $author = User::factory()->create()->assignRole('author');

    $this->actingAs($author)->get('/admin/navigation')->assertForbidden();
});

it('sends the theme\'s settings group to Site › Themes', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/settings/theme.good')->assertRedirect('/admin/themes');
});

it('leaves the theme\'s settings group out of the Settings tabs, but keeps a group whose screen is a Settings tab', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($admin)->get('/admin/settings/general')->assertInertia(fn (Assert $page) => $page
        ->where('settingsNav', fn ($groups): bool => ! collect($groups)->contains('key', 'theme.good') && collect($groups)->contains('key', 'addresses')));
});

it('opens a menu\'s editor with each item described, one that isn\'t shown marked, and the theme\'s places', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $draft = lifecycleDraft($this->editor, 'Not yet', 'page');
    $nav = navCreate($this->editor, 'Main');
    navSave($this->editor, $nav, [
        ['kind' => 'home'],
        ['kind' => 'content', 'target_id' => $about->id, 'children' => [['kind' => 'content', 'target_id' => $draft->id]]],
    ]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    $this->actingAs($this->editor)->get("/admin/navigation/{$nav->id}")->assertInertia(fn (Assert $page) => $page
        ->component('navigation/edit')
        ->where('navigation.name', 'Main')
        ->where('items.0.target', 'Home')
        ->where('items.1.target', 'Page: About')
        ->where('items.1.warning', null)
        ->where('items.1.children.0.warning', 'Not shown: the page isn\'t published')
        ->where('slots.0.label', 'Header')
        ->where('slots.0.navigationId', $nav->id)
        ->where('slots.1.navigationId', null));
});

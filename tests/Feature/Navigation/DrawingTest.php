<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Menus\NavigationTree;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    $this->app['env'] = 'local';
    themeActivate('good');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('draws the bound menu with the current page marked', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home'], ['kind' => 'content', 'target_id' => $about->id]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    $html = addrGet('/about/')->assertOk()->getContent();

    expect($html)->toContain('<nav aria-label="Main">')
        ->toContain('<a href="/about/" aria-current="page">About</a>')
        ->toContain('<a href="/">Home</a>');
});

it('leaves out an item whose page is no longer published', function (): void {
    $gone = addrPublished($this->editor, 'Gone', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home'], ['kind' => 'content', 'target_id' => $gone->id]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));
    pageTrash($gone, $this->editor);

    expect(addrGet('/')->assertOk()->getContent())->toContain('>Home</a>')->not->toContain('Gone</a>');
});

it('labels an item with the page\'s published title, never a draft\'s', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    pageSaveDraft($about, 'About us, a draft');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'content', 'target_id' => $about->id]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    expect(addrGet('/')->assertOk()->getContent())->toContain('<a href="/about/">About</a>')->not->toContain('a draft');
});

it('draws a heading as a button that controls the list of its items, open until a script folds it', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [['kind' => 'home'], ['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'content', 'target_id' => $about->id]]]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', 'primary', $nav->id, (string) $this->editor->id));

    // At /, Home is the page being shown and carries aria-current, so ask for About's page.
    $html = (string) addrGet('/about/')->assertOk()->getContent();
    $dom = dtDom($html);
    $button = $dom->query('//nav[@aria-label="Main"]//button[@data-teme-heading]')->item(0);

    expect($button)->toBeInstanceOf(DOMElement::class)
        ->and($button->getAttribute('type'))->toBe('button')
        ->and($button->getAttribute('aria-expanded'))->toBe('true')
        ->and($button->getAttribute('aria-controls'))->toBe('teme-menu-primary_1-2')
        ->and(trim($button->textContent))->toBe('Visit')
        ->and($dom->query('//li[button[@data-teme-heading]]/ul[@id="teme-menu-primary_1-2"][@data-teme-submenu][not(@hidden)]/li/a[@href="/about/"]')->length)->toBe(1)
        ->and($dom->query('//nav[@aria-label="Main"]//a[not(@href) or @href="" or @href="#"]')->length)->toBe(0)
        ->and($html)->toContain('<a href="/">Home</a>');
});

it('leaves out a heading with nothing drawn under it, and gives a theme each item’s heading flag and a heading no address', function (): void {
    $gone = addrPublished($this->editor, 'Gone', 'page');
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [
        ['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'home']]],
        ['kind' => 'heading', 'label' => 'Archive', 'children' => [['kind' => 'content', 'target_id' => $gone->id]]],
    ]);
    pageTrash($gone, $this->editor);

    $tree = NavigationTree::for($nav, '/');

    expect($tree)->toHaveCount(1)
        ->and($tree[0])->toMatchArray(['label' => 'Visit', 'url' => null, 'current' => false, 'opensNewTab' => false, 'heading' => true])
        ->and($tree[0]['children'])->toHaveCount(1)
        ->and($tree[0]['children'][0])->toMatchArray(['label' => 'Home', 'url' => '/', 'current' => true, 'heading' => false]);
});

it('draws a menu in four queries however many items it has', function (): void {
    $pages = collect(range(1, 6))->map(fn (int $i) => addrPublished($this->editor, "Page {$i}", 'page'));
    $terms = collect(range(1, 4))->map(fn (int $i) => tap(taxonomyTerm('category', "Topic {$i}"), fn ($term) => app(PathProjection::class)->syncTerm($term)));
    $nav = navCreate($this->editor);
    navSave($this->editor, $nav, [
        ['kind' => 'home'],
        ...$pages->map(fn ($page) => ['kind' => 'content', 'target_id' => $page->id, 'children' => [['kind' => 'url', 'url' => 'https://example.org/']]])->all(),
        ...$terms->map(fn ($term) => ['kind' => 'term', 'target_id' => $term->id])->all(),
    ]);

    DB::enableQueryLog();
    $tree = NavigationTree::for($nav, '/');

    expect(DB::getQueryLog())->toHaveCount(4)
        ->and($tree)->toHaveCount(11)
        ->and($tree[1]['children'])->toHaveCount(1);
});

it('says loudly, outside production, that a slot has no menu', function (): void {
    expect(addrGet('/')->assertOk()->getContent())->toContain('data-teme-unbound="primary"');
});

it('draws nothing for an unbound slot in production', function (): void {
    $this->app['env'] = 'production';

    expect(addrGet('/')->assertOk()->getContent())->not->toContain('data-teme-unbound');
});

it('names the unbound slots in the Status banner until each has a menu', function (): void {
    $this->actingAs($this->editor)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('statusBanner.level', 'warning')
        ->where('statusBanner.message', fn (string $message): bool => str_contains($message, 'Header') && str_contains($message, 'Footer')));

    $nav = navCreate($this->editor);

    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('good', $slot, $nav->id, (string) $this->editor->id));
    }

    $this->actingAs($this->editor)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('statusBanner', null));
});

<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $editor = User::factory()->create()->assignRole('editor');
    $about = addrPublished($editor, 'About', 'page');
    $menu = navCreate($editor, 'Primary');
    navSave($editor, $menu, [
        ['kind' => 'home'],
        ['kind' => 'heading', 'label' => 'Visit', 'children' => [
            ['kind' => 'content', 'target_id' => $about->id],
            ['kind' => 'url', 'url' => 'https://harbour.example/', 'label' => 'Harbour'],
        ]],
    ]);

    foreach (['primary', 'footer'] as $slot) {
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $editor->id));
    }

    // The wide header's heading: Pest's desktop window is wide enough to show it.
    $this->folded = "document.querySelector('.nav-wide [data-teme-heading]')?.getAttribute('aria-expanded') === 'false'";
    $this->open = "document.querySelector('.nav-wide [data-teme-heading]')?.getAttribute('aria-expanded') === 'true'";
    $this->linkShown = "document.querySelector('.nav-wide [data-teme-submenu] a[href=\"https://harbour.example/\"]').checkVisibility()";
});

it('folds a heading’s items as the page loads, and opens and folds them from its button', function (): void {
    $page = visit('/about/');

    cmWaitUntil($page, "{$this->folded} && !{$this->linkShown}");

    $page->click('.nav-wide [data-teme-heading]');

    cmWaitUntil($page, "{$this->open} && {$this->linkShown}");

    $page->click('.nav-wide [data-teme-heading]');

    cmWaitUntil($page, "{$this->folded} && !{$this->linkShown}");
});

it('opens a heading with Enter, and folds it with Escape from inside its list, the focus back on its button', function (): void {
    $page = visit('/about/');

    cmWaitUntil($page, $this->folded);

    $page->keys('.nav-wide [data-teme-heading]', 'Enter');

    cmWaitUntil($page, "{$this->open} && {$this->linkShown}");

    $page->keys('.nav-wide [data-teme-submenu] a[href="https://harbour.example/"]', 'Escape');

    cmWaitUntil($page, "{$this->folded} && document.activeElement === document.querySelector('.nav-wide [data-teme-heading]')");
});

it('passes axe with a heading folded and open, in both schemes', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);

    $page = visit('/about/');

    cmWaitUntil($page, $this->folded);

    $page->assertNoAccessibilityIssues(3)->click('.nav-wide [data-teme-heading]');

    cmWaitUntil($page, "{$this->open} && {$this->linkShown}");

    $page->assertNoAccessibilityIssues(3);
})->with(['light', 'dark']);

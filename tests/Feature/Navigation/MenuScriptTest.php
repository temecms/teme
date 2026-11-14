<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->about = addrPublished($this->editor, 'About', 'page');
    $this->visit = [['kind' => 'heading', 'label' => 'Visit', 'children' => [['kind' => 'content', 'target_id' => $this->about->id]]]];

    // The default theme's two places, each showing a menu of its own with this tree.
    $this->show = function (array $primary, array $footer = [['kind' => 'home']]): void {
        foreach (['primary' => $primary, 'footer' => $footer] as $slot => $tree) {
            $nav = navCreate($this->editor, ucfirst($slot));
            navSave($this->editor, $nav, $tree);
            app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $nav->id, (string) $this->editor->id));
        }
    };
});

it('gives each drawing of a menu its own ids, the default theme’s header drawing it twice', function (): void {
    ($this->show)($this->visit, $this->visit);

    $dom = dtDom((string) addrGet('/about/')->assertOk()->getContent());
    $controls = [];

    foreach ($dom->query('//button[@data-teme-heading]') as $button) {
        $controls[] = $button->getAttribute('aria-controls');
    }

    expect($controls)->toBe(['teme-menu-primary_1-1', 'teme-menu-primary_2-1', 'teme-menu-footer_1-1']);

    foreach ($controls as $id) {
        expect($dom->query('//ul[@id="'.$id.'"][@data-teme-submenu]')->length)->toBe(1);
    }
});

it('writes core’s menu script once a page where a heading is drawn, within 1 KB compressed', function (): void {
    ($this->show)($this->visit, $this->visit);

    $html = (string) addrGet('/about/')->assertOk()->getContent();
    preg_match('#<script data-teme="menus">(.+?)</script>#s', $html, $script);

    // The script is really drawn: an empty match would pass the size check too.
    expect(substr_count($html, '<script data-teme="menus">'))->toBe(1)
        ->and($script[1] ?? '')->toContain('[data-teme-heading]')
        ->toContain('aria-controls')
        ->toContain('Escape')
        ->and(strlen((string) gzencode($script[1] ?? '', 9)))->toBeLessThanOrEqual(1024);
});

it('keeps a folded list hidden in core’s layer whatever a theme’s display says, and writes no script where no heading is drawn', function (): void {
    ($this->show)([['kind' => 'home'], ['kind' => 'content', 'target_id' => $this->about->id]]);

    $html = (string) addrGet('/about/')->assertOk()->getContent();

    expect($html)->toContain('[data-teme-submenu][hidden]{display:none!important}')
        ->not->toContain('data-teme="menus"')
        ->not->toContain('data-teme-heading');
});

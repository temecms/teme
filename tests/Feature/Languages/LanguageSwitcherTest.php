<?php

declare(strict_types=1);

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\Languages;
use Tests\Fixtures\Languages\LanguageSite;
use Tests\Fixtures\Languages\LanguageTheme;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    Languages::set(['fr', 'es']);
    $this->theme = LanguageTheme::install();
});

afterEach(function (): void {
    LanguageTheme::remove($this->theme);
});

/**
 * Binds a menu holding one language switcher, with these options, to the
 * fixture theme's footer.
 *
 * @param  array<string, bool>  $options
 */
function lsMenu(LanguageSite $site, array $options = [], ?string $label = null): void
{
    $nav = navCreate($site->admin);
    navSave($site->admin, $nav, [['kind' => 'languages', 'options' => $options === [] ? null : $options, 'label' => $label]]);
    app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('languages', 'footer', $nav->id, (string) $site->admin->id));
}

/**
 * The links under one part of the page: each one's text, address and
 * whether it is marked current.
 *
 * @return list<array{0: string, 1: string, 2: bool}>
 */
function lsLinks(string $html, string $part): array
{
    $links = [];

    foreach (dtDom($html)->query("//div[@{$part}]//a") ?: [] as $link) {
        /** @var DOMElement $link */
        $links[] = [trim($link->textContent), $link->getAttribute('href'), $link->getAttribute('aria-current') === 'page'];
    }

    return $links;
}

it('draws a link to each of three versions from a menu\'s language switcher, the current one marked', function (): void {
    lsMenu($this->site);

    expect(lsLinks((string) addrGet('/fr/bonjour/')->assertOk()->getContent(), 'data-menu'))->toBe([
        ['English (United Kingdom)', '/hello/', false],
        ['Français', '/fr/bonjour/', true],
        ['Español', '/es/hola/', false],
    ]);
});

it('draws nothing on a page with no other version', function (): void {
    lsMenu($this->site);
    $html = (string) addrGet('/alone/')->assertOk()->getContent();

    expect(lsLinks($html, 'data-menu'))->toBe([])
        ->and(lsLinks($html, 'data-component'))->toBe([]);
});

it('gives the same links from the component as from the menu item', function (): void {
    lsMenu($this->site);
    $html = (string) addrGet('/hello/')->assertOk()->getContent();

    $languages = [];

    foreach (dtDom($html)->query('//div[@data-component]//a') ?: [] as $link) {
        /** @var DOMElement $link */
        $languages[] = $link->getAttribute('lang').'|'.$link->getAttribute('hreflang');
    }

    expect(lsLinks($html, 'data-component'))->toBe(lsLinks($html, 'data-menu'))
        ->and(lsLinks($html, 'data-component'))->toHaveCount(3)
        ->and($languages)->toBe(['en-GB|en-GB', 'fr|fr', 'es|es']);
});

it('follows the switcher\'s options: short codes, the current one left out, and a dropdown', function (): void {
    lsMenu($this->site, ['show_names' => false, 'hide_current' => true, 'dropdown' => true]);
    $html = (string) addrGet('/hello/')->assertOk()->getContent();

    expect(lsLinks($html, 'data-menu'))->toBe([['FR', '/fr/bonjour/', false], ['ES', '/es/hola/', false]])
        ->and(headValue(dtDom($html), '//div[@data-menu]//button[@data-teme-heading]'))->toBe('English (United Kingdom)');
});

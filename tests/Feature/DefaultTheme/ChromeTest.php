<?php

declare(strict_types=1);

use App\Domain\Site\SiteDates;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    $this->site = dtSite($this->author);
});

it('makes the site title the only h1 on the front page, and not an h1 elsewhere', function (): void {
    $front = dtDom(addrGet('/')->getContent());
    $about = dtDom(addrGet('/about/')->getContent());

    expect($front->query('//header//h1')->length)->toBe(1)
        ->and($about->query('//header//h1')->length)->toBe(0)
        ->and($about->query('//h1')->length)->toBe(1);
});

it('draws both menus as labelled landmarks, and the mobile menu without script', function (): void {
    $dom = dtDom(addrGet('/about/')->getContent());

    // The primary menu twice, as the prototype draws it: for wide screens, and in the small screens' disclosure.
    expect($dom->query('//nav[@aria-label="Primary"]')->length)->toBe(2)
        ->and($dom->query('//details[contains(@class,"nav-narrow")]/summary')->length)->toBe(1)
        ->and($dom->query('//details[contains(@class,"nav-narrow")]//nav[@aria-label="Primary"]')->length)->toBe(1)
        ->and($dom->query('//nav[@aria-label="Footer"]')->length)->toBe(1)
        ->and($dom->query('//a[@aria-current="page"]')->length)->toBeGreaterThanOrEqual(1)
        // The theme draws no script; core's own (1.7c) asks who is reading, and its graph is data.
        ->and($dom->query('//script[not(@type="speculationrules") and not(@data-teme="session") and not(@type="application/ld+json")]')->length)->toBe(0);
});

it('offers search as a plain form, each with its own field', function (): void {
    $dom = dtDom(addrGet('/')->getContent());

    expect($dom->query('//header//form[@role="search" and @method="get"]//input[@name="s"]')->length)->toBe(2)
        ->and($dom->query('//input[@id="search-wide"]')->length)->toBe(1)
        ->and($dom->query('//input[@id="search-narrow"]')->length)->toBe(1);
});

it('has no Powered by line, and takes the copyright year from core', function (): void {
    $html = addrGet('/')->getContent();

    expect(strtolower($html))->not->toContain('powered by')
        ->and($html)->toContain('© '.SiteDates::inSite('now')->year);
});

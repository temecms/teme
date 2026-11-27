<?php

declare(strict_types=1);

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

it('follows the reader\'s system by default, with dark tokens behind the media query', function (): void {
    $html = addrGet('/')->getContent();

    expect($html)->toContain('data-scheme="auto"')
        ->toContain(':root[data-scheme="dark"]{--teme-base:#141413')
        ->toContain('@media (prefers-color-scheme: dark){:root[data-scheme="auto"]{--teme-base:#141413');
});

it('forces dark when the owner chooses it', function (): void {
    designPublish(['theme.default' => ['color_scheme' => 'dark']]);

    expect(addrGet('/')->getContent())->toContain('data-scheme="dark"');
});

it('gives the owner\'s accent to both schemes, lightened just enough for the dark one (I-457)', function (): void {
    // #0b5394 reaches 7.57:1 on #fcfbf8; on #141413 Task 0b's darkVariant() lightens it to #4283c8.
    designPublish(['design' => ['brand' => '#0b5394']]);

    expect(addrGet('/')->getContent())
        ->toContain('--teme-accent:#0b5394;--teme-on-accent:#ffffff')
        ->toContain(':root[data-scheme="dark"]{--teme-base:#141413;--teme-contrast:#eceae5;--teme-accent:#4283c8;--teme-on-accent:#111111;--teme-brand:#0b5394}')
        ->toContain('{:root[data-scheme="auto"]{--teme-base:#141413;--teme-contrast:#eceae5;--teme-accent:#4283c8;--teme-on-accent:#111111;--teme-brand:#0b5394}}')
        ->not->toContain('#ff8a7a');
});

it('colours the header and the footer with the accent when chosen', function (): void {
    designPublish(['theme.default' => ['header_color' => 'accent']]);

    $dom = dtDom(addrGet('/')->getContent());

    expect($dom->query('//header[@data-tone="accent"]')->length)->toBe(1)
        ->and($dom->query('//footer[@data-tone="accent"]')->length)->toBe(1);
});

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

it('meets the floor', function (string $what): void {
    $url = dtUrl($this->site, $what);
    $response = addrGet($url);
    $dom = dtDom($response->getContent());

    expect($dom->query('//main')->length)->toBe(1)
        ->and($dom->query('//main[@id="main"]')->length)->toBe(1)
        ->and($dom->query('//body/*[1][self::a][@href="#main"]')->length)->toBe(1)
        ->and($dom->query('//html[@lang]')->length)->toBe(1)
        ->and($dom->query('//h1')->length)->toBe(1)
        ->and($dom->query('//nav[not(@aria-label)]')->length)->toBe(0)
        ->and($dom->query('//img[not(@alt)]')->length)->toBe(0)
        ->and($dom->query('//img[not(@width) or not(@height)]')->length)->toBe(0)
        ->and(dtHeadingsInOrder($dom))->toBeTrue();
})->with('templates');

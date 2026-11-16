<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
});

it('writes the page\'s language and direction on html', function (): void {
    LanguageSite::build();

    expect(headValue(headDom('/fr/bonjour/'), '/html/@lang'))->toBe('fr')
        ->and(headValue(headDom('/fr/bonjour/'), '/html/@dir'))->toBe('ltr')
        ->and(headValue(headDom('/fr/'), '/html/@dir'))->toBe('ltr');
});

it('draws a right-to-left site right to left', function (string $tag): void {
    $admin = User::factory()->create()->assignRole('administrator');
    siteSetLanguage($admin, $tag);
    LanguageSite::version($admin, 'Shalom', null, null, [], publish: true);

    foreach (['/shalom/', '/'] as $address) {
        expect(headValue(headDom($address), '/html/@lang'))->toBe($tag)
            ->and(headValue(headDom($address), '/html/@dir'))->toBe('rtl');
    }
})->with(['ar', 'yi']);

it('writes dir on every public page, ltr on an English site', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    LanguageSite::version($admin, 'Hello', null, null, [], publish: true);

    foreach (['/hello/', '/', '/?s=hello'] as $address) {
        expect(headValue(headDom($address), '/html/@dir'))->toBe('ltr');
    }

    expect(headValue(headDom('/nothing-here/', 404), '/html/@dir'))->toBe('ltr');
});

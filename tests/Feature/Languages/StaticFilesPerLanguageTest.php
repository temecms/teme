<?php

declare(strict_types=1);

use App\Domain\Cache\StaticPages;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->folder = cacheOnStatic();
});

afterEach(fn () => cacheOff($this->folder));

it('writes a page that says its language as a file, its html lang the page\'s language', function (string $address, string $language, int $status = 200): void {
    // In production, where a page's own headers are the only ones (I-291).
    $this->app['env'] = 'production';

    $miss = addrGet($address)->assertStatus($status)->assertHeader('Content-Language', $language)->assertHeader('X-Teme-Cache', 'MISS');
    $file = cacheStaticFile($address);
    // A 404 is never a file (the web server would answer it 200); its page, as drawn and as the store keeps it, is checked instead.
    $page = $status === 404 ? (string) addrGet($address)->assertNotFound()->getContent() : $file;

    expect($status === 404 ? $file : $page)->toBe($status === 404 ? null : $miss->getContent())
        ->and(preg_match('/<html lang="([^"]+)"/', (string) $page, $lang))->toBe(1)
        ->and(strtolower($lang[1]))->toBe(strtolower($language));
})->with([
    'the default language' => ['/hello/', 'en-GB'],
    'another language' => ['/fr/bonjour/', 'fr'],
    'another language\'s home' => ['/fr/', 'fr'],
    'another language\'s archive' => ['/fr/category/actualites/', 'fr'],
    'another language\'s 404' => ['/fr/nothing-here/', 'fr', 404],
]);

it('lets every language the site can add say so on a page that becomes a file', function (): void {
    $plain = (new ReflectionClassConstant(StaticPages::class, 'PLAIN'))->getValue();

    expect($plain['content-language'])->toBe(array_map(strtolower(...), array_keys((array) config('languages'))));
});

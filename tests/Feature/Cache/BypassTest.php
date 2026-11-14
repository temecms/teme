<?php

declare(strict_types=1);

use App\Domain\Cache\SignedInMarker;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

it('passes a signed-in reader by, and draws their page afresh', function (): void {
    pageArticle($this->author, 'Big story');
    $signedIn = [SignedInMarker::COOKIE => '1'];

    $first = cacheGet('/big-story/', $signedIn)->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');

    expect($first->getContent())->not->toContain('speculationrules');

    // Nothing was stored for them, and what an anonymous reader stores is never theirs.
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
    $again = cacheGet('/big-story/', $signedIn)->assertHeader('X-Teme-Cache', 'BYPASS (signed in)');

    expect($again->getContent())->not->toContain('speculationrules');
});

it('passes a request for another host by, and stores nothing for it (D3)', function (): void {
    pageArticle($this->author, 'Big story');

    cacheGet('http://other.example/big-story/')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (host)');

    expect(DB::table('page_cache_tags')->count())->toBe(0);
    addrGet('/big-story/')->assertHeader('X-Teme-Cache', 'MISS');
});

it('passes a design preview by, even when the page is stored', function (): void {
    addrGet('/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

    addrGet('/?teme_design_preview=1&expires=1&signature=forged')
        ->assertForbidden()
        ->assertHeader('X-Teme-Cache', 'BYPASS (design preview)');
});

it('passes a search it can\'t key by', function (): void {
    // Passes from the start: PageKey has no key for it (Task 1), and the middleware says why (Task 3).
    addrGet('/?s[]=lifeboat')->assertOk()->assertHeader('X-Teme-Cache', 'BYPASS (query)');
});

<?php

declare(strict_types=1);

use App\Domain\Api\ApiResponse;
use App\Domain\Api\Delivery\DeliverySwitches;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Http\Api\Middleware\EnsureApiOn;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\Fixtures\Api\Switches;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://example.com');
    URL::forceScheme('https');
    Route::middleware(['api', EnsureApiOn::class, 'throttle:api'])->prefix('api/v1')
        ->get('_probe', fn () => ApiResponse::one(['ok' => true], ['self' => url('/api/v1/_probe')]));
});

it('adds Apps and AI tools to Search and social, after robots.txt and before the 404 log', function (): void {
    $sections = array_map(fn (SearchAndSocialSection $section): string => $section->key(), SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $ours = ['robots-txt', 'apps-and-ai', 'not-found-log'];
    $section = collect(SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)))->first(fn (SearchAndSocialSection $section): bool => $section->key() === 'apps-and-ai');
    $group = app(SettingsRegistry::class)->find(SearchAndSocial::KEY);
    $api = $group?->setting(DeliverySwitches::API);
    $llms = $group?->setting(DeliverySwitches::LLMS);

    expect(array_values(array_intersect($sections, $ours)))->toBe($ours)
        ->and($section?->label())->toBe('Apps and AI tools')
        ->and($api?->label)->toBe('Let apps and other sites read your published content as data')
        ->and($api?->description)->toBe('Apps, other sites and tools can read your published content as data through the API, at https://example.com/api/v1: posts, pages and your own types, with their fields, terms and images. They see only what readers see: never drafts, private items, what’s behind a password, or fields you hide on the site.')
        ->and($api?->since)->toBe('0.3.0')
        ->and($llms?->label)->toBe('Publish llms.txt')
        ->and($llms?->description)->toBe('A short guide to your site at https://example.com/llms.txt, for AI assistants and coding tools that someone points at it, with a plain-text copy of each listed post and page at its address followed by .md. It lists only what search engines may list, and nothing while the site discourages search engines.')
        ->and($llms?->since)->toBe('0.3.0');
});

it('keeps both switches off on a new site', function (): void {
    $group = app(SettingsRegistry::class)->find(SearchAndSocial::KEY);

    expect(DeliverySwitches::apiOn())->toBeFalse()
        ->and(DeliverySwitches::llmsOn())->toBeFalse()
        ->and($group?->setting(DeliverySwitches::API)?->default)->toBeFalse()
        ->and($group?->setting(DeliverySwitches::LLMS)?->default)->toBeFalse();
});

it('answers api_off to every route of /api/v1 while the API is off, and not_found to an address no route matches and outside v1', function (): void {
    $this->get('/api/v1/_probe')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertExactJson([
            'type' => 'about:blank', 'title' => 'Not Found', 'status' => 404,
            'detail' => 'This site doesn’t share its content through the API.',
            'instance' => '/api/v1/_probe', 'code' => 'api_off',
        ]);

    foreach (['/api/v1/nothing', '/api', '/api/v2/items'] as $path) {
        $this->get($path)->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    Switches::apiOn();

    $this->get('/api/v1/_probe')->assertOk()->assertJsonPath('data.ok', true);
    $this->get('/api/v1/nothing')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('answers at once when an administrator ticks the API on, with the page cache on', function (): void {
    $folder = cacheOn();

    $this->get('/api/v1/_probe')->assertNotFound()->assertJsonPath('code', 'api_off');

    Switches::apiOn();

    $this->get('/api/v1/_probe')->assertOk()->assertJsonPath('data.ok', true);

    cacheOff($folder);
});

it('stores the api_off answer for ten seconds, tagged seo, until the API is ticked on', function (): void {
    $folder = cacheOn();
    URL::forceRootUrl('http://localhost');
    URL::forceScheme('http');

    $this->get('/api/v1/_probe')->assertNotFound()->assertJsonPath('code', 'api_off')->assertHeader('X-Teme-Cache', 'MISS');
    $stored = cacheEntry('/api/v1/_probe');

    expect($stored?->status)->toBe(404)
        ->and(($stored?->expiresAt ?? 0) - ($stored?->storedAt ?? 0))->toBe(10)
        ->and($stored?->tags)->toContain('seo');

    Switches::apiOn();

    expect(cacheEntry('/api/v1/_probe'))->toBeNull();
    $this->get('/api/v1/_probe')->assertOk();

    cacheOff($folder);
});

it('lets only someone who manages Search and social change them', function (): void {
    $this->seed(RoleSeeder::class);
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('settings')->where('group_key', SearchAndSocial::KEY)->first();

    expect(fn () => scElevated(fn (): mixed => app(UpdateSettings::class)->execute(new UpdateSettingsInput(SearchAndSocial::KEY, [DeliverySwitches::API => true], [], [], 0, (string) $editor->id))))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('settings')->where('group_key', SearchAndSocial::KEY)->first())->toEqual($before)
        ->and(DeliverySwitches::apiOn())->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Domain\Updates\Updater\VersionProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('tells a signed probe the version running, and no one else', function (): void {
    config(['teme.version' => '0.1.0-rc.15']);
    $at = (string) now()->getTimestamp();
    $signed = $at.'.'.hash_hmac('sha256', "teme-version-probe|{$at}", (string) config('app.key'));

    $this->get('/up', ['X-Teme-Probe' => $signed])->assertOk()->assertHeader('X-Teme-Version', '0.1.0-rc.15');
    $this->get('/up')->assertOk()->assertHeaderMissing('X-Teme-Version');
});

it('refuses a probe that is old, from the future, forged or malformed', function (string $header): void {
    $this->get('/up', ['X-Teme-Probe' => $header])->assertOk()->assertHeaderMissing('X-Teme-Version');
})->with([
    'two minutes old' => fn () => ($at = (string) now()->subMinutes(2)->getTimestamp()).'.'.hash_hmac('sha256', "teme-version-probe|{$at}", (string) config('app.key')),
    'two minutes ahead' => fn () => ($at = (string) now()->addMinutes(2)->getTimestamp()).'.'.hash_hmac('sha256', "teme-version-probe|{$at}", (string) config('app.key')),
    'another key' => fn () => ($at = (string) now()->getTimestamp()).'.'.hash_hmac('sha256', "teme-version-probe|{$at}", 'not-the-key'),
    'no dot' => fn () => 'abc',
    'empty' => fn () => '',
]);

it('answers a probe only on /up, never on a page', function (): void {
    $at = (string) now()->getTimestamp();
    $signed = $at.'.'.hash_hmac('sha256', "teme-version-probe|{$at}", (string) config('app.key'));

    $this->get('/', ['X-Teme-Probe' => $signed])->assertHeaderMissing('X-Teme-Version');
});

it('reads the version a site answers with, or null when it answers otherwise', function (): void {
    config(['app.url' => 'https://example.org']);
    Http::preventStrayRequests();
    Http::fake(['https://example.org/up' => Http::sequence()
        ->push('', 200, ['X-Teme-Version' => '0.1.0-rc.15'])
        ->push('', 503)
        ->push('', 200)
        ->pushFailedConnection()]);
    $probe = app(VersionProbe::class);

    expect($probe->version())->toBe('0.1.0-rc.15')
        ->and($probe->version())->toBeNull()
        ->and($probe->version())->toBeNull()
        ->and($probe->version())->toBeNull();
    Http::assertSent(fn (Request $request): bool => preg_match('/^\d+\.[0-9a-f]{64}$/', $request->header('X-Teme-Probe')[0] ?? '') === 1);
});

<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionKind;
use App\Domain\Extensions\Queries\PackagistSearch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    config(['teme.extensions.packagist' => true]);
});

it('searches Packagist for plugins by words and type, and maps each result', function (): void {
    Http::fake(['packagist.org/search.json*' => Http::response(['results' => [
        ['name' => 'acme/link-suggestions', 'description' => 'Suggests links.', 'downloads' => 1234, 'repository' => 'https://github.com/acme/link-suggestions', 'url' => 'https://packagist.org/packages/acme/link-suggestions'],
        ['name' => 'acme/quiet', 'downloads' => 'many'],
    ], 'total' => 2])]);

    $found = app(PackagistSearch::class)->search('links', ExtensionKind::Plugin);

    expect($found)->toBe(['results' => [
        ['name' => 'acme/link-suggestions', 'description' => 'Suggests links.', 'downloads' => 1234, 'url' => 'https://github.com/acme/link-suggestions'],
        ['name' => 'acme/quiet', 'description' => '', 'downloads' => 0, 'url' => null],
    ], 'error' => null]);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://packagist.org/search.json')
        && $request['q'] === 'links' && $request['type'] === 'teme-plugin' && (int) $request['per_page'] === 15);
});

it('asks for themes by their own type', function (): void {
    Http::fake(['packagist.org/*' => Http::response(['results' => []])]);

    app(PackagistSearch::class)->search('dark', ExtensionKind::Theme);

    Http::assertSent(fn (Request $request): bool => $request['type'] === 'teme-theme');
});

it('keeps an answer ten minutes', function (): void {
    Http::fake(['packagist.org/*' => Http::response(['results' => [['name' => 'acme/hello']]])]);

    app(PackagistSearch::class)->search('hello', ExtensionKind::Plugin);
    app(PackagistSearch::class)->search('hello', ExtensionKind::Plugin);
    $this->travel(11)->minutes();
    app(PackagistSearch::class)->search('hello', ExtensionKind::Plugin);

    Http::assertSentCount(2);
});

it('says Packagist didn’t answer when it fails, and keeps nothing', function (Closure $answer): void {
    Http::fake(['packagist.org/*' => $answer]);

    expect(app(PackagistSearch::class)->search('hello', ExtensionKind::Plugin))
        ->toBe(['results' => [], 'error' => 'Packagist didn’t answer. Try again, or use another tab.'])
        ->and(app(PackagistSearch::class)->versions('acme/hello'))
        ->toBe(['versions' => [], 'error' => 'Packagist didn’t answer. Try again, or use another tab.']);
})->with([
    'a server error' => [fn () => fn () => Http::response('down', 503)],
    'no connection' => [fn () => fn () => throw new ConnectionException('timed out')],
    'not JSON' => [fn () => fn () => Http::response('<html>', 200)],
]);

it('lists a package’s stable versions, newest first', function (): void {
    Http::fake(['repo.packagist.org/p2/acme/hello.json' => Http::response(['packages' => ['acme/hello' => [
        ['version' => '2.0.0-beta.1'], ['version' => '1.2.0'], ['version' => 'v1.1.0'], ['version' => 'dev-main'], ['version' => '1.0.0'],
    ]]])]);

    expect(app(PackagistSearch::class)->versions('acme/hello'))->toBe(['versions' => ['1.2.0', 'v1.1.0', '1.0.0'], 'error' => null]);
});

it('asks nothing when Packagist is turned off, or for a name that isn’t a package’s', function (): void {
    Http::fake();
    config(['teme.extensions.packagist' => false]);

    expect(app(PackagistSearch::class)->enabled())->toBeFalse()
        ->and(app(PackagistSearch::class)->search('hello', ExtensionKind::Plugin))->toBe(['results' => [], 'error' => null]);

    config(['teme.extensions.packagist' => true]);

    expect(app(PackagistSearch::class)->versions('../../admin'))->toBe(['versions' => [], 'error' => null]);
    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Domain\Cache\PageEntry;
use App\Domain\Cache\PageKey;
use App\Domain\Cache\PageStore;
use App\Domain\Cache\StaticPages;
use App\Domain\Site\Policy\PagePolicy;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->folder = cacheOnStatic();

    $policy = new PagePolicy;
    $policy->allowScript("console.log('hi')");
    $this->policy = $policy->header('http://localhost');
    $this->shell = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Harbour</title></head><body><p>The harbour</p></body></html>';

    // A page put in the store with these headers beside its type, as the page cache puts one.
    $this->entry = fn (string $uri, string $body, array $headers = []): PageEntry => new PageEntry(
        (string) PageKey::of(Request::create($uri))?->url,
        200,
        ['Content-Type' => ['text/html; charset=UTF-8'], ...$headers],
        $body,
        now()->getTimestamp(),
        now()->getTimestamp() + 3600,
        [(string) PageKey::of(Request::create($uri))?->urlTag()],
    );
    $this->store = fn (string $uri, string $body, array $headers = []): PageEntry => tap(($this->entry)($uri, $body, $headers), fn (PageEntry $entry) => app(PageStore::class)->put($entry));
});

afterEach(fn () => cacheOff($this->folder));

it('still gives a page its file when it carries a policy', function (): void {
    foreach ([
        ['Content-Security-Policy' => [$this->policy]],
        ['Content-Security-Policy-Report-Only' => [$this->policy]],
        ['Reporting-Endpoints' => ['teme-csp="http://localhost/_teme/csp"']],
    ] as $headers) {
        expect(StaticPages::wanted(($this->entry)('/harbour/', $this->shell, $headers)))->toBeTrue();
    }
});

it('writes an enforced policy into the file right after its charset, without what only a header carries', function (): void {
    ($this->store)('/harbour/', $this->shell, ['Content-Security-Policy' => [$this->policy], 'Reporting-Endpoints' => ['teme-csp="http://localhost/_teme/csp"']]);

    $file = (string) cacheStaticFile('/harbour/');
    $at = strpos($file, '<meta charset="utf-8">') + strlen('<meta charset="utf-8">');

    expect(substr($file, $at, 52))->toBe('<meta http-equiv="Content-Security-Policy" content="');

    $meta = dtDom($file)->query('//meta[@http-equiv="Content-Security-Policy"]');

    expect($meta->length)->toBe(1)
        ->and(PagePolicy::forMeta($this->policy))->not->toBe('')
        ->and($meta->item(0)?->getAttribute('content'))->toBe(PagePolicy::forMeta($this->policy))
        ->and($file)->not->toContain('frame-ancestors')
        ->and(preg_replace('/<meta http-equiv="Content-Security-Policy" content="[^"]*">/', '', $file))->toBe($this->shell);
});

it("writes a report-only page's file as it was stored", function (): void {
    ($this->store)('/harbour/', $this->shell, ['Content-Security-Policy-Report-Only' => [$this->policy], 'Reporting-Endpoints' => ['teme-csp="http://localhost/_teme/csp"']]);

    expect(cacheStaticFile('/harbour/'))->toBe($this->shell);
});

it('writes no file for an enforced page with no charset tag, and PHP serves it', function (): void {
    $entry = ($this->store)('/harbour/', '<p>The harbour</p>', ['Content-Security-Policy' => [$this->policy]]);

    expect(StaticPages::wanted($entry))->toBeFalse()
        ->and(cacheStaticFile('/harbour/'))->toBeNull()
        ->and(cacheEntry('/harbour/')?->body)->toBe('<p>The harbour</p>');
});

it('deletes the file an earlier draw left when the page can no longer have one', function (): void {
    ($this->store)('/harbour/', '<p>The harbour</p>');

    expect(cacheStaticFile('/harbour/'))->toBe('<p>The harbour</p>');

    $entry = ($this->store)('/harbour/', '<p>The harbour, again</p>', ['Content-Security-Policy' => [$this->policy]]);

    expect(StaticPages::wanted($entry))->toBeFalse()
        ->and(cacheStaticFile('/harbour/'))->toBeNull();
});

it('still refuses a file to a page with another header', function (): void {
    $entry = ($this->store)('/harbour/', $this->shell, ['Content-Security-Policy' => [$this->policy], 'X-Robots-Tag' => ['noindex']]);

    expect(StaticPages::wanted($entry))->toBeFalse()
        ->and(cacheStaticFile('/harbour/'))->toBeNull();
});

it('compares no content security policy between a file and PHP', function (): void {
    $server = cacheWebServer();

    // PHP's answers carry the page's own policy; the file's answer can't.
    Http::fake(fn (ApiRequest $request): PromiseInterface => $server($request)->then(
        fn (ResponseInterface $answer): ResponseInterface => $answer->getStatusCode() === 200 ? $answer : $answer->withHeader('Content-Security-Policy', "default-src 'self'"),
    ));

    $status = Artisan::call('teme:cache:check-static', ['--url' => 'http://web.test:8080']);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toMatch('/with the headers it adds to pages from PHP\W+yes\s*$/m');
});

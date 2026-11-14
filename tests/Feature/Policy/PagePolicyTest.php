<?php

declare(strict_types=1);

use App\Domain\Site\Policy\PagePolicy;
use Illuminate\Support\Facades\Exceptions;
use Tests\Fixtures\Policy\PolicyHeaders;

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
});

it('hashes a script as a browser does', function (): void {
    expect(PagePolicy::hash('alert(1)'))->toBe("'sha256-".base64_encode(hash('sha256', 'alert(1)', true))."'")
        ->and(PagePolicy::hash("console.log('hi')"))->toBe("'sha256-1ohZFo3B9w3UOFBbfx6JSomkpkME90iPs1r/qXzvX7Y='");
});

it('starts with self, the media and embed origins and the report address, and no hash', function (): void {
    $policy = (new PagePolicy)->header('http://localhost');

    expect(PolicyHeaders::sources($policy, 'default-src'))->toBe(["'self'"])
        ->and(PolicyHeaders::sources($policy, 'object-src'))->toBe(["'none'"])
        ->and(PolicyHeaders::sources($policy, 'base-uri'))->toBe(["'self'"])
        ->and(PolicyHeaders::sources($policy, 'frame-ancestors'))->toBe(["'self'"])
        ->and(PolicyHeaders::sources($policy, 'frame-src'))->toContain('https://www.youtube-nocookie.com', 'https://player.vimeo.com', 'https://open.spotify.com')
        ->and(PolicyHeaders::sources($policy, 'report-uri'))->toBe(['http://localhost/_teme/csp'])
        ->and(PolicyHeaders::sources($policy, 'report-to'))->toBe(['teme-csp'])
        ->and($policy)->not->toContain("'sha256-")
        ->and($policy)->not->toContain("'unsafe-hashes'")
        ->and($policy)->not->toContain("'unsafe-eval'")
        ->and(PolicyHeaders::sources($policy, 'script-src'))->not->toContain("'unsafe-inline'");
});

it('keeps unsafe-inline for styles alone', function (): void {
    $policy = (new PagePolicy)->header('http://localhost');

    expect(PolicyHeaders::sources($policy, 'style-src'))->toBe(["'self'", "'unsafe-inline'"])
        ->and(substr_count($policy, "'unsafe-inline'"))->toBe(1);
});

it("adds unsafe-hashes only with a handler's hash", function (): void {
    $policy = new PagePolicy;
    $policy->allowScriptHashes([PagePolicy::hash('a()')]);

    expect(PolicyHeaders::sources($policy->header('http://localhost'), 'script-src'))->not->toContain("'unsafe-hashes'");

    $policy->allowHandlerHashes([PagePolicy::hash('track(this)')]);

    expect(PolicyHeaders::sources($policy->header('http://localhost'), 'script-src'))
        ->toBe(["'self'", PagePolicy::hash('a()'), "'unsafe-hashes'", PagePolicy::hash('track(this)')]);
});

it('lists each origin once, sorted, under its directive', function (): void {
    $policy = new PagePolicy;
    $policy->allowOrigins('font-src', ['https://b.example.com', 'https://a.example.com']);
    $policy->allowOrigins('font-src', ['https://b.example.com']);
    $policy->allowOrigins('connect-src', ['https://beacon.example.org']);

    $header = $policy->header('http://localhost');

    expect(PolicyHeaders::sources($header, 'font-src'))->toBe(["'self'", 'https://a.example.com', 'https://b.example.com'])
        ->and(PolicyHeaders::sources($header, 'connect-src'))->toBe(["'self'", 'https://beacon.example.org'])
        ->and(PolicyHeaders::sources($header, 'img-src'))->not->toContain('https://a.example.com');
});

it("adds the media disk's origin when it isn't the site's, and the asset origin when ASSET_URL names another", function (): void {
    $before = (new PagePolicy)->header('http://localhost');

    expect(PolicyHeaders::sources($before, 'img-src'))->toBe(["'self'", 'data:'])
        ->and(PolicyHeaders::sources($before, 'media-src'))->toBe(["'self'"]);

    config([
        'filesystems.disks.media.url' => 'https://media.example.com/storage/media',
        'app.asset_url' => 'https://cdn.example.com',
    ]);
    app('filesystem')->forgetDisk('media');

    $after = (new PagePolicy)->header('http://localhost');

    expect(PolicyHeaders::sources($after, 'img-src'))->toBe(["'self'", 'data:', 'https://cdn.example.com', 'https://media.example.com'])
        ->and(PolicyHeaders::sources($after, 'media-src'))->toBe(["'self'", 'https://media.example.com'])
        ->and(PolicyHeaders::sources($after, 'script-src'))->toBe(["'self'", 'https://cdn.example.com'])
        ->and(PolicyHeaders::sources($after, 'style-src'))->toBe(["'self'", "'unsafe-inline'", 'https://cdn.example.com'])
        ->and(PolicyHeaders::sources($after, 'font-src'))->toBe(["'self'", 'https://cdn.example.com']);
});

it('starts afresh', function (): void {
    $policy = new PagePolicy;
    $policy->allowScript('a()');
    $policy->allowHandlerHashes([PagePolicy::hash('b()')]);
    $policy->allowOrigins('font-src', ['https://fonts.example.com']);

    expect($policy->header('http://localhost'))->toContain("'sha256-")->toContain('https://fonts.example.com');

    $policy->start();

    expect($policy->header('http://localhost'))->toBe((new PagePolicy)->header('http://localhost'));
});

it('leaves out what only a header can carry, for a meta', function (): void {
    $policy = new PagePolicy;
    $policy->allowScript('a()');
    $header = $policy->header('http://localhost');
    $meta = PagePolicy::forMeta($header);

    $names = fn (string $policy): array => array_map(
        fn (string $part): string => (string) strtok(trim($part), ' '),
        explode(';', $policy),
    );

    expect($meta)->not->toBe('')
        ->and($meta)->not->toContain('frame-ancestors')
        ->and($meta)->not->toContain('report-uri')
        ->and($meta)->not->toContain('report-to')
        ->and($names($meta))->toBe(array_values(array_diff($names($header), ['frame-ancestors', 'report-uri', 'report-to'])))
        ->and(PolicyHeaders::sources($meta, 'script-src'))->toBe(PolicyHeaders::sources($header, 'script-src'));
});

it('allows a script by the hash of its text, CR LF and CR made LF', function (): void {
    $policy = new PagePolicy;
    $policy->allowScript("a();\r\nb();\rc();");

    expect(PolicyHeaders::hashes($policy->header('http://localhost')))->toBe([PagePolicy::hash("a();\nb();\nc();")]);
});

it('drops and reports a source that would break the header, whoever declared it', function (): void {
    Exceptions::fake();
    $clean = new PagePolicy;
    $clean->allowOrigins('font-src', ['https://fonts.example.com']);
    $policy = new PagePolicy;
    $policy->allowOrigins('font-src', ["https://a.com 'unsafe-eval'", 'https://fonts.example.com', 'https://evil.com;script-src *']);
    $policy->allowOrigins('img-src', ['https://a.com,b', "https://x.example.com\nscript-src *", '"https://q.example.com"', '*']);

    $header = $policy->header('http://localhost');

    expect($header)->toBe($clean->header('http://localhost'))
        ->and($header)->not->toContain("'unsafe-eval'")->not->toContain(',');

    Exceptions::assertReported(fn (UnexpectedValueException $e): bool => str_contains($e->getMessage(), 'content security policy'));
    Exceptions::assertReportedCount(6);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->folder = cacheOnStatic();
});

afterEach(fn () => cacheOff($this->folder));

it('passes a web server that serves the files as the settings intend, and leaves no probe behind (D11)', function (): void {
    Http::fake(cacheWebServer());

    $status = Artisan::call('teme:cache:check-static', ['--url' => 'http://web.test:8080']);
    // Read once: Artisan::output() empties the buffer it reads.
    $output = Artisan::output();

    // Each rule is a line: its words, dots, then yes or no, and the component's right margin.
    expect($status)->toBe(0)
        ->and($output)->toMatch('/serves the file to a GET from a reader\W+yes\s*$/m')
        ->and($output)->not->toMatch('/\sno\s*$/m')
        ->and(glob($this->folder.'/static/*') ?: [])->toBe([]);
});

it('says which rule a web server breaks when it serves a file to a signed-in browser', function (): void {
    Http::fake(cacheWebServer(['cookie']));

    $status = Artisan::call('teme:cache:check-static', ['--url' => 'http://web.test:8080']);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toMatch('/never to a signed-in browser\W+no\s*$/m')
        ->and($output)->toMatch('/never to a request with a query\W+yes\s*$/m');
});

it('catches a web server that drops its security headers from a file, as a bare add_header would (D9)', function (): void {
    Http::fake(cacheWebServer(['headers']));

    $status = Artisan::call('teme:cache:check-static', ['--url' => 'http://web.test:8080']);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toMatch('/with the headers it adds to pages from PHP\W+no\s*$/m');
});

it('catches a web server that keeps serving a file once it is deleted', function (): void {
    Http::fake(cacheWebServer(['remembers']));

    $status = Artisan::call('teme:cache:check-static', ['--url' => 'http://web.test:8080']);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toMatch('/forgets a file once it is deleted\W+no\s*$/m');
});

it('fails when the static-file driver is off, or nothing answers', function (): void {
    config(['page_cache.driver' => 'file']);

    $this->artisan('teme:cache:check-static', ['--url' => 'http://web.test:8080'])
        ->expectsOutputToContain('The static-file driver is off')
        ->assertFailed();

    config(['page_cache.driver' => 'static']);
    Http::fake(['*' => Http::failedConnection()]);

    $this->artisan('teme:cache:check-static', ['--url' => 'http://web.test:8080'])
        ->expectsOutputToContain('Nothing answers at http://web.test:8080')
        ->assertFailed();

    expect(glob($this->folder.'/static/*') ?: [])->toBe([]);
});

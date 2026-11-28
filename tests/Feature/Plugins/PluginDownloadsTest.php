<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Downloads\DeclaredDownloads;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\RequestFailed;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->folder = storage_path('framework/testing/plugin-downloads-'.bin2hex(random_bytes(4)));
    $this->door = fn (array $addresses = ['api.example.com' => ['93.184.215.14'], 'cdn.example.com' => ['93.184.215.15']], int $downloadBytes = PluginHttp::LONG_ANSWER_BYTES, int $folderBytes = PluginHttp::MOST_FOLDER_BYTES): PluginHttp => new PluginHttp(
        ['api.example.com', 'cdn.example.com'],
        FakeResolver::answering($addresses),
        downloadFolder: $this->folder,
        downloadBytes: $downloadBytes,
        folderBytes: $folderBytes,
    );
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

it('asks for the headers of an address with HEAD, and gets no body back', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response('', 200, ['Content-Length' => '1234'])]);

    $answer = ($this->door)()->head('https://api.example.com/file.zip');

    expect($answer->status)->toBe(200)
        ->and($answer->body)->toBe('')
        ->and($answer->headers)->toHaveKey('Content-Length');
    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'HEAD');
});

it('streams a download into its own folder and reports its size and type', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response('abcdef', 200, ['Content-Type' => 'application/zip'])]);

    $file = ($this->door)()->download('https://api.example.com/data.zip');

    expect($file->bytes)->toBe(6)
        ->and($file->status)->toBe(200)
        ->and($file->contentType)->toBe('application/zip')
        ->and(str_starts_with($file->path, $this->folder.DIRECTORY_SEPARATOR))->toBeTrue()
        ->and(file_get_contents($file->path))->toBe('abcdef');
});

it('follows a redirect to a host the plugin was granted, and checks the new host', function (): void {
    Http::fake([
        'https://api.example.com/start' => Http::response('', 302, ['Location' => 'https://cdn.example.com/file']),
        'https://cdn.example.com/file' => Http::response('payload', 200),
    ]);

    $file = ($this->door)()->download('https://api.example.com/start');

    expect(file_get_contents($file->path))->toBe('payload');
    Http::assertSentCount(2);
});

it('refuses a redirect to a host the plugin was not granted, sending nothing to it', function (): void {
    Http::fake([
        'https://api.example.com/start' => Http::response('', 302, ['Location' => 'https://evil.example.net/file']),
        'https://evil.example.net/*' => Http::response('nope', 200),
    ]);

    expect(fn () => ($this->door)()->download('https://api.example.com/start'))->toThrow(HostRefused::class);
    Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'evil.example.net'));
});

it('refuses a redirect to an address that is private, even on a granted host', function (): void {
    Http::fake([
        'https://api.example.com/start' => Http::response('', 302, ['Location' => 'https://cdn.example.com/file']),
    ]);

    expect(fn () => ($this->door)(['api.example.com' => ['93.184.215.14'], 'cdn.example.com' => ['10.0.0.5']])->download('https://api.example.com/start'))
        ->toThrow(HostRefused::class, 'cdn.example.com points to a private address, so Teme won’t send to it.');
});

it('stops after five redirects', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response('', 302, ['Location' => 'https://api.example.com/again'])]);

    expect(fn () => ($this->door)()->download('https://api.example.com/start'))->toThrow(RequestFailed::class, 'Too many redirects from api.example.com, so Teme stopped.');
    Http::assertSentCount(6);
});

it('refuses a file larger than the manifest allows, and keeps no part of it', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response(str_repeat('a', 2 * 1_048_576), 200)]);

    expect(fn () => ($this->door)(downloadBytes: 1_048_576)->download('https://api.example.com/big.bin'))->toThrow(AnswerTooLarge::class);
    expect(is_dir($this->folder) ? File::files($this->folder) : [])->toBe([]);
});

it('refuses a download once the plugin’s folder is full, sending nothing', function (): void {
    Http::fake();
    File::ensureDirectoryExists($this->folder);
    File::put($this->folder.'/held.download', str_repeat('x', 64));

    expect(fn () => ($this->door)(folderBytes: 64)->download('https://api.example.com/file.zip'))->toThrow(RequestFailed::class);
    Http::assertNothingSent();
});

it('refuses a download for a plugin that keeps no download folder', function (): void {
    Http::fake();

    $door = new PluginHttp(['api.example.com'], FakeResolver::answering(['api.example.com' => ['93.184.215.14']]));

    expect(fn () => $door->download('https://api.example.com/file.zip'))->toThrow(RequestFailed::class);
    Http::assertNothingSent();
});

it('removes a leftover download older than a day before it downloads', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response('fresh', 200)]);
    File::ensureDirectoryExists($this->folder);
    $old = $this->folder.'/old.download';
    File::put($old, 'stale');
    touch($old, time() - 2 * 86_400);

    ($this->door)()->download('https://api.example.com/file.zip');

    expect(file_exists($old))->toBeFalse();
});

it('reads the download limit a manifest declares, 16 MB by default and 1 MB to 128 MB when given', function (): void {
    expect(DeclaredDownloads::DEFAULT_BYTES)->toBe(16_777_216)
        ->and(DeclaredDownloads::errors(['maxBytes' => 52_428_800]))->toBe([])
        ->and(DeclaredDownloads::errors(['maxBytes' => 134_217_729]))->not->toBe([])
        ->and(DeclaredDownloads::errors(['maxBytes' => 1024]))->not->toBe([])
        ->and(DeclaredDownloads::errors(['maxBytes' => '5']))->not->toBe([]);
});

it('refuses downloads a plugin declares without asking for http', function (): void {
    expect(app(ContributionsValidator::class)->errors(['downloads' => ['maxBytes' => 52_428_800]], '1.0.0', []))
        ->toBe(['It declares downloads, but the plugin doesn’t ask for http.'])
        ->and(app(ContributionsValidator::class)->errors(['downloads' => ['maxBytes' => 52_428_800]], '1.0.0', ['http']))->toBe([]);
});

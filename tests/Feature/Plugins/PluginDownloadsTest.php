<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginHttp;
use App\Domain\Plugins\Downloads\DeclaredDownloads;
use App\Domain\Plugins\Downloads\DownloadFolders;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\OutboundDownloads;
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

it('sends a plugin’s headers only to the host it asked, never to a redirect on another host', function (): void {
    Http::fake([
        'https://api.example.com/start' => Http::response('', 302, ['Location' => 'https://cdn.example.com/file']),
        'https://cdn.example.com/file' => Http::response('payload', 200),
    ]);

    ($this->door)()->download('https://api.example.com/start', ['Authorization' => 'Bearer secret-token', 'Cookie' => 'session=1']);

    Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'api.example.com') && $sent->hasHeader('Authorization', 'Bearer secret-token'));
    Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'cdn.example.com') && ! $sent->hasHeader('Authorization') && ! $sent->hasHeader('Cookie'));
});

it('keeps the plugin’s headers on a redirect that stays on the same host', function (): void {
    Http::fake([
        'https://api.example.com/start' => Http::response('', 302, ['Location' => '/second']),
        'https://api.example.com/second' => Http::response('payload', 200),
    ]);

    ($this->door)()->download('https://api.example.com/start', ['Authorization' => 'Bearer secret-token']);

    Http::assertSent(fn (Request $sent): bool => str_ends_with($sent->url(), '/second') && $sent->hasHeader('Authorization', 'Bearer secret-token'));
});

it('stops a download at the room its plugin’s folder has left, and keeps no part of it', function (): void {
    Http::fake(['https://api.example.com/*' => Http::response(str_repeat('a', 30), 200)]);
    File::ensureDirectoryExists($this->folder);
    File::put($this->folder.'/held.download', str_repeat('x', 40));

    expect(fn () => ($this->door)(folderBytes: 64)->download('https://api.example.com/file.zip'))->toThrow(AnswerTooLarge::class);
    expect(File::files($this->folder))->toHaveCount(1);
});

it('stops a stream as its progress or its declared length passes the limit, even where no request is faked', function (): void {
    $transport = PluginHttp::transport('api.example.com', '93.184.215.14', 1024);
    $tooLarge = fn (): AnswerTooLarge => new AnswerTooLarge('too large');

    expect(fn () => $transport['progress'](0, 2048))->toThrow(AnswerTooLarge::class)
        ->and(fn () => $transport['on_headers'](new Psr7Response(200, ['Content-Length' => '2048'])))->toThrow(AnswerTooLarge::class)
        ->and($transport['progress'](0, 512))->toBeFalse()
        ->and($tooLarge())->toBeInstanceOf(AnswerTooLarge::class);
});

it('offers OutboundDownloads only inside a long job', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/sharely', 'sharely', ['http'], ['api.example.com'])[OutboundDownloads::class] ?? null)->toBeNull()
        ->and($doors->for('tests/sharely', 'sharely', ['http'], ['api.example.com'], long: true)[OutboundDownloads::class] ?? null)->toBeInstanceOf(PluginHttp::class);
});

it('removes a plugin’s old downloads with the daily prune, and keeps the fresh ones', function (): void {
    $folder = DownloadFolders::pathFor('sharely');
    File::ensureDirectoryExists($folder);
    File::put($folder.'/old.download', 'stale');
    touch($folder.'/old.download', time() - 2 * 86_400);
    File::put($folder.'/fresh.download', 'new');

    $this->artisan('teme:plugin-downloads:prune')->assertSuccessful();

    expect(file_exists($folder.'/old.download'))->toBeFalse()
        ->and(file_exists($folder.'/fresh.download'))->toBeTrue();
    File::deleteDirectory($folder);
});

<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpFile;
use Teme\Contracts\Http\RequestFailed;
use Teme\Testing\SentRequest;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

/*
 * Multipart and raw bodies through the outbound door: every check the other
 * posts make, with a body of up to 10 MB.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->folder = sys_get_temp_dir().'/teme-own-'.bin2hex(random_bytes(4));
    mkdir($this->folder);
});

afterEach(function (): void {
    foreach (glob($this->folder.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->folder);
});

/** A door to media.example.com alone, at one public address, reading files from $own. */
function mediaDoor(?string $own = null): PluginHttp
{
    return new PluginHttp(['media.example.com'], FakeResolver::answering(['media.example.com' => ['93.184.215.14']]), ownPath: $own);
}

/** A resolver that fails the test if the door looks anything up. */
function unaskedResolver(): HostResolver
{
    return new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            throw new RuntimeException('The door looked the host up.');
        }
    };
}

it('sends the fields and the files as multipart, each file under its field, and gives back the answer', function (): void {
    Http::fake(['https://media.example.com/*' => Http::response('{"id":1}', 201)]);
    $bytes = "\x89PNG\r\n\x1a\n".random_bytes(64);

    $answer = mediaDoor()->postMultipart('https://media.example.com/upload', ['status' => 'Hello', 'tag' => ['a', 'b']], [new HttpFile('media', 'photo "one".png', 'image/png', bytes: $bytes)]);

    expect($answer->status)->toBe(201);
    Http::assertSent(function (Request $sent) use ($bytes): bool {
        $request = new SentRequest($sent->method(), $sent->url(), 'media.example.com', $sent->headers(), $sent->body());
        $parts = $request->multipart();

        return str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data; boundary=')
            && array_column($parts, 'name') === ['status', 'tag', 'tag', 'media']
            && $parts[0]['body'] === 'Hello'
            && $parts[3]['filename'] === 'photo %22one%22.png'
            && $parts[3]['contentType'] === 'image/png'
            && $parts[3]['body'] === $bytes;
    });
});

it('reads a file from a path inside the plugin’s own folder, and refuses any other path before sending', function (): void {
    Http::fake();
    file_put_contents($this->folder.'/note.txt', 'from the plugin');
    file_put_contents(sys_get_temp_dir().'/teme-outside.txt', 'not the plugin’s');

    mediaDoor($this->folder)->postMultipart('https://media.example.com/', [], [new HttpFile('file', 'note.txt', 'text/plain', path: $this->folder.'/note.txt')]);
    Http::assertSent(fn (Request $sent): bool => str_contains($sent->body(), 'from the plugin'));

    foreach ([sys_get_temp_dir().'/teme-outside.txt', $this->folder.'/../teme-outside.txt', $this->folder.'/missing.txt'] as $path) {
        expect(fn () => mediaDoor($this->folder)->postMultipart('https://media.example.com/', [], [new HttpFile('file', 'x.txt', 'text/plain', path: $path)]))
            ->toThrow(InvalidArgumentException::class, 'A file’s path must be a file inside the plugin’s own folder.');
    }

    expect(fn () => mediaDoor()->postMultipart('https://media.example.com/', [], [new HttpFile('file', 'x.txt', 'text/plain', path: $this->folder.'/note.txt')]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
    unlink(sys_get_temp_dir().'/teme-outside.txt');
});

it('sends a raw body byte for byte, with the content type given and the plugin’s own headers', function (): void {
    Http::fake(['https://media.example.com/*' => Http::response('', 202)]);
    $body = "{\"a\": 1,\n \"b\":\t[1, 2]}\r\n";

    mediaDoor()->postRaw('https://media.example.com/inbox', $body, 'application/activity+json', ['Signature' => 'keyId="k",signature="s"', 'Content-Type' => 'text/plain']);

    Http::assertSent(fn (Request $sent): bool => $sent->body() === $body
        && $sent->header('Content-Type') === ['application/activity+json']
        && $sent->hasHeader('Signature', 'keyId="k",signature="s"'));
});

it('refuses an address it may not reach, for each new method, sending nothing', function (string $method): void {
    Http::fake();
    $door = new PluginHttp(['media.example.com', 'inside.example.com'], FakeResolver::answering(['media.example.com' => ['93.184.215.14'], 'inside.example.com' => ['10.0.0.5']]));
    $send = fn (string $url) => $method === 'multipart'
        ? $door->postMultipart($url, ['a' => 'b'], [])
        : $door->postRaw($url, 'body', 'text/plain');

    expect(fn () => $send('https://evil.example.net/'))->toThrow(HostRefused::class, 'It may send requests only to the hosts you agreed to: media.example.com, inside.example.com.')
        ->and(fn () => $send('http://media.example.com/'))->toThrow(HostRefused::class, 'Teme sends requests only over https.')
        ->and(fn () => $send('https://inside.example.com/'))->toThrow(HostRefused::class, 'points to a private address');
    Http::assertNothingSent();
})->with(['multipart', 'raw']);

it('refuses a body over 10 MB before looking the host up or sending anything', function (): void {
    Http::fake();
    $door = new PluginHttp(['media.example.com'], unaskedResolver());
    $big = str_repeat('x', PluginHttp::MOST_REQUEST_BYTES + 1);

    expect(fn () => $door->postRaw('https://media.example.com/', $big, 'application/octet-stream'))
        ->toThrow(RequestFailed::class, 'The request to media.example.com was larger than 10 MB, so Teme didn’t send it.')
        ->and(fn () => $door->postMultipart('https://media.example.com/', [], [new HttpFile('file', 'big.bin', 'application/octet-stream', bytes: $big)]))
        ->toThrow(RequestFailed::class, 'larger than 10 MB')
        ->and(fn () => $door->postMultipart('https://media.example.com/', ['note' => $big], []))
        ->toThrow(RequestFailed::class, 'larger than 10 MB');
    Http::assertNothingSent();
});

it('refuses a path’s file over 10 MB without reading it', function (): void {
    Http::fake();
    $handle = fopen($this->folder.'/big.bin', 'w');
    ftruncate($handle, PluginHttp::MOST_REQUEST_BYTES + 1);
    fclose($handle);

    expect(fn () => (new PluginHttp(['media.example.com'], unaskedResolver(), ownPath: $this->folder))->postMultipart('https://media.example.com/', [], [new HttpFile('file', 'big.bin', 'application/octet-stream', path: $this->folder.'/big.bin')]))
        ->toThrow(RequestFailed::class, 'larger than 10 MB');
    Http::assertNothingSent();
});

it('still refuses a JSON or form body over 1 MB', function (): void {
    Http::fake();
    $door = new PluginHttp(['media.example.com'], unaskedResolver());

    expect(fn () => $door->postForm('https://media.example.com/', ['blob' => str_repeat('x', PluginHttp::MOST_BYTES)]))->toThrow(RequestFailed::class, 'larger than 1 MB');
    Http::assertNothingSent();
});

it('refuses a file that is neither bytes nor a path, or both', function (): void {
    expect(fn () => new HttpFile('file', 'a.txt', 'text/plain'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new HttpFile('file', 'a.txt', 'text/plain', bytes: 'x', path: '/tmp/a'))->toThrow(InvalidArgumentException::class);
});

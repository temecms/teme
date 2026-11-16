<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\HostResolver;
use App\Domain\Plugins\Doors\PluginHttp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\RequestFailed;
use Tests\Fixtures\Plugins\Lists\FakeResolver;

/*
 * A form POST through the outbound door (docs/plans/plugin-sockets-1.md, A1):
 * every check postJson makes, with a body WebSub hubs read.
 */
beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** A door to hub.example.com alone, at one public address. */
function hubDoor(): PluginHttp
{
    return new PluginHttp(['hub.example.com'], FakeResolver::answering(['hub.example.com' => ['93.184.215.14']]));
}

/** A resolver that fails the test if the door looks anything up. */
function neverAsked(): HostResolver
{
    return new class implements HostResolver
    {
        public function addresses(string $host): array
        {
            throw new RuntimeException('The door looked the host up.');
        }
    };
}

it('sends the fields form-encoded, with the form’s content type, and gives back the answer', function (): void {
    Http::fake(['https://hub.example.com/*' => Http::response('', 202)]);

    $answer = hubDoor()->postForm('https://hub.example.com/publish', ['hub.mode' => 'publish', 'note' => 'a b&c']);

    expect($answer->status)->toBe(202);
    Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST'
        && $sent->url() === 'https://hub.example.com/publish'
        && $sent->body() === 'hub.mode=publish&note=a+b%26c'
        && $sent->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('repeats a name for each value of a list, never name[0]=', function (): void {
    Http::fake(['https://hub.example.com/*' => Http::response('', 204)]);

    hubDoor()->postForm('https://hub.example.com/', ['hub.mode' => 'publish', 'hub.url' => ['https://a.example/feed/', 'https://b.example/feed/']]);

    Http::assertSent(fn (Request $sent): bool => $sent->body() === 'hub.mode=publish&hub.url=https%3A%2F%2Fa.example%2Ffeed%2F&hub.url=https%3A%2F%2Fb.example%2Ffeed%2F');
});

it('drops a Content-Type the plugin passes, as it drops Host', function (): void {
    Http::fake(['https://hub.example.com/*' => Http::response('', 204)]);

    hubDoor()->postForm('https://hub.example.com/', ['a' => 'b'], ['Content-Type' => 'application/json', 'X-Note' => 'kept']);

    Http::assertSent(fn (Request $sent): bool => $sent->header('Content-Type') === ['application/x-www-form-urlencoded']
        && $sent->hasHeader('X-Note', 'kept'));
});

it('refuses an address it may not reach, sending nothing', function (string $url, string $words): void {
    Http::fake();
    $door = new PluginHttp(['hub.example.com', 'inside.example.com'], FakeResolver::answering(['hub.example.com' => ['93.184.215.14'], 'inside.example.com' => ['10.0.0.5']]));

    expect(fn () => $door->postForm($url, ['a' => 'b']))->toThrow(HostRefused::class, $words);
    Http::assertNothingSent();
})->with([
    'a host not agreed' => ['https://evil.example.net/', 'It may send requests only to the hosts you agreed to: hub.example.com, inside.example.com.'],
    'plain http' => ['http://hub.example.com/', 'Teme sends requests only over https.'],
    'another port' => ['https://hub.example.com:8443/', 'Teme sends requests only to port 443.'],
    'a private address' => ['https://inside.example.com/', 'inside.example.com points to a private address, so Teme won’t send to it.'],
]);

it('refuses a body over 1 MB before looking the host up or sending anything', function (): void {
    Http::fake();
    $door = new PluginHttp(['hub.example.com'], neverAsked());

    expect(fn () => $door->postForm('https://hub.example.com/', ['blob' => str_repeat('x', PluginHttp::MOST_BYTES)]))
        ->toThrow(RequestFailed::class, 'The request to hub.example.com was larger than 1 MB, so Teme didn’t send it.');
    Http::assertNothingSent();
});

it('refuses a field that is neither a string nor a list of strings before any lookup', function (array $fields): void {
    Http::fake();
    $door = new PluginHttp(['hub.example.com'], neverAsked());

    expect(fn () => $door->postForm('https://hub.example.com/', $fields))
        ->toThrow(InvalidArgumentException::class, 'A form’s fields are names with a string or a list of strings.');
    Http::assertNothingSent();
})->with([
    'a nested array' => [['hub.url' => [['https://a.example/']]]],
    'a keyed array' => [['hub' => ['url' => 'https://a.example/']]],
    'a number' => [['count' => 3]],
    'a list of fields' => [['https://a.example/']],
]);

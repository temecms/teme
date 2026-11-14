<?php

declare(strict_types=1);

use App\Domain\Site\ReaderKeys;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->item = Visibilities::change(Visibilities::published($this->editor, 'Members update', 'zqprotected'), 'protected', $this->editor, 'Harbour 2024');
    RateLimiter::clear('unlock:203.0.113.9');
    RateLimiter::clear('unlock-hour:203.0.113.9');
});

/**
 * The form posted from one reader's address, with these fields and cookies,
 * as the browser sends it: no session, no token; $server adds headers.
 *
 * @param  array<string, string>  $fields
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $server
 */
function ulPost(array $fields, array $cookies = [], string $ip = '203.0.113.9', array $server = []): TestResponse
{
    $request = Request::create('/_teme/unlock', 'POST', $fields, $cookies, server: ['REMOTE_ADDR' => $ip, ...$server]);

    return TestResponse::fromBaseResponse(app(Kernel::class)->handle($request), $request);
}

it('turns away a form another site sent, unread: no cookie, the reader\'s keys kept, no try counted (I-1700)', function (): void {
    $response = ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], [ReaderKeys::COOKIE => str_repeat('A', 22)], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(303);

    expect($response->headers->getCookies())->toBe([])
        ->and((string) $response->headers->get('Location'))->toStartWith(rtrim(url('/'), '/').'/')
        ->and(RateLimiter::attempts('unlock:203.0.113.9'))->toBe(0);

    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'])->assertStatus(303);
});

it('counts an IPv6 reader\'s tries by their /64, and an IPv4 address carried in IPv6 as itself (I-1700)', function (): void {
    foreach (range(1, 10) as $try) {
        ulPost(['path' => '/members-update/', 'password' => "guess {$try}"], ip: "2001:db8:5:7::{$try}")->assertStatus(422);
    }

    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], ip: '2001:db8:5:7::99')->assertStatus(429);
    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], ip: '2001:db8:5:8::1')->assertStatus(303);

    foreach (range(1, 10) as $try) {
        ulPost(['path' => '/members-update/', 'password' => "guess {$try}"], ip: '198.51.100.7')->assertStatus(422);
    }

    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], ip: '::ffff:198.51.100.7')->assertStatus(429);
    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], ip: '::ffff:198.51.100.8')->assertStatus(303);
});

it('sends a reader who gives the password back to the page, with a key for it that opens it (I-1700)', function (): void {
    $response = ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'])->assertStatus(303);
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie): bool => $cookie->getName() === ReaderKeys::COOKIE);

    expect($response->headers->get('Location'))->toBe(rtrim(url('/'), '/').'/members-update/')
        ->and($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe(Visibilities::cookie($this->item))
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getExpiresTime())->toBe(now()->addDays(10)->getTimestamp())
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and((string) cacheGet('/members-update/', [ReaderKeys::COOKIE => $cookie->getValue()])->getContent())->toContain('zqprotectedbody');
});

it('keeps the keys a reader already holds, the newest first, twenty at most (I-1700)', function (): void {
    $held = implode('.', array_map(fn (int $n): string => str_pad((string) $n, 22, 'A'), range(1, 20)));

    $cookie = collect(ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'], [ReaderKeys::COOKIE => $held])->headers->getCookies())
        ->firstWhere(fn ($cookie): bool => $cookie->getName() === ReaderKeys::COOKIE);
    $keys = explode('.', (string) $cookie?->getValue());

    expect($keys)->toHaveCount(20)
        ->and($keys[0])->toBe(Visibilities::cookie($this->item))
        ->and($keys[1])->toBe(str_pad('1', 22, 'A'))
        ->and($keys)->not->toContain(str_pad('20', 22, 'A'));
});

it('draws the form again for a wrong password, saying so, with no cookie (I-1700)', function (string $given): void {
    $response = ulPost(['path' => '/members-update/', 'password' => $given])->assertStatus(422);
    $html = (string) $response->getContent();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($html)->toContain('That isn’t the password. Check it and try again.')
        ->and($html)->toContain('aria-invalid="true"')
        ->and($html)->toContain('<input type="hidden" name="path" value="/members-update/">')
        ->and($html)->not->toContain('zqprotectedbody');
})->with(['another case' => ['harbour 2024'], 'a space after' => ['Harbour 2024 '], 'empty' => ['']]);

it('refuses the eleventh try in a minute, the right password too, with no cookie (I-1700)', function (): void {
    foreach (range(1, 10) as $try) {
        ulPost(['path' => '/members-update/', 'password' => "guess {$try}"])->assertStatus(422);
    }

    $response = ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'])->assertStatus(429);

    expect($response->headers->getCookies())->toBe([])
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((string) $response->getContent())->toContain('Too many tries. Wait a minute, then try again.');

    $this->travel(61)->seconds();

    ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024'])->assertStatus(303);
});

it('never sends a reader off this site, whatever the path says (I-1700)', function (string $path): void {
    $location = (string) ulPost(['path' => $path, 'password' => 'Harbour 2024'])->assertStatus(303)->headers->get('Location');

    expect($location)->toStartWith(rtrim(url('/'), '/').'/');
})->with(['//evil.example/x', 'https://evil.example/x', '/\\evil.example', 'javascript:alert(1)', '']);

it('sends a reader to an address that holds no protected item without a cookie (I-1700)', function (): void {
    Visibilities::published($this->editor, 'Harbour news', 'zqpublic');

    $response = ulPost(['path' => '/harbour-news/', 'password' => 'Harbour 2024'])->assertStatus(303);

    expect($response->headers->get('Location'))->toBe(rtrim(url('/'), '/').'/harbour-news')
        ->and($response->headers->getCookies())->toBe([]);
});

it('starts no session and sets no cookie but its own (I-1700)', function (): void {
    $response = ulPost(['path' => '/members-update/', 'password' => 'Harbour 2024']);

    expect(collect($response->headers->getCookies())->map(fn ($cookie): string => $cookie->getName())->all())->toBe([ReaderKeys::COOKIE]);
});

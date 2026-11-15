<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceRefused;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Import\FakeCompanion;

beforeEach(function (): void {
    $this->fake = importFake();
    $this->clients = app(CompanionClients::class);
    $this->probe = fn () => $this->clients->probe(FakeCompanion::SITE, FakeCompanion::USER, FakeCompanion::PASSWORD);
    $this->reader = fn () => $this->clients->for(new ImportSource([
        'address' => FakeCompanion::SITE, 'api_root' => FakeCompanion::SITE.'/wp-json/',
        'user_name' => FakeCompanion::USER, 'credential' => FakeCompanion::PASSWORD,
    ]));
});

it('probes the companion, then signs in to read WordPress\'s own home address', function (): void {
    $probe = ($this->probe)();

    expect($probe->home)->toBe('https://old.example')
        ->and($probe->apiRoot)->toBe('https://old.example/wp-json/')
        ->and($probe->pluginVersion)->toBe('0.2.0')
        ->and($probe->api)->toBe(1)
        ->and(array_column($this->fake->requests, 'route'))->toBe(['probe', 'settings']);
});

it('finds the API on a site with plain permalinks, at ?rest_route=/', function (): void {
    $this->fake->plainPermalinks();

    expect(($this->probe)()->apiRoot)->toBe('https://old.example/?rest_route=/');
});

it('sends the application password only as Basic authentication, never in an address, and not to the public probe', function (): void {
    ($this->probe)();

    $basic = 'Basic '.base64_encode(FakeCompanion::USER.':'.FakeCompanion::PASSWORD);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/settings') && $request->hasHeader('Authorization', $basic));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/probe') && ! $request->hasHeader('Authorization'));
    Http::assertNotSent(fn (Request $request): bool => str_contains(rawurldecode($request->url()), 'abcd'));
});

it('reads a list a page at a time, to the empty page after the last full one', function (): void {
    $this->fake->items('users', [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]]);
    $reader = ($this->reader)();

    $first = $reader->page('users', [], 0, 2);
    $second = $reader->page('users', [], 2, 2);
    $last = $reader->page('users', [], 4, 2);

    expect(array_column($first->items, 'id'))->toBe([1, 2])
        ->and($first->nextAfterId)->toBe(2)
        ->and(array_column($second->items, 'id'))->toBe([3, 4])
        ->and($second->nextAfterId)->toBe(4)
        ->and($last->items)->toBe([])
        ->and($last->nextAfterId)->toBeNull()
        ->and($this->fake->requestsTo('users')[1]['query'])->toBe(['after_id' => '2', 'limit' => '2']);
});

it('refuses every answer it can\'t use, in plain words, under the field it belongs to', function (string $case, string $reason, string $field, bool $retryable): void {
    $forbidden = fn (int $status, string $code): array => ['code' => $code, 'message' => 'Refused.', 'data' => ['status' => $status]];

    match ($case) {
        'wrong password' => $this->fake->answer('settings', 401, $forbidden(401, 'incorrect_password')),
        'not an administrator' => $this->fake->answer('settings', 403, $forbidden(403, 'teme_forbidden')),
        'no capability' => $this->fake->answer('settings', 403, $forbidden(403, 'rest_forbidden')),
        'a firewall page' => $this->fake->answer('settings', 403),
        'cloudflare' => $this->fake->answer('settings', 403, $forbidden(403, 'teme_forbidden'), ['cf-mitigated' => 'challenge']),
        'plain http' => $this->fake->answer('settings', 403, $forbidden(403, 'teme_https_required')),
        'no plugin' => $this->fake->answer('probe', 404, $forbidden(404, 'rest_no_route'))->answer('probe', 404, $forbidden(404, 'rest_no_route')),
        'too old' => $this->fake->answer('probe', 200, ['name' => 'teme-companion', 'version' => '0.1.0', 'api' => 0, 'application_passwords' => true]),
        'no application passwords' => $this->fake->answer('probe', 200, ['name' => 'teme-companion', 'version' => '0.2.0', 'api' => 1, 'application_passwords' => false]),
        'moved' => $this->fake->answer('probe', 301, [], ['Location' => 'https://www.old.example/wp-json/teme/v1/probe?from=old']),
        'busy' => $this->fake->answer('settings', 503),
        'unreachable' => $this->fake->unreachable('probe'),
        default => $this->fake->answer('users', 200, ['api' => 1, 'items' => 'none', 'next' => null, 'limit' => 200]),
    };

    $call = $case === 'bad envelope' ? fn () => ($this->reader)()->page('users', [], 0, 200) : $this->probe;

    expect($call)->toThrow(function (SourceRefused $refusal) use ($reason, $field, $retryable): void {
        expect($refusal->reason)->toBe($reason)
            ->and($refusal->field)->toBe($field)
            ->and($refusal->retryable)->toBe($retryable)
            ->and($refusal->getMessage())->toBe($refusal->sentence())
            ->and($refusal->getMessage())->not->toContain('abcd');
    });
})->with([
    'a wrong application password' => ['wrong password', 'credentials', 'application_password', false],
    'an account that isn\'t an administrator' => ['not an administrator', 'not-administrator', 'user_name', false],
    'an account WordPress refuses the capability to' => ['no capability', 'not-administrator', 'user_name', false],
    'a firewall\'s page, not WordPress\'s answer' => ['a firewall page', 'blocked', 'address', false],
    'Cloudflare\'s bot check' => ['cloudflare', 'blocked', 'address', false],
    'a site that answers only over HTTPS' => ['plain http', 'https', 'address', false],
    'no companion plugin' => ['no plugin', 'not-companion', 'address', false],
    'a companion too old' => ['too old', 'too-old', 'address', false],
    'application passwords switched off' => ['no application passwords', 'no-application-passwords', 'address', false],
    'a redirect' => ['moved', 'moved', 'address', false],
    'a site that\'s down' => ['busy', 'busy', 'address', true],
    'a site that can\'t be reached' => ['unreachable', 'unreachable', 'address', true],
    'a page that isn\'t the plugin\'s envelope' => ['bad envelope', 'bad-answer', 'address', false],
]);

it('says which site a redirect leads to, and never follows it', function (): void {
    $this->fake->answer('probe', 301, [], ['Location' => 'https://www.old.example/wp-json/teme/v1/probe?from=old']);

    expect($this->probe)->toThrow(SourceRefused::class, 'The site sent Teme on to https://www.old.example. Use that address instead.');

    expect(array_column($this->fake->requests, 'route'))->toBe(['probe']);
});

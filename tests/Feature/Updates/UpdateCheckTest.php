<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Updates\CheckForUpdates;
use App\Domain\Updates\Operations\Input\RecordReleaseCheckInput;
use App\Domain\Updates\Operations\RecordReleaseCheck;
use App\Domain\Updates\Queries\UpdateState;
use App\Domain\Updates\Release;
use App\Domain\Updates\ReleaseCheck;
use App\Domain\Updates\UpdateChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    updOperator();
    config([
        'app.url' => 'https://news.example.org',
        'teme.version' => '0.1.0-rc.13',
        'teme.tagged' => true,
        'teme.updates.check' => true,
        'teme.updates.token' => 'github_pat_read_only',
        'teme.updates.channel' => null,
    ]);
    $this->versions = fn (): array => DB::table('teme_releases')->pluck('version')->sort()->values()->all();
    $this->check = fn (): ?object => DB::table('update_checks')->where('id', 1)->first();
});

it('asks GitHub once, with the token, sending nothing about the site', function (): void {
    updFake(Http::response([updItem('0.1.0-rc.14')], 200, ['ETag' => '"abc"']));

    app(CheckForUpdates::class)->run();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $names = array_values(array_diff(array_map(strtolower(...), array_keys($request->headers())), ['host']));
        sort($names);
        $everything = $request->url().json_encode($request->headers()).$request->body();

        return $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/repos/Krafty-Sprouts-Media-LLC/Teme/releases?per_page=100'
            && $names === ['accept', 'authorization', 'user-agent', 'x-github-api-version']
            && $request->header('User-Agent') === ['Teme']
            && $request->header('Accept') === ['application/vnd.github+json']
            && $request->header('X-GitHub-Api-Version') === ['2022-11-28']
            && $request->header('Authorization') === ['Bearer github_pat_read_only']
            && $request->body() === ''
            && ! str_contains($everything, 'news.example.org')
            && ! str_contains($everything, '0.1.0-rc.13');
    });
});

it('asks without a token when none is set, and sends the last answer’s ETag', function (): void {
    config(['teme.updates.token' => null]);
    DB::table('update_checks')->insert(['id' => 1, 'checked_at' => now(), 'succeeded_at' => now(), 'error' => null, 'etag' => '"abc"', 'token_set' => true]);
    updFake(Http::response(null, 304));

    app(CheckForUpdates::class)->run();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization') && $request->header('If-None-Match') === ['"abc"']);
});

it('keeps each release as Teme reads it, and the ETag', function (): void {
    updFake(Http::response([
        updItem('0.1.0-rc.15', ['body' => "### Security\n\n- A fix\n\n<!-- teme-release {\"format\":1,\"security\":true,\"maintenance\":true} -->"]),
        updItem('0.1.0', ['html_url' => 'https://evil.example/releases/v0.1.0']),
        updItem('0.1.0-rc.14', ['body' => str_repeat('a', 70000)]),
    ], 200, ['ETag' => '"abc"']));

    $check = app(CheckForUpdates::class)->run();
    $rc15 = DB::table('teme_releases')->where('version', '0.1.0-rc.15')->first();
    $release = DB::table('teme_releases')->where('version', '0.1.0')->first();

    expect($check->outcome)->toBe(ReleaseCheck::FOUND)
        ->and(($this->versions)())->toBe(['0.1.0', '0.1.0-rc.14', '0.1.0-rc.15'])
        ->and((bool) $rc15->prerelease)->toBeTrue()
        ->and((bool) $rc15->security)->toBeTrue()
        ->and((bool) $rc15->maintenance)->toBeTrue()
        ->and($rc15->notes)->toBe("### Security\n\n- A fix")
        ->and($rc15->url)->toBe('https://github.com/Krafty-Sprouts-Media-LLC/Teme/releases/tag/v0.1.0-rc.15')
        ->and((bool) $release->prerelease)->toBeFalse()
        ->and($release->url)->toBeNull()
        ->and(mb_strlen((string) DB::table('teme_releases')->where('version', '0.1.0-rc.14')->value('notes')))->toBe(65535)
        ->and(($this->check)()->etag)->toBe('"abc"')
        ->and(($this->check)()->error)->toBeNull()
        ->and((bool) ($this->check)()->token_set)->toBeTrue();
});

it('leaves out drafts, tags that aren’t releases, a version twice and a release with no date', function (): void {
    updFake(Http::response([
        updItem('0.1.0-rc.16', ['draft' => true]),
        updItem('0.1.0-rc.15', ['published_at' => null]),
        updItem('0.1.0-rc.14'),
        updItem('0.1.0-rc.14', ['body' => 'Again']),
        ['tag_name' => 'nightly', 'draft' => false, 'published_at' => '2026-11-10T09:00:00Z'],
        updItem('0.1.0-preview.1'),
        'not a release',
    ], 200));

    app(CheckForUpdates::class)->run();

    expect(($this->versions)())->toBe(['0.1.0-rc.14'])
        ->and(DB::table('teme_releases')->value('notes'))->toBe('Notes for 0.1.0-rc.14.');
});

it('reads both marks as false when the hidden line is missing or can’t be read', function (string $body): void {
    updFake(Http::response([updItem('0.1.0-rc.14', ['body' => $body])], 200));

    app(CheckForUpdates::class)->run();
    $row = DB::table('teme_releases')->first();

    expect((bool) $row->security)->toBeFalse()->and((bool) $row->maintenance)->toBeFalse();
})->with([
    'missing' => ['Notes.'],
    'not JSON' => ['Notes. <!-- teme-release {security: yes} -->'],
]);

it('removes a release GitHub no longer lists, and keeps everything on a 304', function (): void {
    updFake(Http::sequence()
        ->push([updItem('0.1.0-rc.15'), updItem('0.1.0-rc.14')], 200, ['ETag' => '"one"'])
        ->push([updItem('0.1.0-rc.14')], 200, ['ETag' => '"two"'])
        ->push(null, 304));

    app(CheckForUpdates::class)->run();
    app(CheckForUpdates::class)->run();
    $unchanged = app(CheckForUpdates::class)->run();

    expect($unchanged->outcome)->toBe(ReleaseCheck::UNCHANGED)
        ->and(($this->versions)())->toBe(['0.1.0-rc.14'])
        ->and(($this->check)()->etag)->toBe('"two"');
});

it('records why a check failed, and keeps the last list and ETag', function (Closure $then, string $error): void {
    $sequence = Http::sequence()->push([updItem('0.1.0-rc.14')], 200, ['ETag' => '"one"']);
    $then($sequence);
    updFake($sequence);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00'));
    app(CheckForUpdates::class)->run();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00'));

    $check = app(CheckForUpdates::class)->run();
    $row = ($this->check)();

    expect($check->outcome)->toBe(ReleaseCheck::FAILED)
        ->and($row->error)->toBe($error)
        ->and(CarbonImmutable::parse($row->checked_at)->format('H:i'))->toBe('10:00')
        ->and(CarbonImmutable::parse($row->succeeded_at)->format('H:i'))->toBe('09:00')
        ->and($row->etag)->toBe('"one"')
        ->and(($this->versions)())->toBe(['0.1.0-rc.14']);
})->with([
    'unreachable' => [fn (ResponseSequence $then) => $then->pushFailedConnection(), 'Teme couldn’t reach GitHub. It asks again within the hour.'],
    'rate-limited, 403' => [fn (ResponseSequence $then) => $then->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']), 'GitHub’s limit on requests was reached. Teme asks again within the hour.'],
    'rate-limited, 429' => [fn (ResponseSequence $then) => $then->push(['message' => 'Too many requests'], 429, ['Retry-After' => '60']), 'GitHub’s limit on requests was reached. Teme asks again within the hour.'],
    'token refused' => [fn (ResponseSequence $then) => $then->push(['message' => 'Bad credentials'], 401), 'GitHub refused the token in TEME_GITHUB_TOKEN. Make a new read-only token for the repository and set it.'],
    'refused' => [fn (ResponseSequence $then) => $then->push(['message' => 'Forbidden'], 403), 'GitHub refused the request (403). Teme asks again within the hour.'],
    'not found, with a token' => [fn (ResponseSequence $then) => $then->push(['message' => 'Not Found'], 404), 'GitHub can’t find Krafty-Sprouts-Media-LLC/Teme, or the token in TEME_GITHUB_TOKEN can’t read it.'],
    'a server error' => [fn (ResponseSequence $then) => $then->push('Bad gateway', 502), 'GitHub answered 502. Teme asks again within the hour.'],
    'a redirect, not followed' => [fn (ResponseSequence $then) => $then->push('', 301, ['Location' => 'https://api.github.com/repositories/1/releases']), 'GitHub answered 301. Teme asks again within the hour.'],
    'not a list' => [fn (ResponseSequence $then) => $then->push(['message' => 'Moved'], 200), 'GitHub’s answer couldn’t be read. Teme asks again within the hour.'],
]);

it('says to set the token when the repository can’t be found without one', function (): void {
    config(['teme.updates.token' => null]);
    updFake(Http::response(['message' => 'Not Found'], 404));

    app(CheckForUpdates::class)->run();

    expect(($this->check)()->error)->toBe('GitHub can’t find Krafty-Sprouts-Media-LLC/Teme. While the repository is private, set TEME_GITHUB_TOKEN to a read-only token for it.')
        ->and(($this->check)()->succeeded_at)->toBeNull()
        // The screen, drawn by web without the token, reads this.
        ->and((bool) ($this->check)()->token_set)->toBeFalse()
        ->and(($this->versions)())->toBe([]);
});

it('asks nothing and records nothing when the check is off', function (): void {
    config(['teme.updates.check' => false]);
    updFake(Http::response([updItem('0.1.0-rc.14')], 200));

    expect(app(CheckForUpdates::class)->run())->toBeNull();

    Http::assertNothingSent();
    expect(($this->check)())->toBeNull();
});

it('records only for the operator, writing nothing when anyone else asks', function (): void {
    $this->seed(RoleSeeder::class);
    $admin = User::factory()->create()->assignRole('administrator');
    app()->forgetInstance(Elevation::class);

    expect(fn () => app(RecordReleaseCheck::class)->execute(new RecordReleaseCheckInput(ReleaseCheck::failed('x'), CarbonImmutable::now(), false, (string) $admin->id)))
        ->toThrow(AuthorizationException::class);

    expect(($this->check)())->toBeNull()
        ->and(DB::table('teme_releases')->count())->toBe(0);
});

it('refuses when there is no actor and no operator, writing nothing', function (): void {
    app()->forgetInstance(Elevation::class);

    expect(fn () => app(RecordReleaseCheck::class)->execute(new RecordReleaseCheckInput(ReleaseCheck::failed('x'), CarbonImmutable::now(), false)))
        ->toThrow(AuthorizationException::class);

    expect(($this->check)())->toBeNull()
        ->and(DB::table('teme_releases')->count())->toBe(0);
});

it('refuses a release whose version isn’t one, or comes twice, writing nothing', function (): void {
    $release = Release::fromGitHub(updItem('0.1.0-rc.14'), 'Krafty-Sprouts-Media-LLC/Teme');
    $notOne = new Release('not-a-version', false, false, false, '', null, CarbonImmutable::now());

    expect(fn () => app(RecordReleaseCheck::class)->execute(new RecordReleaseCheckInput(ReleaseCheck::found([$notOne], null), CarbonImmutable::now(), true)))
        ->toThrow(ValidationException::class, 'not-a-version isn’t a release’s version.');

    expect(fn () => app(RecordReleaseCheck::class)->execute(new RecordReleaseCheckInput(ReleaseCheck::found([$release, $release], null), CarbonImmutable::now(), true)))
        ->toThrow(ValidationException::class, 'The release list names 0.1.0-rc.14 twice.');

    expect(($this->check)())->toBeNull();
});

it('follows the running version’s channel unless TEME_UPDATES_CHANNEL chooses', function (?string $set, string $running, string $channel, bool $isSet): void {
    config(['teme.updates.channel' => $set, 'teme.version' => $running]);

    expect(UpdateChannel::current())->toBe($channel)
        ->and(UpdateChannel::isSet())->toBe($isSet);
})->with([
    'a pre-release, unset' => [null, '0.1.0-rc.13', 'prerelease', false],
    'a release, unset' => [null, '0.1.0', 'stable', false],
    'a pre-release, stable chosen' => ['stable', '0.1.0-rc.13', 'stable', true],
    'a release, prerelease chosen' => ['prerelease', '0.1.0', 'prerelease', true],
    'a mistyped value' => ['beta', '0.1.0', 'stable', false],
]);

it('reads the newer releases in the channel, newest first, and none for an untagged build', function (): void {
    updFake(Http::response([updItem('0.1.0-rc.12'), updItem('0.1.0'), updItem('0.1.0-rc.14'), updItem('0.1.0-rc.13')], 200));
    app(CheckForUpdates::class)->run();
    $state = app(UpdateState::class);
    $versions = fn (array $releases): array => array_map(fn ($release): string => $release->version, $releases);

    expect($versions($state->newer()))->toBe(['0.1.0', '0.1.0-rc.14'])
        ->and($state->newest()?->version)->toBe('0.1.0');

    config(['teme.updates.channel' => 'stable']);
    expect($versions($state->newer()))->toBe(['0.1.0']);

    config(['teme.tagged' => false, 'teme.version' => '0.1.0']);
    expect($state->newer())->toBe([]);
});

it('runs from teme:updates:check as the operator, saying what it found, and every hour on one server', function (): void {
    app()->forgetInstance(Elevation::class);
    updFake(Http::response([updItem('0.1.0-rc.14')], 200));

    $this->artisan('teme:updates:check')
        ->expectsOutput('Asked GitHub for Teme’s releases.')
        ->expectsOutput('This site runs Teme 0.1.0-rc.13. The newest release on the prerelease channel is 0.1.0-rc.14.')
        ->assertExitCode(0);

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'teme:updates:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('exits 1 with the error when the check fails, and 0 saying so when it is off', function (): void {
    updFake(Http::failedConnection());

    $this->artisan('teme:updates:check')
        ->expectsOutput('Teme couldn’t reach GitHub. It asks again within the hour.')
        ->assertExitCode(1);

    config(['teme.updates.check' => false]);

    $this->artisan('teme:updates:check')
        ->expectsOutput('Update checks are off on this server (TEME_UPDATES_CHECK), so nothing was asked.')
        ->assertExitCode(0);
});

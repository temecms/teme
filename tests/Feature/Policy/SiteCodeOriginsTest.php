<?php

declare(strict_types=1);

use App\Domain\Site\Policy\PagePolicy;
use App\Domain\SiteCode\Models\SiteCodeEntry;
use App\Domain\SiteCode\Operations\Input\SaveSiteCodeInput;
use App\Domain\SiteCode\Operations\SaveSiteCode;
use App\Domain\SiteCode\Queries\SiteCodeScreen;
use App\Domain\SiteCode\SiteCodeEntries;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Policy\PolicyHeaders;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [], 'app.url' => 'http://localhost']);
    themeActivate('default');
    $this->admin = User::factory()->create(['name' => 'Ada Admin'])->assignRole('administrator');
    $this->analytics = '<script defer data-site="ledger" src="https://stats.example.org/script.js"></script>';

    // A save of the analytics entry with these origins, as this person, elevated.
    $this->save = fn (?string $entryId, array $origins, ?User $actor = null): array => scElevated(fn (): array => app(SaveSiteCode::class)->execute(
        new SaveSiteCodeInput($entryId, 'Visitor analytics', 'head', $this->analytics, true, (string) ($actor ?? $this->admin)->id, $origins),
    ));
});

it('adds a JSONB list of origins to every entry, empty by default', function (): void {
    $column = DB::selectOne("SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'site_code_entries' AND column_name = 'origins'");
    $entry = scAdd($this->admin);

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('jsonb')
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toBe("'[]'::jsonb")
        ->and($entry->refresh()->origins)->toBe([])
        ->and(json_decode((string) DB::table('site_code_entries')->value('origins'), true))->toBe([]);

    try {
        DB::table('site_code_entries')->update(['origins' => '{}']);
        $state = null;
    } catch (QueryException $refused) {
        $state = $refused->getCode();
    }

    expect($state)->toBe('23514');
});

it('saves the hosts an administrator declares, trimmed, lower-cased, once each', function (): void {
    $result = ($this->save)(null, ["Plausible.io\n\n plausible.io \n*.Example.com"]);

    expect(SiteCodeEntry::query()->findOrFail($result['id'])->origins)->toEqualCanonicalizing(['plausible.io', '*.example.com']);

    ($this->save)($result['id'], ['', ' Plausible.io ', 'cdn.example.net']);

    expect(SiteCodeEntry::query()->findOrFail($result['id'])->origins)->toEqualCanonicalizing(['plausible.io', 'cdn.example.net']);
});

it('refuses a host with a scheme, a path, a port, a space or a keyword, and more than 20, in these words, writing nothing', function (array $origins, string $message): void {
    $entry = scAdd($this->admin);
    Cache::memo()->forget(SiteCodeEntries::CACHE_KEY);
    SiteCodeEntries::on();
    $row = DB::table('site_code_entries')->get()->all();
    $events = DB::table('security_events')->count();
    $cached = Cache::memo()->get(SiteCodeEntries::CACHE_KEY);

    try {
        ($this->save)($entry->id, $origins);
        $errors = [];
    } catch (ValidationException $refused) {
        $errors = $refused->errors();
    }

    expect($errors)->toBe(['origins' => [$message]])
        ->and(DB::table('site_code_entries')->get()->all())->toEqual($row)
        ->and(DB::table('security_events')->count())->toBe($events)
        ->and(Cache::memo()->get(SiteCodeEntries::CACHE_KEY))->toBe($cached);
})->with([
    'a scheme' => [['https://plausible.io'], '“https://plausible.io” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'a path' => [['plausible.io/js'], '“plausible.io/js” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'a port' => [['plausible.io:8443'], '“plausible.io:8443” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'a space' => [['plausible io'], '“plausible io” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'a keyword' => [["'unsafe-inline'"], '“\'unsafe-inline\'” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'localhost' => [['localhost'], '“localhost” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    'an address' => [['10.0.0.1'], '“10.0.0.1” isn\'t a host name. Write it as plausible.io or *.example.com, without https://, a path or a port.'],
    '21 hosts' => [array_map(fn (int $n): string => "h{$n}.example.com", range(1, 21)), 'List 20 hosts at most.'],
]);

it('refuses a person who may not manage site code, and writes nothing', function (): void {
    $entry = scAdd($this->admin);
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('site_code_entries')->get()->all();

    expect(fn () => ($this->save)($entry->id, ['plausible.io'], $editor))->toThrow(AuthorizationException::class, 'Not permitted to change site code.')
        ->and(DB::table('site_code_entries')->get()->all())->toEqual($before)
        ->and(count(scLog()))->toBe(1);
});

it('asks for the password again for a change to the origins alone', function (): void {
    $entry = scAdd($this->admin);
    $before = DB::table('site_code_entries')->get()->all();
    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => app(SaveSiteCode::class)->execute(new SaveSiteCodeInput($entry->id, 'Visitor analytics', 'head', $this->analytics, true, (string) $this->admin->id, ['plausible.io'])))
        ->toThrow(ElevationRequiredException::class)
        ->and(DB::table('site_code_entries')->get()->all())->toEqual($before)
        ->and(count(scLog()))->toBe(1);
});

it('logs the origins before and after', function (): void {
    $entry = scAdd($this->admin);

    ($this->save)($entry->id, ['plausible.io']);

    expect(scLog()[1])->toBe(scEvent('site_code.changed', $this->admin->id, [
        'entry' => $entry->id,
        'before_name' => 'Visitor analytics', 'before_placement' => 'head', 'before_on' => true, 'before_code' => $this->analytics, 'before_origins' => '',
        'after_name' => 'Visitor analytics', 'after_placement' => 'head', 'after_on' => true, 'after_code' => $this->analytics, 'after_origins' => 'plausible.io',
    ]));
});

it('counts a change to the origins alone as a change, and purges every stored page', function (): void {
    $entry = scAdd($this->admin);
    cacheOn();
    addrGet('/')->assertHeader('X-Teme-Cache', 'MISS');

    expect(cacheKept('/'))->toBe(['/']);

    $result = ($this->save)($entry->id, ['plausible.io']);

    expect($result)->toBe(['id' => $entry->id, 'added' => false, 'changed' => true])
        ->and(cacheKept('/'))->toBe([])
        ->and(($this->save)($entry->id, ['plausible.io']))->toBe(['id' => $entry->id, 'added' => false, 'changed' => false]);
});

it('gives a page the declared hosts in every fetch directive', function (): void {
    ($this->save)(null, ['plausible.io']);

    $policy = PolicyHeaders::of(addrGet('/')->assertOk());

    foreach (PagePolicy::DECLARED as $directive) {
        expect(PolicyHeaders::sources($policy, $directive))->toContain('https://plausible.io');
    }
});

it("shows each entry's declared and found origins on the screen", function (): void {
    ($this->save)(null, ['plausible.io']);

    $entry = app(SiteCodeScreen::class)->data()['places'][0]['entries'][0];

    expect($entry['origins'])->toBe(['plausible.io'])
        ->and($entry['found'])->toBe(['https://stats.example.org']);
});

it("gives each change on the screen the entry's declared hosts before and after", function (): void {
    $added = ($this->save)(null, ['plausible.io']);
    ($this->save)($added['id'], ['plausible.io', '*.example.com']);
    DB::table('security_events')->where('type', 'site_code.added')->update(['context' => DB::raw("context - 'after_origins'")]);

    $changes = app(SiteCodeScreen::class)->data()['changes'];

    expect($changes[0]['what'])->toBe('Changed')
        ->and($changes[0]['beforeOrigins'])->toBe(['plausible.io'])
        ->and($changes[0]['afterOrigins'])->toBe(['plausible.io', '*.example.com'])
        ->and($changes[1]['what'])->toBe('Added')
        ->and($changes[1]['beforeOrigins'])->toBeNull()
        ->and($changes[1]['afterOrigins'])->toBe([]);
});

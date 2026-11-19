<?php

declare(strict_types=1);

use App\Domain\Redirects\AddressCounts;
use App\Domain\Redirects\NotFoundLog;
use App\Domain\Redirects\NotFoundLogSection;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SearchAndSocialSection;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    // The spool's folder, this test's own: the page cache's, the cache itself left off.
    $this->folder = sys_get_temp_dir().'/teme-log-settings-'.getmypid().'-'.uniqid();
    config(['app.url' => 'http://localhost', 'page_cache.stores.file.path' => $this->folder]);
    $this->admin = User::factory()->create()->assignRole('administrator');
    // A reader's GET of this exact path.
    $this->visit = fn (string $uri): TestResponse => TestResponse::fromBaseResponse(app(HttpKernel::class)->handle(Request::create($uri, 'GET')));
    // The open spool's lines, each without its time.
    $this->spooled = function (): array {
        $file = app(AddressCounts::class)->folder().'/'.AddressCounts::OPEN;
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return array_map(fn (string $line): string => implode("\t", array_slice(explode("\t", $line), 0, -1)), $lines);
    };
    // One minute's run, as the scheduler makes it.
    $this->count = fn (): int => $this->artisan('teme:redirects:count')->assertSuccessful()->run();
});

afterEach(fn () => cacheOff($this->folder));

it('registers Addresses with nothing at them at 800, its size and its ignore list starting from the log\'s own defaults (I-874)', function (): void {
    $sections = array_map(fn (SearchAndSocialSection $section): array => [$section->order(), $section->key(), $section->label()], SearchAndSocial::ordered(app()->tagged(SearchAndSocial::SECTIONS)));
    $group = app(SettingsRegistry::class)->find('seo');

    expect($sections)->toContain([800, 'not-found-log', 'Addresses with nothing at them'])
        ->and($group?->setting(NotFoundLogSection::SIZE)?->label)->toBe('Addresses the 404 log keeps')
        ->and($group?->setting(NotFoundLogSection::SIZE)?->description)->toBe('When the log is full, the address seen least recently makes room. From 1,000 to 100,000.')
        ->and([$group?->setting(NotFoundLogSection::SIZE)?->min, $group?->setting(NotFoundLogSection::SIZE)?->max])->toBe([1000, 100000])
        ->and($group?->setting(NotFoundLogSection::IGNORE)?->label)->toBe('Addresses the 404 log ignores')
        ->and($group?->setting(NotFoundLogSection::IGNORE)?->description)->toBe('One per line, as the address after the site’s, and * stands for anything, slashes included. Bots probe every site for PHP files and names starting with a dot, so those are ignored to begin with.')
        ->and(NotFoundLogSection::size())->toBe(NotFoundLog::KEEP)
        ->and(NotFoundLogSection::patterns())->toBe(NotFoundLog::IGNORED);
});

it('reads the ignore list one pattern a line, in the stored form of the paths it is matched against, blank lines and repeats left out', function (): void {
    cmSettings([NotFoundLogSection::IGNORE => "  /WP-Login.php \r\n\r\n*.php\n*.php\n/old//Section/*/"], SearchAndSocial::KEY);

    expect(NotFoundLogSection::patterns())->toBe(['wp-login.php', '*.php', 'old/section/*']);
});

it('refuses a size outside 1,000 to 100,000, keeping the log\'s own', function (int $size): void {
    $this->actingAs($this->admin)->from('/admin/settings/seo')
        ->put('/admin/settings/seo', ['values' => [NotFoundLogSection::SIZE => $size], 'expected_version' => 0])
        ->assertSessionHasErrors('values.'.NotFoundLogSection::SIZE);

    expect(NotFoundLogSection::size())->toBe(10_000);
})->with([999, 100_001]);

it('keeps the log at the size the setting gives, which the minute\'s run applies (I-874)', function (): void {
    DB::table('not_found_log')->insert(array_map(fn (int $n): array => [
        'path' => "old-{$n}", 'count' => 1, 'first_seen_at' => now()->subDays(2), 'last_seen_at' => now()->subDay()->addSeconds($n), 'referrer_host' => null,
    ], range(1, 1000)));
    cmSettings([NotFoundLogSection::SIZE => 1000], SearchAndSocial::KEY);

    ($this->visit)('/never-was/')->assertNotFound();
    ($this->count)();
    ($this->count)();

    expect(DB::table('not_found_log')->count())->toBe(1000)
        ->and(DB::table('not_found_log')->where('path', 'never-was')->exists())->toBeTrue()
        ->and(DB::table('not_found_log')->where('path', 'old-1')->exists())->toBeFalse();
});

it('applies the ignore list in the minute\'s run, so an address the list no longer names is logged and one it still names never is (I-874)', function (): void {
    cmSettings([NotFoundLogSection::IGNORE => ".*\n*/.*"], SearchAndSocial::KEY);

    ($this->visit)('/old-page.php')->assertNotFound();
    ($this->visit)('/.env')->assertNotFound();

    // A reader's request reads no setting: both wait in the spool.
    expect(($this->spooled)())->toBe(["n\told-page.php\t", "n\t.env\t"]);

    ($this->count)();
    ($this->count)();

    expect(DB::table('not_found_log')->pluck('path')->all())->toBe(['old-page.php']);
});

it('serves a stored 404 without a database statement, the ignore list being the run\'s alone (I-262)', function (): void {
    $cache = cacheOn();
    ($this->visit)('/never-was/')->assertHeader('X-Teme-Cache', 'MISS');
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    ($this->visit)('/never-was/')->assertHeader('X-Teme-Cache', 'HIT');

    expect($statements)->toBe([])
        ->and(($this->spooled)())->toBe(["n\tnever-was\t", "n\tnever-was\t"]);

    cacheOff($cache);
});

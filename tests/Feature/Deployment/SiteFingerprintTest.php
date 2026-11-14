<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Deployment\SiteFingerprint;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * teme:site:fingerprint: what a site holds and what its addresses answer,
 * in lines two servers compare after a restore (I-385).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    config(['app.url' => 'http://localhost']);

    // The command's exit code and everything it printed, from a buffer of this test's own.
    // Console lines end in PHP_EOL, which is "\r\n" on Windows; the tests read "\n" on every machine.
    $this->print = function (array $options = []): array {
        $output = new BufferedOutput;
        $code = Artisan::call('teme:site:fingerprint', $options, $output);

        return [$code, str_replace("\r\n", "\n", $output->fetch())];
    };

    // The lines of one kind, in the order printed.
    $this->lines = fn (string $output, string $kind): array => array_values(array_filter(
        explode("\n", $output),
        fn (string $line): bool => str_starts_with($line, $kind.' '),
    ));

    // A fingerprint saved as the other server's, and the file's path.
    $this->saved = function (string $text): string {
        $file = tempnam(sys_get_temp_dir(), 'fingerprint-');
        file_put_contents($file, $text);

        return $file;
    };
});

it('prints the newest migration, then every table once with its rows, the server’s own work as activity', function (): void {
    addrPublished($this->editor, 'Hello World');

    [$code, $output] = ($this->print)(['--sample' => 0]);

    $tables = array_map(fn (object $row): string => (string) $row->relname, DB::select(
        "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND NOT c.relispartition",
    ));
    $printed = array_map(
        fn (string $line): string => explode(' ', $line)[1],
        array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_starts_with($line, 'table ') || str_starts_with($line, 'activity '))),
    );
    $sorted = $printed;
    usort($sorted, strcmp(...));

    expect($code)->toBe(0)
        ->and($tables)->toContain('contents', 'paths', 'sessions')
        ->and(($this->lines)($output, 'migration'))->toBe(['migration '.DB::table('migrations')->max('migration')])
        ->and($printed)->toEqualCanonicalizing($tables)
        ->and($printed)->toBe($sorted)
        ->and($output)->toContain(
            "\ntable contents 1\n",
            "\ntable paths ".DB::table('paths')->count()."\n",
            "\nactivity sessions 0\n",
            "\nactivity jobs ".DB::table('jobs')->count()."\n",
            "\nactivity security_events ".DB::table('security_events')->count()."\n",
        )
        ->and($output)->not->toContain("\ntable sessions ", "\ntable jobs ", "\ntable security_events ")
        ->and(count(($this->lines)($output, 'address')))->toBe(count(SiteFingerprint::FIXED));
});

it('asks the four fixed addresses first, then as many of the site’s own as --sample says, in the order of their path’s MD5', function (): void {
    foreach (['Hello World', 'Second Story', 'Third Story', 'Fourth Story'] as $title) {
        addrPublished($this->editor, $title);
    }

    $expected = collect(DB::select("SELECT p.path, c.type FROM paths p LEFT JOIN contents c ON p.target_type = 'content' AND p.target_id = c.id::text"))
        ->sortBy(fn (object $row): string => md5((string) $row->path)."\0".$row->path, SORT_STRING)
        ->take(3)
        ->map(fn (object $row): string => Addresses::toUrl((string) $row->path, $row->type === null ? null : (string) $row->type))
        ->values()
        ->all();

    [$code, $output] = ($this->print)(['--sample' => 3]);
    $asked = array_map(fn (string $line): string => explode(' ', $line)[1], ($this->lines)($output, 'address'));

    expect($code)->toBe(0)
        ->and(DB::table('paths')->count())->toBeGreaterThan(3)
        ->and($asked)->toBe([...SiteFingerprint::FIXED, ...$expected]);
});

it('writes a page as its status and the SHA-256 of its body with the site’s host made neutral, so one site under two addresses prints the same lines', function (): void {
    addrPublished($this->editor, 'Hello World');

    config(['app.url' => 'https://a.example.test']);
    $page = (string) addrGet('https://a.example.test/hello-world/')->getContent();
    [, $first] = ($this->print)(['--sample' => 10]);

    config(['app.url' => 'https://b.example.test']);
    [, $second] = ($this->print)(['--sample' => 10]);

    $line = collect(($this->lines)($first, 'address'))->first(fn (string $line): bool => str_starts_with($line, 'address /hello-world/ '));

    expect($page)->toContain('https://a.example.test/hello-world/')
        ->and($line)->toBe('address /hello-world/ 200 '.hash('sha256', str_replace('a.example.test', SiteFingerprint::HOST, $page)))
        ->and(($this->lines)($second, 'address'))->toBe(($this->lines)($first, 'address'));
});

it('writes a redirect as its status and where it sends the reader, as a path when it stays on this site', function (): void {
    $post = addrPublished($this->editor, 'Hello World');
    addrRename($post, 'hello-again', $this->editor);
    config(['app.url' => 'https://a.example.test']);

    [, $output] = ($this->print)(['--sample' => 50]);
    $addresses = ($this->lines)($output, 'address');

    expect($addresses)->toContain('address /hello-world/ 301 /hello-again/')
        ->and(collect($addresses)->contains(fn (string $line): bool => str_starts_with($line, 'address /hello-again/ 200 ')))->toBeTrue();
});

it('draws every page with the page cache off, storing nothing, and leaves the page cache as it was', function (): void {
    addrPublished($this->editor, 'Hello World');
    $folder = cacheOn();

    try {
        [$code] = ($this->print)(['--sample' => 10]);

        expect($code)->toBe(0)
            ->and(config('page_cache.enabled'))->toBeTrue()
            ->and(cacheEntry('/hello-world/'))->toBeNull()
            ->and(cacheEntry('/'))->toBeNull();

        addrGet('/hello-world/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');

        expect(cacheEntry('/hello-world/'))->not->toBeNull();
    } finally {
        cacheOff($folder);
    }
});

it('refuses while the site is in maintenance mode, asking for nothing', function (): void {
    addrPublished($this->editor, 'Hello World');
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app()->forgetInstance(MaintenanceModeManager::class);
    app()->maintenanceMode()->activate([]);

    [$code, $output] = ($this->print)(['--sample' => 10]);

    expect($code)->toBe(1)
        ->and($output)->toContain('The site is in maintenance mode, so every address would answer 503. Bring it up first (php artisan up), then run this again.')
        ->and(($this->lines)($output, 'table'))->toBe([])
        ->and(($this->lines)($output, 'address'))->toBe([]);
});

it('refuses a sample that isn’t a whole number from 0 to 10,000', function (string $sample): void {
    [$code, $output] = ($this->print)(['--sample' => $sample]);

    expect($code)->toBe(1)
        ->and($output)->toContain('--sample must be a whole number from 0 to 10,000.')
        ->and(($this->lines)($output, 'table'))->toBe([]);
})->with(['-1', '10001', 'many', '2.5']);

it('compares with another server’s fingerprint, and says when the two sites match', function (): void {
    addrPublished($this->editor, 'Hello World');
    [, $there] = ($this->print)(['--sample' => 10]);
    $file = ($this->saved)($there);

    try {
        [$code, $output] = ($this->print)(['--compare' => $file]);
    } finally {
        unlink($file);
    }

    $tables = count(($this->lines)($there, 'table'));
    $addresses = count(array_unique(array_map(fn (string $line): string => explode(' ', $line)[1], ($this->lines)($there, 'address'))));

    expect($code)->toBe(0)
        ->and($tables)->toBeGreaterThan(10)
        ->and($addresses)->toBeGreaterThan(count(SiteFingerprint::FIXED))
        ->and($output)->toContain("# Compared with {$file}: ", "The two sites match: {$tables} tables and {$addresses} addresses compared.")
        ->and($output)->not->toContain('Differs:');
});

it('names each difference in a table or an address, asks the other server’s addresses, and lists the server’s own work without counting it', function (): void {
    addrPublished($this->editor, 'Hello World');
    [, $there] = ($this->print)(['--sample' => 10]);

    $paths = DB::table('paths')->count();
    $hello = collect(explode("\n", $there))->first(fn (string $line): bool => str_starts_with($line, 'address /hello-world/ '));
    $here = substr((string) $hello, strlen('address /hello-world/ '));
    $zeros = str_repeat('0', 64);
    $ones = str_repeat('1', 64);

    $file = ($this->saved)(str_replace(
        ["\ntable contents 1\n", "\ntable paths {$paths}\n", "\nactivity sessions 0\n", "\n{$hello}\n"],
        ["\ntable contents 2\n", "\n", "\nactivity sessions 4\n", "\naddress /hello-world/ 200 {$zeros}\naddress /only-there/ 200 {$ones}\n"],
        $there,
    ));

    try {
        [$code, $output] = ($this->print)(['--compare' => $file]);
    } finally {
        unlink($file);
    }

    expect($here)->toStartWith('200 ')
        ->and($code)->toBe(1)
        ->and(collect(($this->lines)($output, 'address'))->contains(fn (string $line): bool => str_starts_with($line, 'address /only-there/ 404 ')))->toBeTrue()
        ->and($output)->toContain(
            'Differs: table contents: 1 here, 2 there.',
            "Differs: table paths: {$paths} here, none there.",
            "Differs: address /hello-world/: {$here} here, 200 {$zeros} there.",
            'Differs: address /only-there/: 404 ',
            "200 {$ones} there.",
            'Activity, not compared: sessions: 0 here, 4 there.',
            '4 differences: each is listed above.',
        );
});

it('refuses a fingerprint it can’t read, or a file that isn’t one', function (): void {
    $missing = sys_get_temp_dir().'/no-such-fingerprint-'.uniqid();
    [$unreadCode, $unread] = ($this->print)(['--compare' => $missing]);

    $file = ($this->saved)("Hello\n");

    try {
        [$wrongCode, $wrong] = ($this->print)(['--compare' => $file]);
    } finally {
        unlink($file);
    }

    expect($unreadCode)->toBe(1)
        ->and($unread)->toContain("Teme can’t read {$missing}.")
        ->and(($this->lines)($unread, 'table'))->toBe([])
        ->and($wrongCode)->toBe(1)
        ->and($wrong)->toContain("{$file} isn’t a fingerprint: its first line should begin “# Teme site fingerprint”.")
        ->and(($this->lines)($wrong, 'table'))->toBe([]);
});

it('reads a fingerprint saved with Windows line ends and a byte-order mark as the same fingerprint', function (): void {
    addrPublished($this->editor, 'Hello World');
    [, $there] = ($this->print)(['--sample' => 10]);
    $file = ($this->saved)("\xEF\xBB\xBF".str_replace("\n", "\r\n", $there));

    try {
        [$code, $output] = ($this->print)(['--compare' => $file]);
    } finally {
        unlink($file);
    }

    expect($there)->toContain("\ntable contents 1\n")
        ->and($code)->toBe(0)
        ->and($output)->toContain('The two sites match: ')
        ->and($output)->not->toContain('Differs:');
});

it('writes an address that holds a space with %20 and one that holds a console tag as it is, so each line keeps its four parts and its characters', function (): void {
    $post = addrPublished($this->editor, 'Hello World');
    DB::table('paths')->insert(['path' => 'old path', 'target_type' => 'content', 'target_id' => (string) $post->id, 'is_current' => false]);
    DB::table('paths')->insert(['path' => 'odd<info>path', 'target_type' => 'content', 'target_id' => (string) $post->id, 'is_current' => false]);

    [$code, $output] = ($this->print)(['--sample' => 50]);
    $line = collect(($this->lines)($output, 'address'))->first(fn (string $line): bool => str_starts_with($line, 'address /old%20path/ '));
    $tagged = collect(($this->lines)($output, 'address'))->first(fn (string $line): bool => str_starts_with($line, 'address /odd<info>path/ '));

    expect($code)->toBe(0)
        ->and($line)->not->toBeNull()
        ->and(explode(' ', (string) $line))->toHaveCount(4)
        ->and($tagged)->not->toBeNull()
        ->and(explode(' ', (string) $tagged))->toHaveCount(4)
        ->and(collect(($this->lines)($output, 'address'))->every(fn (string $line): bool => count(explode(' ', $line)) === 4))->toBeTrue();
});

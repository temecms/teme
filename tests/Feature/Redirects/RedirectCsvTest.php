<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Redirects\Operations\ImportRedirects;
use App\Domain\Redirects\Operations\Input\ImportRedirectsInput;
use App\Domain\Redirects\Queries\RedirectExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->import = fn (string $csv, ?User $actor = null): array => app(ImportRedirects::class)->execute(new ImportRedirectsInput($csv, (string) ($actor ?? $this->editor)->id));
    $this->lines = fn (iterable $export): array => explode("\n", rtrim(implode('', is_array($export) ? $export : iterator_to_array($export, false)), "\n"));
});

it('imports each line checked as one added by hand, leaves out and reports those that fail, and marks the rest imported (I-872)', function (): void {
    $item = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/taken', '/news/');

    $result = ($this->import)("\xEF\xBB\xBFfrom,to,status,note,on\n"
        ."/old-fares,/ferry-fares/,301,From the print edition,\n"
        ."/obituaries/[...rest],/news/[...rest],,The old section,no\n"
        ."/withdrawn,,410,,yes\n"
        ."/taken,/elsewhere/,301,,\n"
        ."/loop,/loop/,301,,\n"
        ."/bad-status,/news/,404,,\n"
        ."/bad-on,/news/,301,,maybe\n"
        ."\n"
        ."/old-fares,/x/,301,,\n");

    expect($result)->toBe(['added' => 3, 'refused' => [
        ['line' => 5, 'reason' => 'Line 5: A redirect from /taken already exists. Edit that one instead.'],
        ['line' => 6, 'reason' => 'Line 6: That sends readers from /loop back to itself.'],
        ['line' => 7, 'reason' => 'Line 7: 404 isn’t a status a redirect can have.'],
        ['line' => 8, 'reason' => 'Line 8: Write yes or no in the on column, or leave it empty.'],
        ['line' => 10, 'reason' => 'Line 10: A redirect from /old-fares already exists. Edit that one instead.'],
    ]])
        ->and(DB::table('redirects')->where('origin', 'import')->orderBy('source')->get(['source', 'status', 'target_kind', 'target', 'enabled', 'note', 'created_by'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['source' => 'obituaries/[...rest]', 'status' => 301, 'target_kind' => 'address', 'target' => '/news/[...rest]', 'enabled' => false, 'note' => 'The old section', 'created_by' => $this->editor->id],
            ['source' => 'old-fares', 'status' => 301, 'target_kind' => 'content', 'target' => $item->id, 'enabled' => true, 'note' => 'From the print edition', 'created_by' => $this->editor->id],
            ['source' => 'withdrawn', 'status' => 410, 'target_kind' => null, 'target' => null, 'enabled' => true, 'note' => '', 'created_by' => $this->editor->id],
        ])
        ->and(json_decode((string) DB::table('security_events')->where('type', 'redirect.imported')->value('context'), true))->toEqual(['added' => 3, 'refused' => 5]);
});

it('refuses a file it can\'t read, and writes nothing', function (string $csv, string $words): void {
    expect(fn () => ($this->import)($csv))->toThrow(fn (ValidationException $refused) => expect($refused->errors())->toBe(['csv' => [$words]]))
        ->and(DB::table('redirects')->count())->toBe(0);
})->with([
    'not UTF-8' => ["from,to\n/caf\xE9,/news/\n", 'The file isn’t in UTF-8. Save it as CSV UTF-8 and import it again.'],
    'too many lines' => ["from,to\n".str_repeat("/a,/b/\n", 5001), 'This file has more than 5,000 lines. Split it, and import each part.'],
    'no redirect in it' => ["from,to,status\n\n", 'Choose a CSV file with at least one redirect in it.'],
]);

it('writes nothing for an author or a contributor (authorization)', function (string $role): void {
    $person = User::factory()->create()->assignRole($role);

    expect(fn () => ($this->import)("/old-fares,/news/\n", $person))->toThrow(AuthorizationException::class)
        ->and(DB::table('redirects')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'redirect.imported')->count())->toBe(0);
})->with(['author', 'contributor']);

it('removes the stored not-found pages an import replaces, after commit (I-297)', function (): void {
    $folder = cacheOn();
    addrGet('/old-fares/')->assertHeader('X-Teme-Cache', 'MISS');
    addrGet('/kept-missing/')->assertHeader('X-Teme-Cache', 'MISS');

    ($this->import)("/old-fares,/news/\n");

    expect(cacheKept('/old-fares/', '/kept-missing/'))->toBe(['/kept-missing/']);
    cacheOff($folder);
});

it('exports every redirect as CSV, items at their current addresses, never letting a spreadsheet run a note as a formula, and reads its own file back (I-872)', function (): void {
    $item = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/a', item: $item->id);
    rdSaved('/b,c', '/news/');
    rdSaved('/withdrawn', status: 410, on: false);
    DB::table('redirects')->where('source', 'a')->update(['note' => '=HYPERLINK("https://evil.example")']);

    $csv = ($this->lines)(app(RedirectExport::class)->csv());

    expect($csv[0])->toBe('from,to,status,note,on')
        ->and(array_slice($csv, 1))->toEqualCanonicalizing([
            '/a,/ferry-fares/,301,"\'=HYPERLINK(""https://evil.example"")",yes',
            '"/b,c",/news/,301,,yes',
            '/withdrawn,,410,,no',
        ]);

    DB::table('redirects')->delete();

    expect(($this->import)(implode("\n", $csv)."\n")['added'])->toBe(3)
        ->and(DB::table('redirects')->where('source', 'a')->value('note'))->toBe('=HYPERLINK("https://evil.example")')
        ->and(DB::table('redirects')->where('source', 'a')->value('target'))->toBe($item->id)
        ->and(DB::table('redirects')->where('source', 'withdrawn')->value('enabled'))->toBeFalse();
});

it('exports nginx rules for the redirects that are on and not waiting, every regex and address quoted (I-872)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00'));
    $hidden = addrPublished($this->editor, 'Market day');
    app(UnpublishContent::class)->execute(new ContentActionInput($hidden->id, (string) $this->editor->id));
    rdSaved('/old.fares', '/news/');
    rdSaved('/2019/05/[slug]', '/[slug]/');
    rdSaved('/blog/[...rest]', 'https://archive.example/[...rest]?a=$b');
    rdSaved('/withdrawn', status: 410);
    rdSaved('/switched-off', '/news/', on: false);
    rdSaved('/market', item: $hidden->id);

    $nginx = ($this->lines)(app(RedirectExport::class)->nginx());

    expect(array_slice($nginx, 0, 2))->toBe([
        '# Redirects from localhost, exported by Teme on 8 October 2026. Redirects that are switched off, or wait for their item or file, are left out.',
        '# Items are written at their current addresses: export again after one moves.',
    ])->and(array_slice($nginx, 2))->toEqualCanonicalizing([
        'location ~* "^/old\\\\.fares/?$" { return 301 "/news/"; }',
        'location ~* "^/2019/05/([^/]+)/?$" { return 301 "/$1/"; }',
        'location ~* "^/blog/(.+)/?$" { return 301 "https://archive.example/$1?a=%24b"; }',
        'location ~* "^/withdrawn/?$" { return 410; }',
    ]);
});

it('finds each exported item\'s address through the paths index, never reading every address (I-872)', function (): void {
    $fares = addrPublished($this->editor, 'Ferry fares');
    rdSaved('/old-fares', item: $fares->id);
    DB::statement("INSERT INTO paths (path, target_type, target_id, is_current, created_at) SELECT 'filler-' || g, 'term', lpad(g::text, 26, '0'), true, now() FROM generate_series(1, 3000) g");
    DB::statement('ANALYZE paths');
    DB::statement('ANALYZE contents');
    DB::statement('ANALYZE redirects');
    // Every table the test filled is analyzed, and the plans that read every address are ruled out, so the plan left shows the index can serve the join, by an index scan or a bitmap one.
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_hashjoin = off');
    DB::statement('SET LOCAL enable_mergejoin = off');

    $plan = genericPlanOf(fn () => iterator_to_array(app(RedirectExport::class)->csv(), false), '"paths" as "p"');

    expect(planReadsIndex($plan, 'paths_\w+', 'paths p'))->toBeTrue($plan)
        ->and($plan)->toMatch('/Index Cond: .*\(target_id\)::text = \(c\.id\)::text/')
        ->not->toContain('Seq Scan on paths')
        ->and(($this->lines)(app(RedirectExport::class)->csv()))->toContain('/old-fares,/ferry-fares/,301,,yes');
});

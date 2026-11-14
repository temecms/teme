<?php

declare(strict_types=1);

use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\ImportOldRedirect;
use App\Domain\Import\Operations\Input\ImportOldRedirectInput;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Redirects\RedirectsStep;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Redirects\Operations\ImportRedirect;
use App\Domain\Redirects\Operations\Input\ImportRedirectInput;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    [$this->source, $this->run] = OldSite::running('redirects');
});

/**
 * The step walked to its end as the operator, or as $actor; its run messages.
 *
 * @return list<ImportMessage>
 */
function rsRun(string $source, string $run, OldSite $site, ?User $actor = null): array
{
    $walk = fn (): array => OldSite::walk(app(RedirectsStep::class), OldSite::context($source, $run, $actor), $site);

    return $actor === null ? scElevated($walk) : $walk();
}

function rsRow(string $source, string $id): ImportMapEntry
{
    return ImportMapEntry::query()->where('source_id', $source)->where('object_type', 'redirect')->where('object_id', $id)->sole();
}

/**
 * @return list<string>
 */
function rsCodes(string $source, string $id): array
{
    return DB::table('import_messages')->where('map_id', rsRow($source, $id)->id)->orderBy('code')->pluck('code')->all();
}

/**
 * A Redirection rule, as the companion sends it.
 *
 * @return array<string, mixed>
 */
function rsRule(int $id, string $from, string $to, int $code = 301): array
{
    return ['id' => $id, 'url' => $from, 'match_type' => 'url', 'action_type' => 'url', 'action_code' => $code, 'action_data' => ['url' => $to], 'regex' => 0, 'status' => 'enabled'];
}

/**
 * Each redirect's source and where it leads, by source.
 *
 * @return array<string, string|null>
 */
function rsTargets(): array
{
    return DB::table('redirects')->orderBy('source')->pluck('target', 'source')->all();
}

it('brings every store’s rules as redirects marked imported, one map row a rule (I-297)', function (): void {
    $site = (new OldSite)
        ->redirects('redirection', [rsRule(1, '/old-page/', '/new-page/')])
        ->redirects('rank_math', [['id' => 2, 'sources' => [['pattern' => 'shop/', 'comparison' => 'start']], 'url_to' => 'https://store.example/', 'header_code' => 302, 'status' => 'active']])
        ->redirects('safe_redirect_manager', [['id' => 3, 'status' => 'publish', 'from' => '/gone-page', 'to' => '', 'code' => 410, 'regex' => false]])
        ->redirects('yoast_premium', [['origin' => 'old-one', 'url' => 'new-one', 'type' => 301, 'format' => 'plain']])
        ->redirects('simple_301', ['/legacy' => '/archive/']);

    expect(rsRun($this->source, $this->run, $site))->toBe([]);

    $rows = DB::table('redirects')->orderBy('source')->get(['source', 'kind', 'status', 'target_kind', 'target', 'origin'])->map(fn (object $row): array => (array) $row)->all();

    expect($rows)->toBe([
        ['source' => 'gone-page', 'kind' => 'exact', 'status' => 410, 'target_kind' => null, 'target' => null, 'origin' => 'import'],
        ['source' => 'legacy', 'kind' => 'exact', 'status' => 301, 'target_kind' => 'address', 'target' => '/archive/', 'origin' => 'import'],
        ['source' => 'old-one', 'kind' => 'exact', 'status' => 301, 'target_kind' => 'address', 'target' => '/new-one', 'origin' => 'import'],
        ['source' => 'old-page', 'kind' => 'exact', 'status' => 301, 'target_kind' => 'address', 'target' => '/new-page/', 'origin' => 'import'],
        ['source' => 'shop', 'kind' => 'exact', 'status' => 302, 'target_kind' => 'outside', 'target' => 'https://store.example/', 'origin' => 'import'],
        ['source' => 'shop/[...rest]', 'kind' => 'pattern', 'status' => 302, 'target_kind' => 'outside', 'target' => 'https://store.example/', 'origin' => 'import'],
    ])
        ->and(ImportMapEntry::query()->where('object_type', 'redirect')->orderBy('object_id')->pluck('object_id')->all())->toBe(['rank-math:2', 'redirection:1', 'safe-redirect-manager:3', 'simple-301:/legacy', 'yoast-premium:old-one'])
        ->and(rsRow($this->source, 'rank-math:2')->outcome)->toBe(ImportOutcome::Created)
        ->and(rsRow($this->source, 'rank-math:2')->teme_type)->toBe('redirect')
        ->and(array_keys(rsRow($this->source, 'rank-math:2')->facts['redirects']))->toEqualCanonicalizing(['/shop/', '/shop/[...rest]'])
        ->and(array_map(fn (array $request): string => $request['query']['source'] ?? '', $site->requests))->toBe(['redirection', 'rank_math', 'safe_redirect_manager', 'yoast_premium', 'simple_301']);
});

it('sends readers where the old rule did, by ID wherever the import brought the thing, never to the old site’s own host (rule 9)', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    $media = mediaUpload($this->admin, mediaJpeg(), 'photo.jpg');
    ImportRecords::entry($this->source, $this->run, 'post', '12', 'created', ['teme_type' => 'content', 'teme_id' => $post->id]);
    ImportRecords::entry($this->source, $this->run, 'attachment', '30', 'created', ['teme_type' => 'media', 'teme_id' => $media->id]);
    ImportRecords::entry($this->source, $this->run, 'file', '2024/05/photo.jpg', 'created', ['teme_type' => 'media', 'teme_id' => $media->id, 'run_id' => null]);
    $site = (new OldSite)->redirects('redirection', [
        rsRule(1, '/a', 'https://www.old.example/harbour-news/'),
        rsRule(2, '/b', 'https://old.example/?p=12'),
        rsRule(3, '/c', '/?attachment_id=30'),
        rsRule(4, '/d', '/wp-content/uploads/2024/05/photo-300x200.jpg'),
        rsRule(5, '/e', 'https://elsewhere.example/page'),
        rsRule(6, '/f', 'https://old.example/somewhere/?utm=x'),
        ['id' => 7, 'url' => '^/old/(.*)$', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => 'https://old.example/$1'], 'regex' => 1, 'status' => 'enabled'],
    ]);

    rsRun($this->source, $this->run, $site);
    $to = fn (string $from): array => (array) DB::table('redirects')->where('source', $from)->first(['target_kind', 'target']);

    expect($to('a'))->toBe(['target_kind' => 'content', 'target' => $post->id])
        ->and($to('b'))->toBe(['target_kind' => 'content', 'target' => $post->id])
        ->and($to('c'))->toBe(['target_kind' => 'media', 'target' => $media->id])
        ->and($to('d'))->toBe(['target_kind' => 'media', 'target' => $media->id])
        ->and($to('e'))->toBe(['target_kind' => 'outside', 'target' => 'https://elsewhere.example/page'])
        ->and($to('f'))->toBe(['target_kind' => 'address', 'target' => '/somewhere/?utm=x'])
        ->and($to('old/[...p1]'))->toBe([])
        ->and(rsCodes($this->source, 'redirection:7'))->toBe(['redirect.regex'])
        ->and(DB::table('redirects')->where('target', 'like', '%old.example%')->count())->toBe(0);
});

it('replaces the import’s own attachment-page redirect, but keeps a comment page’s and a person’s, saying so', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    $media = mediaUpload($this->admin, mediaJpeg(), 'photo.jpg');
    scElevated(fn () => app(ImportRedirect::class)->execute(new ImportRedirectInput(from: '/photo/', mediaId: $media->id)));
    scElevated(fn () => app(ImportRedirect::class)->execute(new ImportRedirectInput(from: '/harbour-news/comment-page-2/', to: '/harbour-news/comments/page/2/')));
    rdSaved('/mine/', '/somewhere/');
    $site = (new OldSite)->redirects('redirection', [
        rsRule(1, '/photo/', '/harbour-news/'),
        rsRule(2, '/harbour-news/comment-page-2/', '/harbour-news/'),
        rsRule(3, '/mine/', '/elsewhere/'),
    ]);

    rsRun($this->source, $this->run, $site);

    expect(rsTargets())->toBe(['harbour-news/comment-page-2' => '/harbour-news/comments/page/2/', 'mine' => '/somewhere/', 'photo' => $post->id])
        ->and(rsRow($this->source, 'redirection:1')->outcome)->toBe(ImportOutcome::Updated)
        ->and(rsCodes($this->source, 'redirection:2'))->toBe(['redirect.kept'])
        ->and(rsCodes($this->source, 'redirection:3'))->toBe(['redirect.kept'])
        ->and(rsRow($this->source, 'redirection:3')->outcome)->toBe(ImportOutcome::Skipped)
        ->and(DB::table('import_messages')->where('code', 'redirect.kept')->orderBy('message')->pluck('message')->first())->toBe('Teme already has a redirect from /harbour-news/comment-page-2/, so that one was kept as it is.');
});

it('makes no chain of the old site’s chains, in either order, and refuses a loop in words (I-859)', function (): void {
    $site = (new OldSite)->redirects('redirection', [
        rsRule(1, '/a', '/b'),
        rsRule(2, '/b', '/c'),
        rsRule(3, '/d', '/a'),
        rsRule(4, '/x', '/y'),
        rsRule(5, '/y', '/x'),
    ]);

    rsRun($this->source, $this->run, $site);

    expect(rsTargets())->toBe(['a' => '/c', 'b' => '/c', 'd' => '/c', 'x' => '/y'])
        ->and(rsCodes($this->source, 'redirection:5'))->toBe(['redirect.refused'])
        ->and(DB::table('import_messages')->where('code', 'redirect.refused')->value('message'))->toBe('Teme couldn’t add its redirect from /y: it would send readers back to /y.');
});

it('writes nothing again for an unchanged rule, follows a changed one, and keeps what a person changed or deleted since', function (): void {
    $rules = [rsRule(1, '/a', '/new-a'), rsRule(2, '/b', '/new-b'), rsRule(3, '/c', '/new-c')];
    rsRun($this->source, $this->run, (new OldSite)->redirects('redirection', $rules));
    $this->travel(1)->minutes();
    // A person changes b's redirect here, and deletes c's.
    DB::table('redirects')->where('source', 'b')->update(['target' => '/mine', 'target_path' => 'mine', 'updated_at' => now()]);
    DB::table('redirects')->where('source', 'c')->delete();

    rsRun($this->source, $this->run, (new OldSite)->redirects('redirection', $rules));
    expect(rsTargets())->toBe(['a' => '/new-a', 'b' => '/mine']);

    $changed = array_map(fn (array $rule): array => [...$rule, 'action_data' => ['url' => $rule['action_data']['url'].'-2']], $rules);
    rsRun($this->source, $this->run, (new OldSite)->redirects('redirection', $changed));

    expect(rsTargets())->toBe(['a' => '/new-a-2', 'b' => '/mine'])
        ->and(rsCodes($this->source, 'redirection:2'))->toBe(['redirect.kept-here'])
        ->and(rsCodes($this->source, 'redirection:3'))->toBe(['redirect.deleted-here'])
        ->and(rsRow($this->source, 'redirection:3')->outcome)->toBe(ImportOutcome::Skipped)
        ->and(rsRow($this->source, 'redirection:1')->outcome)->toBe(ImportOutcome::Updated);
});

it('reports a rule that doesn’t convert as skipped, and doesn’t read it as work again while it is unchanged', function (): void {
    $site = (new OldSite)->redirects('rank_math', [['id' => 7, 'sources' => [['pattern' => 'promo', 'comparison' => 'contains']], 'url_to' => '/x/', 'header_code' => 301, 'status' => 'active']]);

    rsRun($this->source, $this->run, $site);

    expect(rsRow($this->source, 'rank-math:7')->outcome)->toBe(ImportOutcome::Skipped)
        ->and(rsCodes($this->source, 'rank-math:7'))->toBe(['redirect.comparison'])
        ->and(DB::table('redirects')->count())->toBe(0);

    DB::table('import_messages')->delete();
    rsRun($this->source, $this->run, $site);

    expect(DB::table('import_messages')->count())->toBe(0);
});

it('reads nothing for a store the old site hasn’t, and says when a store’s answer can’t be read', function (): void {
    $said = rsRun($this->source, $this->run, (new OldSite)->redirects('yoast_premium', 'not a list of rules'));

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->message], $said))->toBe([
        ['redirects.unreadable', 'The old site’s Yoast SEO Premium rules couldn’t be read, so none of them came across. Add them in Tools › Redirects.'],
    ])->and(DB::table('redirects')->count())->toBe(0);
});

it('carries on at its cursor, within a store and between them', function (): void {
    $site = (new OldSite)
        ->redirects('redirection', [rsRule(1, '/old-1', '/new-1'), rsRule(2, '/old-2', '/new-2'), rsRule(3, '/old-3', '/new-3')])
        ->redirects('simple_301', ['/one' => '/1', '/two' => '/2']);

    scElevated(fn () => app(RedirectsStep::class)->run(OldSite::context($this->source, $this->run), $site, 'simple_301:1'));
    expect(array_keys(rsTargets()))->toBe(['two']);

    scElevated(fn () => app(RedirectsStep::class)->run(OldSite::context($this->source, $this->run), $site, 'redirection:2'));
    expect(array_keys(rsTargets()))->toBe(['old-3', 'one', 'two']);
});

it('writes nothing when the person may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $input = new ImportOldRedirectInput($this->source, $this->run, SourceRef::of('redirect', 'redirection:1'), 'a-hash', [['from' => '/a', 'to' => '/b', 'contentId' => null, 'mediaId' => null, 'status' => 301]], [], (string) $editor->id);

    expect(fn () => app(ImportOldRedirect::class)->execute($input))->toThrow(AuthorizationException::class)
        ->and(DB::table('redirects')->count())->toBe(0)
        ->and(ImportMapEntry::query()->count())->toBe(0);
});

it('registers the redirects step at 900, for every kind of run', function (): void {
    $phase = app(ImportPhases::class)->find('redirects');

    expect($phase)->toBeInstanceOf(RedirectsStep::class)
        ->and($phase?->order())->toBe(900)
        ->and($phase?->label())->toBe('Redirects')
        ->and($phase?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});

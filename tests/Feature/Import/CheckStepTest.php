<?php

declare(strict_types=1);

use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Phases\Addresses\OldAddressesStep;
use App\Domain\Import\Phases\Check\CheckStep;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Redirects\RedirectsStep;
use App\Domain\Import\Phases\Settings\SiteSettingsStep;
use App\Domain\Import\Queries\ImportScreen;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\ImportRecords;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    [$this->source, $this->run, $this->token] = OldSite::running('check');
});

/**
 * The check walked to its end as the operator, its messages recorded as the run's.
 *
 * @return list<ImportMessage>
 */
function ckRun(string $source, string $run, string $token, OldSite $site): array
{
    return scElevated(fn (): array => OldSite::walk(app(CheckStep::class), OldSite::context($source, $run), $site, $token));
}

it('passes every old address that answers: a page, an old address, a redirect, a file, a category, an author and the home page (I-339)', function (): void {
    $post = addrPublished($this->admin, 'Harbour news');
    addrRename($post, 'harbour', $this->admin);
    $media = mediaUpload($this->admin, mediaJpeg(), 'photo.jpg');
    ImportRecords::entry($this->source, $this->run, 'file', '2024/05/photo.jpg', 'created', ['teme_type' => 'media', 'teme_id' => $media->id, 'run_id' => null]);
    rdSaved('/attachment/photo/', file: $media->id);
    $person = User::factory()->create();
    DB::table('paths')->insert([
        ['path' => 'category/news', 'target_type' => 'term', 'target_id' => taxonomyTerm('category', 'News')->id, 'is_current' => true],
        ['path' => 'author/ada', 'target_type' => 'user', 'target_id' => (string) $person->id, 'is_current' => true],
    ]);
    $site = (new OldSite)
        ->urls('post', [['id' => 1, 'type' => 'post', 'url' => OldSite::HOME.'/harbour/'], ['id' => 2, 'type' => 'post', 'url' => OldSite::HOME.'/harbour-news/']])
        ->urls('term', [['id' => 3, 'taxonomy' => 'category', 'url' => OldSite::HOME.'/category/news/']])
        ->urls('author', [['id' => 4, 'url' => OldSite::HOME.'/author/ada/']])
        ->urls('attachment_page', [['id' => 5, 'url' => OldSite::HOME.'/attachment/photo/']])
        ->urls('attachment_file', [['id' => 5, 'files' => ['full' => OldSite::HOME.'/wp-content/uploads/2024/05/photo.jpg', 'medium' => OldSite::HOME.'/wp-content/uploads/2024/05/photo-300x200.jpg']]])
        ->urls('archive', [['id' => 0, 'kind' => 'home', 'url' => OldSite::HOME.'/']]);

    expect(ckRun($this->source, $this->run, $this->token, $site))->toBe([])
        ->and($site->urlRequests())->toBe(['post:0', 'term:0', 'author:0', 'attachment_page:0', 'attachment_file:0', 'archive:whole']);
});

it('lists each old address that doesn’t answer, as an error that holds up the switch', function (): void {
    $withdrawn = addrPublished($this->admin, 'Withdrawn');
    pageTrash($withdrawn, $this->admin);
    rdSaved('/moved/', '/nowhere/');
    rdSaved('/gone/', status: 410);
    $site = (new OldSite)
        ->urls('post', [
            ['id' => 1, 'type' => 'post', 'url' => OldSite::HOME.'/never-came/'],
            ['id' => 2, 'type' => 'product', 'url' => OldSite::HOME.'/products/kettle/'],
            ['id' => 3, 'type' => 'post', 'url' => OldSite::HOME.'/moved/'],
            ['id' => 4, 'type' => 'post', 'url' => OldSite::HOME.'/?p=4'],
            ['id' => 5, 'type' => 'post', 'url' => OldSite::HOME.'/withdrawn/'],
            ['id' => 6, 'type' => 'page', 'url' => OldSite::HOME.'/gone/'],
        ])
        ->urls('term', [['id' => 7, 'taxonomy' => 'post_format', 'url' => OldSite::HOME.'/type/aside/']])
        ->urls('attachment_file', [['id' => 8, 'files' => ['full' => 'https://cdn.example/wp-content/uploads/a.jpg', 'medium' => 'https://cdn.example/wp-content/uploads/a-300x200.jpg']]])
        ->urls('archive', [['id' => 0, 'kind' => 'archive', 'type' => 'product', 'url' => OldSite::HOME.'/products/']]);

    $said = ckRun($this->source, $this->run, $this->token, $site);

    expect(array_map(fn (ImportMessage $message): array => [$message->code, $message->level, $message->message], $said))->toBe([
        ['check.not-found', ImportMessageLevel::Error, '/never-came/, a post on the old site, answers “not found” here. Bring what it showed, or add a redirect for it in Tools › Redirects.'],
        ['check.not-brought', ImportMessageLevel::Error, '/products/kettle/, an item of product on the old site, answers “not found” here, because Teme doesn’t bring that kind of thing. Add a redirect for it in Tools › Redirects: one pattern, such as /products/[...rest], covers every address under one part.'],
        ['check.redirect-not-found', ImportMessageLevel::Error, '/moved/, a post on the old site, is sent on to http://localhost/nowhere/, which answers “not found”. Change where it leads in Tools › Redirects.'],
        ['check.query', ImportMessageLevel::Error, '/?p=4, a post on the old site, differs from other addresses only by what follows the ?, which Teme doesn’t tell apart, so readers following it reach the home page.'],
        ['check.not-found', ImportMessageLevel::Error, '/withdrawn/, a post on the old site, answers “not found” here. Bring what it showed, or add a redirect for it in Tools › Redirects.'],
        ['check.other-status', ImportMessageLevel::Warning, '/gone/, a page on the old site, answers 410 here.'],
        ['check.not-brought', ImportMessageLevel::Error, '/type/aside/, a term of post_format on the old site, answers “not found” here, because Teme doesn’t bring that kind of thing. Add a redirect for it in Tools › Redirects: one pattern, such as /type/[...rest], covers every address under one part.'],
        ['check.not-brought', ImportMessageLevel::Error, '/products/, the archive of product on the old site, answers “not found” here, because Teme doesn’t bring that kind of thing. Add a redirect for it in Tools › Redirects: one pattern, such as /products/[...rest], covers every address under one part.'],
        ['check.other-host', ImportMessageLevel::Warning, 'Addresses on cdn.example, such as https://cdn.example/wp-content/uploads/a.jpg, belong to another site, so Teme didn’t check them.'],
    ])
        ->and($said[0]->context)->toBe(['address' => '/never-came/', 'kind' => 'post', 'id' => 1])
        ->and(DB::table('import_messages')->where('run_id', $this->run)->where('level', 'error')->count())->toBe(7);
});

it('starts again with its earlier messages cleared, and names another host once in a run', function (): void {
    $site = (new OldSite)
        ->urls('post', [['id' => 1, 'type' => 'post', 'url' => OldSite::HOME.'/never-came/']])
        ->urls('attachment_file', [['id' => 2, 'files' => ['full' => 'https://cdn.example/a.jpg']]]);

    ckRun($this->source, $this->run, $this->token, $site);
    ckRun($this->source, $this->run, $this->token, $site);
    $again = scElevated(fn () => app(CheckStep::class)->run(OldSite::context($this->source, $this->run), $site, 'attachment_file:0'));

    expect(DB::table('import_messages')->where('run_id', $this->run)->orderBy('code')->pluck('code')->all())->toBe(['check.not-found', 'check.other-host'])
        ->and($again->messages)->toBe([]);
});

it('reads every kind in order, a page at a time, and carries on at its cursor', function (): void {
    $site = (new OldSite)->urls('post', array_map(fn (int $id): array => ['id' => $id, 'type' => 'post', 'url' => OldSite::HOME.'/'], range(1, 501)));

    expect(ckRun($this->source, $this->run, $this->token, $site))->toBe([])
        ->and($site->urlRequests())->toBe(['post:0', 'post:500', 'term:0', 'author:0', 'attachment_page:0', 'attachment_file:0', 'archive:whole']);

    $later = new OldSite;
    scElevated(fn () => app(CheckStep::class)->run(OldSite::context($this->source, $this->run), $later, 'attachment_file:0'));

    expect($later->urlRequests())->toBe(['attachment_file:0', 'archive:whole']);
});

it('registers the check at 1100 as `check`, for every kind of run', function (): void {
    $phase = app(ImportPhases::class)->find('check');

    expect($phase)->toBeInstanceOf(CheckStep::class)
        ->and($phase?->order())->toBe(1100)
        ->and($phase?->label())->toBe('Address check')
        ->and($phase?->runsIn(ImportRunKind::CatchUp))->toBeTrue();
});

it('runs the old addresses, the redirects, the settings and the check in their order, and a run that passes is ready to switch', function (): void {
    // The run beforeEach left running would hold this one back.
    DB::table('import_runs')->where('id', $this->run)->update(['state' => 'finished', 'phase_token' => null, 'ended_at' => now()]);
    $fake = importFake();
    $source = importSource();
    importPhaseAlone(app(RedirectsStep::class), app(OldAddressesStep::class), app(SiteSettingsStep::class), app(CheckStep::class));
    addrPublished($this->admin, 'Harbour news');
    $page = fn (array $items): array => ['api' => 1, 'items' => $items, 'next' => null, 'limit' => 500];
    $fake->answer('redirects', 200, $page([['id' => 9, 'url' => '/old-news/', 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => '/harbour-news/'], 'regex' => 0]]))
        ->answer('redirects', 200, $page([]))
        ->answer('redirects', 200, $page([]))
        ->answer('redirects', 200, ['api' => 1, 'plain' => null, 'regex' => null])
        ->answer('redirects', 200, ['api' => 1, 'rules' => null])
        ->items('urls', [
            ['id' => 1, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/harbour-news/'],
            ['id' => 2, 'kind' => 'post', 'type' => 'post', 'url' => FakeCompanion::SITE.'/old-news/'],
        ]);

    $run = importRun($source);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('redirects')->where('source', 'old-news')->value('target_kind'))->toBe('content')
        ->and(app(ImportScreen::class)->report($run->id)['ready'] ?? null)->toBeTrue();
});

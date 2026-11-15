<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Paths;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Phases\Addresses\OldSiteUrls;
use App\Domain\Import\Phases\ImportPhase;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Media\Models\Media;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Redirects\RedirectSource;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Themes\ActiveTheme;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Import\AcceptanceSite;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\MenuImports;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    $this->site = AcceptanceSite::serve();
    $this->source = importSource();
});

/**
 * What a reader meets at an old address: 'page'; 'moved' (a redirect on
 * this site that reaches a page within two hops); 'away' (a redirect to a
 * file or another site); or the status it ended on.
 */
function accAnswer(string $address): string|int
{
    $own = OldSiteUrls::own($address, FakeCompanion::HOST);
    $response = addrGet($own['path']);

    for ($hop = 0; $hop <= 2; $hop++) {
        if ($response->getStatusCode() === 200) {
            return $hop === 0 ? 'page' : 'moved';
        }

        if (! in_array($response->getStatusCode(), [301, 302, 307, 308], true)) {
            return $response->getStatusCode();
        }

        $next = RedirectSource::onThisSite((string) $response->headers->get('Location'));

        if ($next === null || Paths::taken(explode('/', trim($next, '/'))[0])) {
            return 'away';
        }

        $response = addrGet($next);
    }

    return $response->getStatusCode();
}

/**
 * The codes of the messages on one object's line of the report.
 *
 * @return list<string>
 */
function accCodes(string $runId, string $type, string $wordpressId): array
{
    return DB::table('import_messages')
        ->join('import_map', 'import_map.id', '=', 'import_messages.map_id')
        ->where('import_messages.run_id', $runId)
        ->where('import_map.object_type', $type)
        ->where('import_map.object_id', $wordpressId)
        ->pluck('import_messages.code')
        ->all();
}

it('answers every address the old site listed after a whole import through every step, with a page or a redirect that reaches one (criterion 6)', function (): void {
    $run = $this->site->import($this->source);
    $answers = [];

    foreach ($this->site->addresses() as $address) {
        $answers[$address] = accAnswer($address);
    }

    expect($run->fresh()->state)->toBe(ImportRunState::Finished)
        ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'like', 'check.%')->where('level', 'error')->pluck('message')->all())->toBe([])
        ->and(array_filter($answers, fn (string|int $answer): bool => ! in_array($answer, ['page', 'moved', 'away'], true)))->toBe([])
        ->and($answers[FakeCompanion::SITE.'/tide-tables/'])->toBe('page')
        ->and($answers[FakeCompanion::SITE.'/about/team/'])->toBe('page')
        ->and($answers[FakeCompanion::SITE.'/products/kettle/'])->toBe('moved')
        ->and($answers[FakeCompanion::SITE.'/tide-tables/harbour/'])->toBe('moved')
        ->and($answers[FakeCompanion::SITE.'/wp-content/uploads/2024/05/harbour.jpg'])->toBe('away');

    $this->artisan('teme:import:walk', ['address' => FakeCompanion::SITE])->assertExitCode(0);
});

it('leaves the mark of every step it registers, so none skips in silence', function (string $key, Closure $mark): void {
    $run = $this->site->import($this->source);

    // The catch-up's step returns the run it ran; every other step's mark is on the full run.
    $ran = $mark->call($this, $run) ?? $run;

    expect(DB::table('job_batches')->where('name', "import:{$ran->id}:{$key}")->value('finished_at'))->not->toBeNull();
})->with(accSteps());

it('has a mark for every step the app registers, and for no other', function (): void {
    expect(array_keys(accSteps()))->toEqualCanonicalizing(array_map(fn (ImportPhase $phase): string => $phase->key(), app(ImportPhases::class)->all()));
});

it('gives every object of the old site its line in the report with the outcome it should have, and says on its line what it left out and why', function (): void {
    $run = $this->site->import($this->source);
    $lines = array_slice(iterator_to_array(app(ImportScreen::class)->lines($run->id, false), false), 1);
    $outcomes = [];

    foreach ($lines as $line) {
        [$type, $id, $outcome] = str_getcsv(trim($line));

        if (in_array($type, ['user', 'term', 'attachment', 'post', 'comment', 'menu', 'redirect'], true)) {
            $outcomes["{$type}:{$id}"] = $outcome;
        }
    }

    expect($outcomes)->toEqualCanonicalizing($this->site->outcomes())
        ->and(accCodes($run->id, 'post', '22'))->toContain('shortcode.removed')
        ->and(accCodes($run->id, 'post', '23'))->toContain('imported.removed')
        ->and(accCodes($run->id, 'post', '51'))->toBe(['post.other-type']);
});

it('keeps every address answering through a catch-up after a post is renamed and another deleted on WordPress, and lists the deleted one (I-345)', function (): void {
    $this->site->import($this->source);
    $this->travel(1)->hours();
    $this->site->renameAndDelete();

    $catchUp = $this->site->import($this->source, ImportRunKind::CatchUp);
    $answers = array_map(accAnswer(...), $this->site->addresses());
    $deleted = DB::table('import_messages')->where('run_id', $catchUp->id)->where('code', 'catch-up.deleted')->pluck('context')->all();

    expect($catchUp->fresh()->state)->toBe(ImportRunState::Finished)
        ->and(array_filter($answers, fn (string|int $answer): bool => ! in_array($answer, ['page', 'moved', 'away'], true)))->toBe([])
        ->and((string) addrGet('/tide-tables/')->headers->get('Location'))->toEndWith('/tide-times/')
        ->and((string) addrGet('/tides/')->headers->get('Location'))->toEndWith('/tide-times/')
        ->and(array_map(fn (string $context): string => (string) json_decode($context, true)['wordpress_id'], $deleted))->toContain('23');
});

/**
 * The Teme item WordPress post or page $wordpressId became.
 */
function accItem(string $runId, int $wordpressId): Content
{
    return Content::query()->findOrFail(DB::table('import_map')->where('object_type', 'post')->where('object_id', (string) $wordpressId)->value('teme_id'));
}

/**
 * The hrefs of the links in an item's working document, in order.
 *
 * @return list<string>
 */
function accLinks(Content $item): array
{
    $document = ContentRevision::query()->findOrFail($item->refresh()->working_revision_id)->document;
    $found = [];
    $walk = function (array $nodes) use (&$walk, &$found): void {
        foreach ($nodes as $node) {
            foreach ((array) ($node['marks'] ?? []) as $mark) {
                if (($mark['type'] ?? null) === 'link') {
                    $found[] = (string) $mark['attrs']['href'];
                }
            }

            $walk((array) ($node['content'] ?? []));
        }
    };
    $walk((array) ($document['content'] ?? []));

    return $found;
}

/**
 * One row for each step the app registers, named by its key: the key, and a
 * closure that asserts what that step, and only it, writes on this site,
 * run with the test as $this once a whole import has finished. A step whose
 * condition this site doesn’t meet writes nothing to show: its mark is the
 * batch the test checks for every row, and the closure does nothing. The
 * `deleted` step’s closure runs the catch-up it needs and returns it.
 *
 * @return array<string, array{0: string, 1: Closure(ImportRun): (ImportRun|null)}>
 */
function accSteps(): array
{
    $alone = fn (ImportRun $run): null => null;
    $terms = fn (string $name): Term => Term::query()->where('name', $name)->firstOrFail();

    return [
        'address-clash' => ['address-clash', $alone],
        'address-settings' => ['address-settings', function (ImportRun $run): null {
            expect(DB::table('import_map')->where('object_type', 'setting')->where('object_id', 'address-settings')->value('outcome'))->toBe('created')
                ->and(AddressSettings::current()->patterns['post'])->toBe('/{slug}/');

            return null;
        }],
        'users' => ['users', function (ImportRun $run): null {
            expect(User::query()->where('email', 'ben@old.example')->firstOrFail()->hasRole('editor'))->toBeTrue();

            return null;
        }],
        'authors-waiting' => ['authors-waiting', $alone],
        'terms' => ['terms', function (ImportRun $run) use ($terms): null {
            expect([$terms('News')->taxonomy, $terms('Harbour')->taxonomy, $terms('tides')->taxonomy, $terms('ferries')->taxonomy])->toBe(['category', 'category', 'tag', 'tag']);

            return null;
        }],
        'term-parents' => ['term-parents', function (ImportRun $run) use ($terms): null {
            expect($terms('Harbour')->parent_id)->toBe($terms('News')->id);

            return null;
        }],
        'old-addresses' => ['old-addresses', function (ImportRun $run) use ($terms): null {
            $moved = addrGet('/category/harbour/');

            expect($moved->getStatusCode())->toBe(301)
                ->and((string) $moved->headers->get('Location'))->toEndWith('/category/news/harbour/')
                ->and(DB::table('paths')->where('path', 'category/harbour')->value('target_id'))->toBe($terms('Harbour')->id);

            return null;
        }],
        'media' => ['media', function (ImportRun $run): null {
            expect(Media::query()->count())->toBe(2)
                ->and(Media::query()->where('title', 'Harbour at dawn')->firstOrFail()->sha256)->toBe(hash('sha256', $this->site->bytes('harbour')));

            return null;
        }],
        'outside-images' => ['outside-images', function (ImportRun $run): null {
            expect(accCodes($run->id, 'post', '22'))->toContain('image.outside');

            return null;
        }],
        'content-visibility' => ['content-visibility', $alone],
        'content-waiting' => ['content-waiting', $alone],
        'content' => ['content', function (ImportRun $run): null {
            $row = fn (int $id): array => (array) DB::table('contents')->where('id', accItem($run->id, $id)->id)->first(['title', 'status', 'visibility']);

            expect([$row(21)['title'], $row(21)['status'], $row(25)['visibility'], $row(24)['status']])->toBe(['Tide tables', 'published', 'private', 'draft']);

            return null;
        }],
        'page-parents' => ['page-parents', function (ImportRun $run): null {
            expect(accItem($run->id, 42)->parent_id)->toBe(accItem($run->id, 41)->id);

            return null;
        }],
        'media-parents' => ['media-parents', function (ImportRun $run): null {
            $harbour = Media::query()->where('title', 'Harbour at dawn')->firstOrFail();

            expect($harbour->uploaded_for)->toBe(accItem($run->id, 21)->id);

            return null;
        }],
        'plugin-options' => ['plugin-options', $alone],
        'plugin-data' => ['plugin-data', function (ImportRun $run): ImportRun {
            // A plugin with data to bring, on a companion that sends it, so the phase has lists to read.
            PluginFixtures::use('importer');
            $manifest = PluginFixtures::manifest('importer');
            scElevated(fn (): string => app(EnablePlugin::class)->execute(new EnablePluginInput('tests/importer', $manifest['version'], $manifest['capabilities'], [], [], null)));
            app(PluginLoader::class)->load();
            $this->site->companion->items('probe', ['name' => 'teme-companion', 'version' => '0.3.0', 'api' => 2, 'application_passwords' => true]);
            $this->site->companion->wordpress(posts: [['id' => 90, 'type' => 'ap_actor', 'title' => 'Harbour master', 'meta' => []]]);
            ImportSource::query()->whereKey($this->source->id)->update(['api_version' => 2]);
            $this->travel(1)->hours();
            $catchUp = $this->site->import($this->source, ImportRunKind::CatchUp);

            expect(DB::table('import_map')->where('object_type', 'plugin')->where('object_id', 'importer:post:ap_actor:90')->value('outcome'))->toBe('created');
            PluginFixtures::forget();

            return $catchUp;
        }],
        'plugin-data-gone' => ['plugin-data-gone', function (ImportRun $run): ImportRun {
            $this->travel(1)->hours();

            return $this->site->import($this->source, ImportRunKind::CatchUp);
        }],
        'second-pass' => ['second-pass', function (ImportRun $run): null {
            expect(accLinks(accItem($run->id, 21)))->toBe(['teme:content/'.accItem($run->id, 22)->id]);

            return null;
        }],
        'comments' => ['comments', function (ImportRun $run): null {
            $reply = DB::table('comments')->where('id', DB::table('import_map')->where('object_type', 'comment')->where('object_id', '62')->value('teme_id'))->first();

            expect($reply->parent_id)->toBe(DB::table('import_map')->where('object_type', 'comment')->where('object_id', '61')->value('teme_id'));

            return null;
        }],
        'comment-counts' => ['comment-counts', function (ImportRun $run): null {
            expect(cmStats(accItem($run->id, 21)))->toMatchArray(['approved_count' => 3, 'pending_count' => 0]);

            return null;
        }],
        'comment-pages' => ['comment-pages', function (ImportRun $run): null {
            expect(addrGet('/tide-tables/comment-page-2/')->getStatusCode())->toBe(301);

            return null;
        }],
        'fediverse-replies' => ['fediverse-replies', $alone],
        'menus' => ['menus', function (ImportRun $run): null {
            $items = MenuImports::stored(Navigation::query()->findOrFail(DB::table('import_map')->where('object_type', 'menu')->where('object_id', '3')->value('teme_id')));

            expect(array_column($items, 'kind'))->toBe(['term', 'content', 'url'])
                ->and($items[1]['target_id'])->toBe(accItem($run->id, 41)->id)
                ->and($items[2]['url'])->toBe('https://example.org/');

            return null;
        }],
        'menu-places' => ['menu-places', function (ImportRun $run): null {
            $navigation = DB::table('import_map')->where('object_type', 'menu')->where('object_id', '3')->value('teme_id');

            expect(array_values(array_filter(app(ThemeSlotSettings::class)->bound(app(ActiveTheme::class)->get()))))->toBe([$navigation]);

            return null;
        }],
        'widgets' => ['widgets', function (ImportRun $run): null {
            expect(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'widgets.reported')->value('message'))->toContain('sidebar-1')->toContain('text');

            return null;
        }],
        'redirects' => ['redirects', function (ImportRun $run): null {
            expect(DB::table('redirects')->whereIn('source', ['products/kettle', 'old-feed'])->where('origin', 'import')->count())->toBe(2);

            return null;
        }],
        'settings' => ['settings', function (ImportRun $run): null {
            $settings = app(SettingsReader::class);

            expect([$settings->string('general', 'site_title'), $settings->string('general', 'tagline')])->toBe(['The Harbour Gazette', 'News from the quay']);

            return null;
        }],
        'fediverse' => ['fediverse', function (ImportRun $run): null {
            expect(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'fediverse.companion-too-old')->count())->toBe(1);

            return null;
        }],
        'check' => ['check', function (ImportRun $run): null {
            expect(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'check.other-host')->pluck('context')->map(fn (string $context): string => (string) json_decode($context, true)['host'])->all())->toBe(['cdn.example'])
                ->and(DB::table('import_messages')->where('run_id', $run->id)->where('code', 'like', 'check.%')->where('level', 'error')->count())->toBe(0);

            return null;
        }],
        'fidelity' => ['fidelity', function (ImportRun $run): null {
            expect(accCodes($run->id, 'post', '22'))->toContain('fidelity.lost');

            return null;
        }],
        'recount' => ['recount', function (ImportRun $run) use ($terms): null {
            expect($terms('News')->published_count)->toBe(2);

            return null;
        }],
        'deleted' => ['deleted', function (ImportRun $run): ImportRun {
            $this->travel(1)->hours();
            $this->site->renameAndDelete();
            $catchUp = $this->site->import($this->source, ImportRunKind::CatchUp);
            $named = DB::table('import_messages')->where('run_id', $catchUp->id)->where('code', 'catch-up.deleted')->pluck('context')->map(fn (string $context): string => (string) json_decode($context, true)['wordpress_id'])->all();

            expect($named)->toContain('23');

            return $catchUp;
        }],
    ];
}

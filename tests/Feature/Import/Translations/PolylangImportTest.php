<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Phases\ImportContext;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\Languages\TranslationsStep;
use App\Domain\Import\Phases\SourceRef;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Queries\ImportMap;
use App\Domain\Import\Source\CompanionClients;
use App\Domain\Import\Source\SourceRefused;
use App\Domain\Import\Translations\ImportedLanguages;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\ThemeSlotSettings;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Settings\Models\SettingsGroupRecord;
use App\Domain\Support\Security\JobActor;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Themes\ActiveTheme;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\PolylangSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost', 'teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    Storage::fake('media');
    Storage::fake('media-originals');
    Sleep::fake();
    $this->site = PolylangSite::serve();
    $this->source = importSource();
});

/**
 * The item a WordPress post or page became, or null.
 */
function plItem(string $sourceId, int $wordpressId): ?Content
{
    $id = app(ImportMap::class)->temeId($sourceId, SourceRef::of('post', $wordpressId));

    return $id === null ? null : Content::query()->find($id);
}

/**
 * The term a WordPress term became, by its term ID, or null.
 */
function plTerm(string $sourceId, int $termId): ?Term
{
    $id = app(ImportMap::class)->temeId($sourceId, SourceRef::of('term', $termId));

    return $id === null ? null : Term::query()->find($id);
}

/**
 * The codes of the run's messages.
 *
 * @return list<string>
 */
function plCodes(ImportRun $run): array
{
    return ImportMessageRecord::query()->where('run_id', $run->id)->pluck('code')->all();
}

it('adds Polylang\'s languages before anything is written, in its order, each at its slug, with Polylang\'s default', function (): void {
    $keys = array_map(fn ($phase): string => $phase->key(), app(ImportPhases::class)->all());

    $run = $this->site->import($this->source);
    $languages = app(SiteLanguages::class);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(array_search('languages', $keys, true))->toBeLessThan(array_search('users', $keys, true))
        ->and($languages->defaultTag())->toBe('en')
        ->and($languages->others())->toBe(['es', 'pt-BR', 'pt', 'it', 'hi', 'ja'])
        ->and(array_map($languages->prefix(...), $languages->others()))->toBe(['es', 'pt', 'pe', 'it', 'hi', 'ja'])
        ->and(plItem($this->source->id, 21)?->language)->toBeNull()
        ->and(addrGet('/harbour-news/')->getStatusCode())->toBe(200);
});

it('creates every item and term in its language, at the address it had, linked or not', function (): void {
    $this->site->import($this->source);

    $italian = plItem($this->source->id, 24);
    $term = plTerm($this->source->id, 6);

    expect($italian?->language)->toBe('it')
        ->and($italian?->translation_group)->toBeNull()
        ->and(addrGet('/it/solo-in-italiano/')->getStatusCode())->toBe(200)
        ->and(plItem($this->source->id, 23)?->language)->toBe('pt-BR')
        ->and(addrGet('/pt/noticias-do-porto/')->getStatusCode())->toBe(200)
        ->and($term?->language)->toBe('it')
        ->and($term?->slug)->toBe('news-it');
});

it('brings a post filed under a category of another language across without it, saying it was left off', function (): void {
    // Polylang keeps a post's categories in its language, but a bulk edit or an earlier plugin can file it under another's.
    $this->site->filed(21, ['category' => [5, 6]]);

    $run = $this->site->import($this->source);
    $english = plItem($this->source->id, 21);
    $messages = ImportMessageRecord::query()->where('run_id', $run->id)->where('code', 'post.terms-other-language')->get();

    expect($english)->not->toBeNull()
        ->and(json_decode((string) DB::table('content_revisions')->where('id', $english?->working_revision_id)->value('terms'), true))->toBe([plTerm($this->source->id, 5)?->id])
        ->and($messages)->toHaveCount(1)
        ->and((string) $messages->first()?->message)->toContain('left off');
});

it('links each group of translations, joining the maps that lost members, and changes nothing when run again', function (): void {
    $this->site->import($this->source);
    $group = fn (array $ids): array => array_map(fn (int $id): ?string => plItem($this->source->id, $id)?->translation_group, $ids);

    $harbour = $group([21, 22, 23]);
    $ferries = $group([25, 26, 27]);
    $news = [plTerm($this->source->id, 5)?->translation_group, plTerm($this->source->id, 6)?->translation_group];

    expect(array_unique($harbour))->toHaveCount(1)->and($harbour[0])->not->toBeNull()
        ->and(array_unique($ferries))->toHaveCount(1)->and($ferries[0])->not->toBeNull()->not->toBe($harbour[0])
        ->and(array_unique($news))->toHaveCount(1)->and($news[0])->not->toBeNull();

    $run = $this->site->import($this->source);

    expect($group([21, 22, 23]))->toBe($harbour)
        ->and($group([25, 26, 27]))->toBe($ferries)
        ->and(plCodes($run))->not->toContain('translations.skipped');
});

it('shows each language\'s home page at its home', function (): void {
    $this->site->import($this->source);

    expect(addrGet('/')->getContent())->toContain('Home')
        ->and(addrGet('/es/')->getStatusCode())->toBe(200)
        ->and(addrGet('/es/')->getContent())->toContain('Inicio');
});

it('imports a menu per language, each switcher a languages item, and shows each in its place in its language', function (): void {
    $run = $this->site->import($this->source);
    $main = Navigation::query()->where('name', 'Main')->sole();
    $principal = Navigation::query()->where('name', 'Principal')->sole();
    $switcher = fn (Navigation $menu) => $menu->items()->where('kind', 'languages')->sole();
    $slot = array_key_first(app(ActiveTheme::class)->get()?->manifest?->navigations() ?? []);
    $translations = SettingsGroupRecord::query()->find(ThemeSlotSettings::groupKey('good'))?->translations ?? [];

    expect($main->language)->toBeNull()
        ->and($principal->language)->toBe('es')
        ->and($switcher($main)->options)->toBe(['dropdown' => false, 'show_names' => true, 'hide_current' => true])
        ->and($switcher($principal)->options)->toBe(['dropdown' => true, 'show_names' => true, 'hide_current' => false])
        ->and($translations['es'][ThemeSlotSettings::settingKey((string) $slot)] ?? null)->toBe($principal->id)
        ->and(plCodes($run))->toContain('menu.switcher-options');
});

it('copies the site title, the tagline and the title templates in each language, and says what it left', function (): void {
    $run = $this->site->import($this->source);
    $general = SettingsGroupRecord::query()->find('general')?->translations ?? [];
    $seo = SettingsGroupRecord::query()->find(SearchAndSocial::KEY)?->translations ?? [];

    expect($general['es'] ?? [])->toHaveCount(2)
        ->and($general['es']['site_title'] ?? null)->toBe('La Gaceta del Puerto')
        ->and($general['es']['tagline'] ?? null)->toBe('Noticias del muelle')
        ->and($seo['es']['title_home'] ?? null)->toBe('{site} {sep} {tagline}')
        ->and($seo['es']['title_type_post'] ?? null)->toBe('{title} {sep} {date} {sep} {author}')
        ->and($seo['es']['title_taxonomy_category'] ?? null)->toBe('{title} {sep} {site}')
        ->and(plCodes($run))->toContain('language-words.left');
});

it('stops before writing anything when Polylang set each page\'s language from its content', function (): void {
    $this->site->with('languages', PolylangSite::languages(['options' => ['force_lang' => 0]]));
    $settings = fn (): array => SettingsGroupRecord::query()->orderBy('group_key')->get()->map(fn (SettingsGroupRecord $group): array => $group->only(['group_key', 'overrides', 'translations', 'version']))->all();
    $before = $settings();

    $run = $this->site->import($this->source);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and($settings())->toBe($before)
        ->and($run->stop_reason)->toBe('languages-from-content')
        ->and(Content::query()->count())->toBe(0)
        ->and(app(SiteLanguages::class)->others())->toBe([]);
});

it('stops a catch-up rather than bring a new post into the default language once the companion is too old to say the site\'s languages', function (): void {
    $this->site->import($this->source);
    // Later the plugin is swapped for one without the languages route, and nothing is kept from before.
    $this->site->with('analyze', tiReport(['multilingual' => ['wpml' => null, 'polylang' => ['version' => '3.7.4', 'languages' => 7], 'translatepress' => null, 'weglot' => null]]));

    foreach (range(1, 4) as $ignored) {
        $this->site->companion->answer('languages', 404, ['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]]);
    }

    $this->site->with('content', [ContentImports::wpItem(28, [
        'type' => 'post', 'slug' => 'nuovo', 'title' => 'Nuovo', 'author' => 1, 'language' => 'it', 'translations' => [],
        'blocks' => [wpBlock('core/paragraph', '<p>Nuovo.</p>')], 'content' => '', 'permalink' => FakeCompanion::SITE.'/it/nuovo/',
        'modified_gmt' => now()->addDay()->format('Y-m-d H:i:s'),
    ])]);
    Cache::forget(ImportedLanguages::key($this->source->id));
    Cache::forget(ImportAnalysis::key($this->source->id));

    $run = $this->site->import($this->source, ImportRunKind::CatchUp);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and($run->stop_reason)->toBe(SourceRefused::TOO_OLD)
        ->and($run->stop_message)->toContain('The site report says Polylang is on, with 7 languages')
        ->and(plItem($this->source->id, 28))->toBeNull()
        ->and(app(SiteLanguages::class)->defaultTag())->toBe('en');
});

it('says which languages Teme doesn\'t offer, and leaves their posts on the old site', function (): void {
    $answer = PolylangSite::languages();
    $answer['languages'][6]['locale'] = 'tlh';
    $this->site->with('languages', $answer);

    $run = $this->site->import($this->source);

    expect(plCodes($run))->toContain('languages.not-offered')
        ->and(app(SiteLanguages::class)->has('ja'))->toBeFalse()
        ->and(plItem($this->source->id, 27))->toBeNull()
        ->and(plItem($this->source->id, 26)?->language)->toBe('hi');
});

it('translates here only the types and taxonomies Polylang translated', function (): void {
    $this->site->with('languages', PolylangSite::languages(['taxonomies' => ['post_tag']]));

    $this->site->import($this->source);

    expect(app(SiteLanguages::class)->translatesTaxonomy('category'))->toBeFalse()
        ->and(app(SiteLanguages::class)->translates('post'))->toBeTrue()
        ->and(plTerm($this->source->id, 6)?->language)->toBeNull()
        ->and(plTerm($this->source->id, 6)?->slug)->toBe('news-it');
});

it('stops rather than create items in the default language when the old site\'s languages can\'t be read again', function (): void {
    $this->site->companion->answer('languages', 200, PolylangSite::languages());

    foreach (range(1, 5) as $ignored) {
        $this->site->companion->answer('languages', 500, ['code' => 'internal_server_error']);
    }

    // The kept answer is gone by the time the posts and pages are read, as an evicted cache leaves it.
    Event::listen(JobProcessing::class, function (): void {
        if (ImportRun::query()->value('phase') === 'content') {
            Cache::forget(ImportedLanguages::key($this->source->id));
        }
    });

    $run = $this->site->import($this->source);

    expect($run->state)->toBe(ImportRunState::Stopped)
        ->and($run->phase)->toBe('content')
        ->and(Content::query()->count())->toBe(0);
});

it('says when two of the old site\'s languages become one language here, keeping the first one\'s prefix', function (): void {
    $answer = PolylangSite::languages();
    $answer['languages'][] = ['slug' => 'mx', 'locale' => 'es_MX', 'name' => 'Español de México', 'rtl' => false, 'order' => 7];
    $this->site->with('languages', $answer);

    $run = $this->site->import($this->source);
    $same = ImportMessageRecord::query()->where('run_id', $run->id)->where('code', 'languages.same-tag')->sole();

    expect($same->context)->toMatchArray(['prefix' => 'mx', 'first' => 'es'])
        ->and(app(SiteLanguages::class)->prefix('es'))->toBe('es');
});

it('reads the old site\'s translation maps once a run for each kind, not once for each batch of groups', function (): void {
    $this->site->import($this->source);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += str_contains($query->sql, "jsonb_typeof(facts -> 'translations')") ? 1 : 0;
    });

    scElevated(function (): void {
        // A later run of the same source, whose groups nothing has kept yet.
        $context = new ImportContext(strtolower((string) Str::ulid()), $this->source->id, ImportRunKind::Full, JobActor::operator(), null, 0, []);

        foreach ([null, 'content:1', 'content:2'] as $cursor) {
            app(TranslationsStep::class)->run($context, app(CompanionClients::class)->for($this->source), $cursor);
        }
    });

    expect($reads)->toBe(1);
});

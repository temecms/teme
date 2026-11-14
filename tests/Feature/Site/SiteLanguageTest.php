<?php

declare(strict_types=1);

use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\Contents\Operations\Input\ReindexSearchInput;
use App\Domain\Contents\Operations\ReindexSearch;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\SiteLanguage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('is English by default, and English search stems words', function (): void {
    expect(app(SiteLanguage::class)->tag())->toBe('en')
        ->and(app(SiteLanguage::class)->searchConfig())->toBe('english');
});

it('maps a language PostgreSQL has no rules for to simple', function (): void {
    expect(app(SiteLanguage::class)->searchConfig('yo'))->toBe('simple')
        ->and(app(SiteLanguage::class)->searchConfig('pcm'))->toBe('simple');
});

it('offers only configurations the database really has', function (): void {
    $available = DB::table('pg_ts_config')->pluck('cfgname')->all();

    foreach (config('languages') as $tag => $language) {
        expect($available)->toContain($language['search']);
    }
});

it('records which rules each item was indexed with', function (): void {
    $post = searchPublished($this->editor, 'Harbour notes', 'The lifeboats were running all night.');

    expect(DB::table('contents')->where('id', $post->id)->value('search_config'))->toBe('english');
});

it('rebuilds search in the background when the language changes, as the admin who changed it', function (): void {
    Queue::fake();

    siteSetLanguage($this->admin, 'fr');

    Queue::assertPushed(ReindexSearchJob::class, fn (ReindexSearchJob $job): bool => $job->actor->userId === (string) $this->admin->id);
});

it('reindexes every published item with the new language\'s rules', function (): void {
    $post = searchPublished($this->editor, 'Le port', 'Les bateaux partaient chaque nuit.');
    siteSetLanguage($this->admin, 'fr');   // the queue is synchronous in tests, so the job runs now

    expect(DB::table('contents')->where('id', $post->id)->value('search_config'))->toBe('french')
        ->and(app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->admin->id))['remaining'])->toBe(0);
});

it('writes nothing when someone without the settings ability asks for a reindex', function (): void {
    searchPublished($this->editor, 'Harbour notes', 'Boats.');
    DB::table('contents')->update(['search_config' => 'simple']);

    expect(fn () => app(ReindexSearch::class)->execute(new ReindexSearchInput((string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('contents')->whereNotNull('search_tsv')->value('search_config'))->toBe('simple');
});

it('writes the date to show in the site language', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'UTC'));
    siteSetLanguage($this->admin, 'fr');
    $post = pageArticle($this->editor, 'Le port');

    expect(app(ArticleViews::class)->published($post->refresh())->publishedText)->toContain('septembre');
});

it('tells browsers and screen readers the site language', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    siteSetLanguage($this->admin, 'pt-BR');
    pageArticle($this->editor, 'Porto');

    expect(addrGet('/porto/')->assertOk()->getContent())->toContain('<html lang="pt-BR"');
});

it('gives every language its own name, a direction and an Open Graph locale', function (): void {
    foreach (config('languages') as $tag => $language) {
        expect($language['native'] ?? '')->not->toBe('', "[{$tag}] has no native name.")
            ->and($language['direction'] ?? null)->toBeIn(['ltr', 'rtl'])
            ->and($language['og'] ?? '')->toMatch('/^[a-z]{2,3}_[A-Z]{2}$/');
    }
});

it('draws Arabic and Yiddish right to left, and nothing else', function (): void {
    $rtl = array_keys(array_filter(config('languages'), fn (array $language): bool => ($language['direction'] ?? null) === 'rtl'));

    expect($rtl)->toEqualCanonicalizing(['ar', 'yi']);
});

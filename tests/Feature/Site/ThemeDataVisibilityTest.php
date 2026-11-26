<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Languages\PageLanguage;
use App\Domain\Languages\SiteLanguages;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Site\ArticleViews;
use App\Domain\Site\ListingViews;
use App\Domain\Site\SiteAddresses;
use App\Domain\Taxonomy\Models\Term;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->site = LanguageSite::build();
    $this->hide = fn (string $id, string $visibility = 'private') => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($id, $visibility, null, (string) $this->site->admin->id));
});

it('draws nothing from the items component for a type that is not public', function (): void {
    ctCreate(null, ['key' => 'secret', 'singular' => 'Secret', 'plural' => 'Secrets', 'segment' => 'secrets', 'public' => false, 'archive' => false, 'searchable' => false, 'comments' => null, 'taxonomies' => []]);
    ltItem($this->site->editor, 'secret', 'Hidden plans');

    expect(trim(Blade::render('<x-teme::items type="secret" />')))->toBe('');
});

it('leaves a private item, a trashed one and another language\'s out of the items component', function (): void {
    $private = LanguageSite::version($this->site->editor, 'Private note', null, null, [], publish: true);
    ($this->hide)($private);
    $trashed = LanguageSite::version($this->site->editor, 'Trashed note', null, null, [], publish: true);
    pageTrash(Content::query()->findOrFail($trashed), $this->site->admin);

    $default = Blade::render('<x-teme::items type="post" />');

    expect($default)->toContain('Hello')->toContain('Alone')
        ->not->toContain('Private note')->not->toContain('Trashed note')
        ->not->toContain('Bonjour')->not->toContain('Hola');

    app(PageLanguage::class)->use('fr');
    $french = Blade::render('<x-teme::items type="post" />');

    expect($french)->toContain('Bonjour')->not->toContain('Hello')->not->toContain('Hola');
});

it('gives a password-protected item no reading time, and reads no body for the others', function (): void {
    $protected = LanguageSite::version($this->site->editor, 'Locked', null, null, [], publish: true);
    app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($protected, 'protected', 'open sesame', (string) $this->site->admin->id));

    DB::enableQueryLog();
    $items = app(ListingViews::class)->items([$protected, $this->site->alone]);
    $log = implode("\n", array_column(DB::getQueryLog(), 'query'));

    expect($items[0]->readingMinutes)->toBeNull()
        ->and($items[1]->readingMinutes)->toBe(1)
        ->and($log)->not->toMatch('/"?document"?\s*[,)]|content_revisions"?\."?document/');
});

it('counts a category\'s items in its own language, leaving out private ones', function (): void {
    $default = Term::query()->findOrFail($this->site->news);
    $french = Term::query()->findOrFail($this->site->newsFr);

    expect(app(ListingViews::class)->forTerm($default->refresh(), 1)->total)->toBe(1)
        ->and(app(ListingViews::class)->forTerm($french->refresh(), 1)->total)->toBe(1);

    $second = LanguageSite::version($this->site->editor, 'Second hello', null, null, [$this->site->news], publish: true);
    $secret = LanguageSite::version($this->site->editor, 'Secret hello', null, null, [$this->site->news], publish: true);
    ($this->hide)($secret);

    expect(app(ListingViews::class)->forTerm($default->refresh(), 1)->total)->toBe(2)
        ->and($second)->not->toBe($secret)
        ->and(app(ListingViews::class)->forTerm($french->refresh(), 1)->total)->toBe(1);
});

it('gives no total for a listing deeper than the last page served, and the exact one up to it', function (): void {
    $columns = array_values(array_diff(Schema::getColumnListing('contents'), ['id', 'slug', 'guid']));
    $names = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $columns));
    $row = DB::table('contents')->where('id', $this->site->alone)->first();
    DB::statement(
        "INSERT INTO contents (id, slug, guid, {$names}) SELECT lpad(to_hex(g), 26, '0'), 'copy-' || g, md5(g::text), {$names} FROM contents, generate_series(1, 1000) g WHERE contents.id = ?",
        [$row->id],
    );
    cmSettings(['posts_per_page' => 1], 'reading');
    $listings = app(ListingViews::class);

    expect($listings->forType('post', 'posts/', 1)->total)->toBeNull();

    cmSettings(['posts_per_page' => 2], 'reading');

    expect($listings->forType('post', 'posts/', 1)->total)->toBe(1000 + 1 + 1);
});

it('gives a page only the siblings of its own language, none from a hidden one', function (): void {
    $contact = LanguageSite::version($this->site->editor, 'Contact', null, null, [], publish: true, type: 'page');
    $contactFr = LanguageSite::version($this->site->editor, 'Contact FR', 'fr', null, [], publish: true, type: 'page');
    LanguageSite::version($this->site->editor, 'Contacto', 'es', null, [], publish: true, type: 'page');
    $titles = fn (string $id): array => array_map(fn ($link): string => $link->title, app(ArticleViews::class)->published(Content::query()->findOrFail($id))->siblings);

    expect($titles($contact))->toBe(['About', 'Contact'])
        ->and($titles($contactFr))->toBe(['Contact FR']);
});

it('gives the posts address with the language\'s prefix, and its translated posts page', function (): void {
    $group = TranslationGroups::newId();
    $welcome = LanguageSite::version($this->site->editor, 'Welcome', null, null, [], publish: true, type: 'page');
    $news = LanguageSite::version($this->site->editor, 'News page', null, $group, [], publish: true, type: 'page');
    LanguageSite::version($this->site->editor, 'Actualités page', 'fr', $group, [], publish: true, type: 'page');

    expect(app(SiteAddresses::class)->posts())->toBe(url('/').'/');
    app(PageLanguage::class)->use('fr');
    $french = new SiteAddresses(app(PageLanguage::class), app(SiteLanguages::class));

    expect($french->posts())->toBe(rtrim(url('/'), '/').'/fr/');

    addrChange($this->site->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome, 'postsPageId' => $news]);
    $translated = new SiteAddresses(app(PageLanguage::class), app(SiteLanguages::class));

    expect($translated->posts())->toBe(rtrim(url('/'), '/').'/fr/actualites-page/');
    app(PageLanguage::class)->use('en-GB');
    $default = new SiteAddresses(app(PageLanguage::class), app(SiteLanguages::class));

    expect($default->posts())->toBe(rtrim(url('/'), '/').'/news-page/');
});

it('gives an empty posts address while a static front page is shown and no posts page is named', function (): void {
    $welcome = LanguageSite::version($this->site->editor, 'Welcome', null, null, [], publish: true, type: 'page');
    addrChange($this->site->admin, ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $welcome, 'postsPageId' => '']);
    $addresses = app(SiteAddresses::class);

    expect($addresses->posts())->toBe('')
        ->and($addresses->archives())->not->toHaveKey('post');
});

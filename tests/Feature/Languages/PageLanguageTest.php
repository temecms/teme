<?php

declare(strict_types=1);

use App\Domain\Languages\PageLanguage;
use App\Domain\Site\ListingViews;
use App\Domain\Site\SiteLanguage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\Languages;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    siteSetLanguage(User::factory()->create()->assignRole('administrator'), 'en-GB');
    Languages::set(['fr', 'pt-BR']);
});

it('is the default language until something says otherwise, and stores nothing', function (): void {
    $page = app(PageLanguage::class);

    expect($page->tag())->toBe('en-GB')
        ->and($page->stored())->toBeNull()
        ->and($page->isSet())->toBeFalse();
});

it('sets the application\'s locale to the page\'s language', function (): void {
    app(PageLanguage::class)->use('pt-BR');

    expect(app()->getLocale())->toBe('pt_BR')
        ->and(app(PageLanguage::class)->stored())->toBe('pt-BR')
        ->and(app(PageLanguage::class)->tag())->toBe('pt-BR')
        ->and(app(PageLanguage::class)->isSet())->toBeTrue();
});

it('stores nothing for the default language even when set to it', function (): void {
    app(PageLanguage::class)->use('en-GB');

    expect(app(PageLanguage::class)->stored())->toBeNull()
        ->and(app(PageLanguage::class)->tag())->toBe('en-GB');
});

it('refuses a language the site does not have', function (): void {
    $before = app()->getLocale();

    expect(fn () => app(PageLanguage::class)->use('it'))->toThrow(InvalidArgumentException::class)
        ->and(app()->getLocale())->toBe($before);
});

it('starts every request unset', function (): void {
    app(PageLanguage::class)->use('fr');

    // What a second request in the same application sees (B-227).
    app()->forgetScopedInstances();

    expect(app(PageLanguage::class)->isSet())->toBeFalse()
        ->and(app(PageLanguage::class)->tag())->toBe('en-GB');
});

it('answers an item\'s own language, and the default for one that names none', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $english = lifecycleDraft($editor, 'Hello');
    $french = lifecycleDraft($editor, 'Bonjour');
    DB::table('contents')->where('id', $french->id)->update(['language' => 'fr']);
    $french->refresh();
    $language = app(SiteLanguage::class);

    expect($language->forContent($english))->toBe('en-GB')
        ->and($language->forContent($english->id))->toBe('en-GB')
        ->and($language->forContent($french))->toBe('fr')
        ->and($language->forContent($french->id))->toBe('fr')
        ->and($language->of(null))->toBe('en-GB')
        ->and($language->of('fr'))->toBe('fr');
});

it('reads an item\'s language from its row when the model was loaded without it', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $french = lifecycleDraft($editor, 'Bonjour');
    DB::table('contents')->where('id', $french->id)->update(['language' => 'fr']);
    $narrow = $french->newQuery()->select(['id', 'type'])->findOrFail($french->id);

    expect(app(SiteLanguage::class)->forContent($narrow))->toBe('fr');
});

it('draws a listing\'s dates with no statement per item', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $news = taxonomyTerm('category', 'News');

    $count = function () use ($news): int {
        app()->forgetScopedInstances();

        return count(dashQueries(fn () => app(ListingViews::class)->forTerm($news->refresh(), 1)));
    };

    // Both on one page of the listing, so only the items' number differs.
    foreach (range(1, 2) as $number) {
        pageArticle($editor, "Story {$number}", categories: [$news]);
    }

    // Once to read the settings the page asks for, which are then remembered.
    $count();
    $two = $count();

    foreach (range(3, 8) as $number) {
        pageArticle($editor, "Story {$number}", categories: [$news]);
    }

    expect($count())->toBe($two);
});

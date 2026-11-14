<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Models\Content;
use App\Domain\Languages\PageLanguage;
use App\Domain\Site\Breadcrumbs;
use App\Domain\Site\ListingViews;
use App\Domain\Site\RelatedPosts;
use App\Domain\Site\ViewModels\Crumb;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

it('lists only the default language\'s items on the default home page, archives and author pages', function (): void {
    app(PathProjection::class)->syncUser($this->site->editor);
    // The French post filed under the default category too, as a shared taxonomy's term would be.
    DB::table('content_terms')->insert(['content_id' => $this->site->postFr, 'term_id' => $this->site->news, 'scope' => 'published', 'position' => 1]);
    $month = now()->format('Y/m');

    foreach (['/', '/category/news/', "/{$month}/", '/'.addrCurrent('user', (string) $this->site->editor->id).'/'] as $address) {
        addrGet($address)->assertOk()
            ->assertHeader('Content-Language', 'en-GB')
            ->assertSee('Hello')
            ->assertDontSee('Bonjour')
            ->assertDontSee('Hola');
    }
});

it('relates a post only to posts in its own language', function (): void {
    DB::table('content_terms')->insert(['content_id' => $this->site->postFr, 'term_id' => $this->site->news, 'scope' => 'published', 'position' => 1]);
    $other = LanguageSite::version($this->site->editor, 'Howdy', null, null, [$this->site->news], publish: true);

    $related = array_map(fn (array $entry): string => $entry['item']->title, app(RelatedPosts::class)->for(Content::query()->findOrFail($this->site->post)));

    expect($related)->toBe(['Howdy']);
});

it('leads a breadcrumb\'s Home to the page language\'s home', function (): void {
    app(PageLanguage::class)->use('fr');
    $trail = app(Breadcrumbs::class);

    expect($trail->home()->url)->toBe('/fr/')
        ->and(array_map(fn (Crumb $crumb): string => $crumb->url, $trail->toItem('Équipe', '/fr/equipe/', null, [new Crumb('À propos', '/fr/')])))->toBe(['/fr/', '/fr/equipe/']);

    app(PageLanguage::class)->use('en-GB');

    expect($trail->home()->url)->toBe('/');
});

it('can answer a French list from the other-language index', function (): void {
    // Many default-language rows, with French ones and pages between.
    planFillerPosts($this->site->editor, 3000);
    DB::statement("UPDATE contents SET language = 'fr' WHERE id IN (SELECT id FROM contents WHERE type = 'post' AND translation_group IS NULL ORDER BY id LIMIT 300)");
    DB::statement("UPDATE contents SET type = 'page' WHERE id IN (SELECT id FROM contents WHERE type = 'post' AND language IS NULL ORDER BY id DESC LIMIT 300)");
    DB::statement('ANALYZE contents');

    $plan = planOf(fn () => app(ListingViews::class)->forDate((int) now()->format('Y'), null, null, 1, 'fr'), '"contents"."language" = ?');

    expect(planReadsIndex($plan, 'contents_other_language_published', 'contents'))->toBeTrue($plan);
});

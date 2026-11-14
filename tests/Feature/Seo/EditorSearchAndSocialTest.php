<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Seo\SearchAndSocial;
use App\Domain\Seo\SocialCards;
use App\Domain\Seo\TitlesAndListing;
use App\Domain\Seo\WholeSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create(['name' => 'Jane Doe'])->assignRole('editor');
    cmSettings(['site_title' => 'The Ledger', 'tagline' => 'News from the harbour'], 'general');
    $this->open = fn (string $id) => $this->actingAs($this->editor)->get("/admin/content/{$id}/edit")->assertOk();
});

it('gives the editor the title row an item\'s head uses, and the tokens the screen can\'t know (I-290)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    cmSettings(['title_separator' => '|', 'title_type_post' => '{title} {sep} {type} {sep} {site}'], SearchAndSocial::KEY);
    $post = lifecycleDraft($this->editor, 'Harbour news');

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $page
        ->where('seo.kind', 'item')
        ->where('seo.template', '{title} {sep} {type} {sep} {site}')
        ->where('seo.separator', '|')
        ->where('seo.tokens.site', 'The Ledger')
        ->where('seo.tokens.tagline', 'News from the harbour')
        ->where('seo.tokens.type', 'Post')
        ->where('seo.tokens.author', 'Jane Doe')
        // Today's date as the site's language writes it; Task 2 holds it to a published page's.
        ->where('seo.tokens.date', fn (string $date): bool => str_contains($date, 'October') && str_contains($date, '2026'))
        ->where('seo.tokens.year', '2026')
        ->missing('seo.tokens.title'));
});

it('gives the home page\'s row to the item the site\'s address shows, and the posts page only its site tokens (I-903)', function (string $which): void {
    $home = addrPublished($this->editor, 'Welcome', 'page');
    $posts = addrPublished($this->editor, 'News', 'page');
    addrChange(User::factory()->create()->assignRole('administrator'), ['frontShows' => AddressSettings::FRONT_PAGE, 'frontPageId' => $home->id, 'postsPageId' => $posts->id]);

    ($this->open)($which === 'the home page' ? $home->id : $posts->id)->assertInertia(fn (Assert $page) => $which === 'the home page'
        ? $page->where('seo.kind', 'home')->where('seo.template', TitlesAndListing::HOME)->where('seo.tokens.title', 'The Ledger')->where('seo.tokens.site', 'The Ledger')->missing('seo.tokens.author')
        : $page->where('seo.kind', 'latest')->where('seo.template', TitlesAndListing::ITEM)->where('seo.tokens.site', 'The Ledger')->missing('seo.tokens.title')->missing('seo.tokens.author'));
})->with(['the home page', 'the posts page']);

it('says whether the whole site, or the item\'s type, is kept out of search, and which host an outside canonical is compared with (I-291, I-853)', function (): void {
    $post = lifecycleDraft($this->editor, 'Harbour news');

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $page->where('seo.discouraged', false)->where('seo.typeHidden', false)->where('seo.siteHost', 'localhost'));

    cmSettings([WholeSite::DISCOURAGE => true, 'hide_type_post' => true], SearchAndSocial::KEY);

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $page->where('seo.discouraged', true)->where('seo.typeHidden', true));
});

it('gives the site\'s default social image as cards draw it, and none while it is unset (I-293)', function (): void {
    $image = pageImage($this->editor, 1600, 900);
    $post = lifecycleDraft($this->editor, 'Harbour news');

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $page->where('seo.defaultImage', null));

    cmSettings([SocialCards::DEFAULT_IMAGE => $image->id], SearchAndSocial::KEY);

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $page->where('seo.defaultImage.id', $image->id)->has('seo.defaultImage.media.variants'));
});

it('names the canonical\'s chosen item and its state, and nothing for the item\'s own address or an outside one (I-292)', function (string $choice, ?string $state): void {
    $ferry = addrPublished($this->editor, 'Ferry fares');
    $market = lifecycleDraft($this->editor, 'Night market');
    $gone = addrPublished($this->editor, 'Old timetable');
    $post = refsDraft($this->editor, match ($choice) {
        'a published item' => [BuiltInFields::CANONICAL => ['item' => $ferry->id]],
        'a draft' => [BuiltInFields::CANONICAL => ['item' => $market->id]],
        'an item since trashed' => [BuiltInFields::CANONICAL => ['item' => $gone->id]],
        'an outside address' => [BuiltInFields::CANONICAL => ['address' => 'https://example.org/original-story']],
        default => [],
    });
    pageTrash($gone, $this->editor);

    ($this->open)($post->id)->assertInertia(fn (Assert $page) => $state === null
        ? $page->where('seo.canonicalItem', null)
        : $page->where('seo.canonicalItem.state', $state)->where('seo.canonicalItem.title', match ($state) {
            'published' => 'Ferry fares',
            'unpublished' => 'Night market',
            default => 'Old timetable',
        }));
})->with([
    'a published item' => ['a published item', 'published'],
    'a draft' => ['a draft', 'unpublished'],
    'an item since trashed' => ['an item since trashed', 'trashed'],
    'an outside address' => ['an outside address', null],
    'its own address' => ['its own address', null],
]);

it('opens with the social image in the editor\'s media beside the featured image, in the same lookup', function (): void {
    $featured = pageImage($this->editor, 1200, 800);
    $social = pageImage($this->editor, 1200, 630);
    $post = refsDraft($this->editor, [
        ...refsFeatured($featured),
        BuiltInFields::SOCIAL_IMAGE => ['id' => $social->id, 'alt' => null, 'decorative' => false],
    ]);

    expect(array_keys(EditorSession::for($post->id, (string) $this->editor->id)->media))->toEqualCanonicalizing([$featured->id, $social->id]);
});

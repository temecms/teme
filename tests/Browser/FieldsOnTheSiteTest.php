<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Fields\StoredFieldRows;

uses(RefreshDatabase::class);

const FOS_RELEASE = '01K9F1E1D0000000000000RD01';
const FOS_RUNTIME = '01K9F1E1D0000000000000RT02';
const FOS_RATING = '01K9F1E1D0000000000000RA03';
const FOS_TRAILER = '01K9F1E1D0000000000000TR05';
const FOS_POSTER = '01K9F1E1D0000000000000PS06';
const FOS_TAGLINE = '01K9F1E1D0000000000000TG07';

beforeEach(function (): void {
    // TestCase turns Vite off for PHP tests; a real browser needs core's built styles.
    $this->withVite();
    $this->served = [];
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    lscrTimezone('Africa/Lagos');
    siteSetLanguage($this->admin, 'en-GB');
    StoredFieldRows::movieType();
    StoredFieldRows::store(StoredFieldRows::entry(FOS_TAGLINE, 'tagline', ['label' => 'Tagline']), 1);
    StoredFieldRows::store(StoredFieldRows::entry(FOS_RELEASE, 'release_date', ['label' => 'Release date', 'fieldType' => 'datetime', 'settings' => ['dateOnly' => true]]), 2);
    StoredFieldRows::store(StoredFieldRows::entry(FOS_RUNTIME, 'runtime', ['label' => 'Runtime', 'fieldType' => 'number']), 3);
    StoredFieldRows::store(StoredFieldRows::entry(FOS_RATING, 'rating', ['label' => 'Rating', 'fieldType' => 'select', 'settings' => ['choices' => [['value' => 'pg', 'label' => 'PG-13']]]]), 4);
    StoredFieldRows::store(StoredFieldRows::entry(FOS_TRAILER, 'trailer_url', ['label' => 'Trailer URL', 'fieldType' => 'url']), 5);
    StoredFieldRows::store(StoredFieldRows::entry(FOS_POSTER, 'poster', ['label' => 'Poster', 'fieldType' => 'media']), 6);

    // The theme's header and footer menus bound, so axe sees no empty navigation landmark.
    $about = addrPublished($this->admin, 'About', 'page');

    foreach (['primary' => 'Primary', 'footer' => 'Footer'] as $slot => $name) {
        $menu = navCreate($this->admin, $name);
        navSave($this->admin, $menu, [['kind' => 'content', 'target_id' => $about->id]]);
        app(BindNavigationSlot::class)->execute(new BindNavigationSlotInput('default', $slot, $menu->id, (string) $this->admin->id));
    }

});

afterEach(function (): void {
    foreach ($this->served as $id) {
        Storage::disk('public')->deleteDirectory($id);
    }
});

/**
 * Publishes the movie with a poster, and returns its address. A poster that
 * is `served` has its files copied to where the test server finds them (the
 * fake media disk is not served), and removed afterwards; one that isn't
 * draws as a missing image.
 */
function fosMovie(bool $served): string
{
    $poster = mediaUpload(test()->admin, mediaJpeg(1200, 800), 'Poster.jpg');

    if ($served) {
        // The public disk is served from public/storage, the link `storage:link` makes.
        if (! file_exists(public_path('storage'))) {
            File::link(storage_path('app/public'), public_path('storage'));
        }

        foreach (Storage::disk('media')->allFiles($poster->id) as $path) {
            Storage::disk('public')->put($path, (string) Storage::disk('media')->get($path));
        }

        test()->served = [...test()->served, $poster->id];
    }

    $movie = lifecycleDraft(test()->admin, 'Dune: Part Two', 'movie')->refresh();
    $fields = [
        FOS_TAGLINE => 'Long live the fighters.', FOS_RELEASE => '2024-03-01', FOS_RUNTIME => 166, FOS_RATING => 'pg',
        FOS_TRAILER => 'https://example.com/trailer', FOS_POSTER => ['id' => $poster->id, 'alt' => 'The poster', 'decorative' => false],
    ];
    app(SaveDraft::class)->execute(new SaveDraftInput($movie->id, 'Dune: Part Two', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Paul Atreides unites with the Fremen.']]]]], $fields, $movie->working_revision_id, (string) test()->admin->id));
    listsPublish($movie, test()->admin);

    return (string) Addresses::relativeUrl(Content::query()->findOrFail($movie->id));
}

it('passes axe on a movie’s page with its fields, light and dark', function (string $scheme): void {
    designPublish(['theme.default' => ['color_scheme' => $scheme]]);

    visit(fosMovie(true))->assertNoAccessibilityIssues(3)->assertSee('Release date')->assertSee('1 March 2024');
})->with(['light', 'dark']);

it('keeps the page from scrolling sideways and the list in one column, with a real poster and a missing one', function (bool $served, int $width): void {
    $page = visit(fosMovie($served))->resize($width, 812);

    // This checkout's theme styles are loaded: a stale or missing public/themes/default link draws the page unstyled, and a 1200 px poster then widens it.
    $page->assertSee('Runtime')
        ->assertScript("getComputedStyle(document.querySelector('.field-list')).borderTopWidth === '1px'")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript("(() => { const row = document.querySelector('.field-list > div'); return getComputedStyle(row).gridTemplateColumns.split(' ').length === 1; })()")
        ->assertScript("(() => { const img = document.querySelector('.field-list img'); return img !== null && img.getBoundingClientRect().right <= window.innerWidth; })()");

    if ($served) {
        // The poster is really there: loaded, in proportion, and no taller than it draws.
        $page->assertScript("(() => { const img = document.querySelector('.field-list img'); return img.complete && img.naturalWidth > 0 && Math.abs(img.getBoundingClientRect().height / img.getBoundingClientRect().width - 800 / 1200) < 0.02; })()");
    }
})->with([[true, 375], [true, 320], [false, 375], [false, 320]]);

it('lines the poster up with the other values at 1280 px', function (): void {
    $page = visit(fosMovie(true))->resize(1280, 900);

    $page->assertScript("getComputedStyle(document.querySelector('.field-list')).borderTopWidth === '1px'")
        ->assertScript("(() => { const lefts = [...document.querySelectorAll('.field-list dd')].map((dd) => Math.round(dd.getBoundingClientRect().left)); return new Set(lefts).size === 1; })()")
        ->assertScript("(() => { const list = document.querySelector('.field-list'); const footer = document.querySelector('.site-footer'); return footer.getBoundingClientRect().top - list.getBoundingClientRect().bottom < 600; })()");
});

it('saves the pictures the owner looks at', function (): void {
    $url = fosMovie(true);
    $page = visit($url)->resize(1280, 900);
    $page->assertSee('Release date');
    $page->screenshot(true, 'fields-on-site-after');

    StoredFieldRows::change(FOS_TAGLINE, ['show' => 'before']);
    $page = visit($url)->resize(1280, 900);
    $page->assertSee('Long live the fighters.');
    $page->screenshot(true, 'fields-on-site-one-before');
});

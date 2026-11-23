<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\ReaderPasswords;
use App\Domain\Contents\Visibility;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\ImportStatus;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Phases\Content\ContentPhase;
use App\Domain\Import\Phases\Content\ContentWaitingStep;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Taxonomy\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->companion = importFake();
    $this->source = importSource();
    ContentImports::person($this->source->id, $this->author, 3);
});

/**
 * The item WordPress's post or page $wordpressId became, in the trash or not.
 */
function cphItem(string $sourceId, int $wordpressId): Content
{
    return Content::withTrashed()->findOrFail(ContentImports::row($sourceId, $wordpressId)?->teme_id);
}

it('imports each post and page as WordPress had it, its map row as comments and menus read it (I-332)', function (): void {
    $run = ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12), ContentImports::wpItem(13, ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'parent' => 0, 'menu_order' => 3, 'permalink' => 'https://old.example/about/'])]);
    $row = ContentImports::row($this->source->id, 12);
    $post = cphItem($this->source->id, 12);
    $page = cphItem($this->source->id, 13);

    expect($run->refresh()->state)->toBe(ImportRunState::Finished)
        ->and($row?->teme_type)->toBe('content')
        ->and($row?->status)->toBe(ImportStatus::Imported)
        ->and($row?->outcome)->toBe(ImportOutcome::Created)
        // Its own keys, never the whole list: later parts may keep more beside them.
        ->and($row?->facts ?? [])->toHaveKeys(['path', 'parent', 'menu_order', 'written', 'references', 'pending_links', 'pending_canonical', 'missing', 'visibility', 'language', 'translations'])
        ->and($row?->facts['path'])->toBe('harbour-news')
        ->and(ContentImports::row($this->source->id, 13)?->facts['menu_order'])->toBe(3)
        ->and($post->type)->toBe('post')
        ->and($page->type)->toBe('page')
        ->and($post->title)->toBe('Harbour news')
        ->and((int) $post->author_id)->toBe($this->author->id)
        ->and(ContentRevision::query()->findOrFail($post->working_revision_id)->origin)->toBe('import')
        ->and(addrCurrent('content', $post->id))->toBe('harbour-news')
        ->and(addrCurrent('content', $page->id))->toBe('about');
});

it('credits an item to its WordPress author, else to the person chosen to own such items, else the first administrator, saying so', function (): void {
    $owner = User::factory()->create()->assignRole('editor');

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12),
        ContentImports::wpItem(13, ['author' => 77, 'slug' => 'orphan', 'title' => 'Orphan', 'permalink' => 'https://old.example/orphan/']),
    ], choices: ['owner' => (string) $owner->id]);
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(14, ['author' => 0, 'slug' => 'nobody', 'title' => 'Nobody', 'permalink' => 'https://old.example/nobody/'])]);

    expect((int) cphItem($this->source->id, 12)->author_id)->toBe($this->author->id)
        ->and((int) cphItem($this->source->id, 13)->author_id)->toBe($owner->id)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->toContain('post.author-missing')
        ->and((int) cphItem($this->source->id, 14)->author_id)->toBe($this->admin->id);
});

it('files it under its categories and tags, the SEO plugin’s primary category or else the lowest term ID (I-869)', function (): void {
    $boats = typesTerm($this->admin, 'category', 'Boats');
    $news = typesTerm($this->admin, 'category', 'News');
    $tides = typesTerm($this->admin, 'tag', 'Tides');
    ContentImports::term($this->source->id, $boats, 5, 7, 'category');
    ContentImports::term($this->source->id, $news, 6, 4, 'category');
    ContentImports::term($this->source->id, $tides, 9, 8, 'post_tag');

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['terms' => ['category' => [5, 6], 'post_tag' => [9, 404], 'product_cat' => [70]], 'seo' => ['primary_terms' => ['category' => 7]]]),
        ContentImports::wpItem(13, ['slug' => 'second', 'title' => 'Second', 'permalink' => 'https://old.example/second/', 'terms' => ['category' => [5, 6]]]),
    ]);
    $first = cphItem($this->source->id, 12);
    $second = cphItem($this->source->id, 13);

    expect(ContentRevision::query()->findOrFail($first->working_revision_id)->terms)->toBe([$boats->id, $news->id, $tides->id])
        ->and($first->fields[BuiltInFields::PRIMARY_CATEGORY])->toBe($boats->id)
        ->and($second->fields[BuiltInFields::PRIMARY_CATEGORY])->toBe($news->id)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toContain('post.terms-missing')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toContain('post.terms-left');
});

it('brings a post holding a term of a skipped taxonomy across without it, saying so, never failing for it', function (): void {
    $boats = typesTerm($this->admin, 'category', 'Boats');
    ContentImports::term($this->source->id, $boats, 5, 7, 'category');
    // The terms phase skipped term 30 of ef_usergroup, a taxonomy Teme doesn't have: its row has no Teme term.
    ImportRecords::entry($this->source->id, ImportRecords::run($this->source->id), 'term', '30', 'skipped', ['teme_type' => null, 'teme_id' => null]);

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['terms' => ['category' => [5], 'ef_usergroup' => [30], 'post_status' => [31]]]),
    ]);
    $row = ContentImports::row($this->source->id, 12);

    expect($row?->status)->toBe(ImportStatus::Imported)
        ->and(ContentRevision::query()->findOrFail(cphItem($this->source->id, 12)->working_revision_id)->terms)->toBe([$boats->id])
        ->and(ContentImports::codes($row))->toContain('post.terms-left');
});

it('leaves off a term its type or language can’t use, counting it among those that didn’t come across, never failing for it', function (): void {
    $boats = typesTerm($this->admin, 'category', 'Boats');
    $genre = Term::query()->create(['taxonomy' => 'ef_genre', 'name' => 'Drama', 'slug' => 'drama']);
    ContentImports::term($this->source->id, $boats, 5, 7, 'category');
    // A map row whose Teme term is one posts can't be filed under.
    ContentImports::term($this->source->id, $genre, 6, 8, 'category');

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['terms' => ['category' => [5, 6]]]),
    ]);
    $row = ContentImports::row($this->source->id, 12);

    expect($row?->status)->toBe(ImportStatus::Imported)
        ->and(ContentRevision::query()->findOrFail(cphItem($this->source->id, 12)->working_revision_id)->terms)->toBe([$boats->id])
        ->and(ContentImports::codes($row))->toContain('post.terms-missing');
});

it('sets its pin, discussion, excerpt and featured image, and leaves every page on the theme’s default template, whatever an earlier release’s choices said (I-156)', function (): void {
    $photo = mediaUpload($this->admin, mediaJpeg());
    ContentImports::attachment($this->source->id, $photo, 41, 12);
    // The fixture theme offers pages one template, full-width, as ImportPlanningTest uses it.
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $template = 'full-width';

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['sticky' => true, 'comment_status' => 'closed', 'excerpt' => '<p>The ferries &amp; the tide.</p>', 'featured_media' => 41]),
        ContentImports::wpItem(13, ['type' => 'page', 'slug' => 'about', 'title' => 'About', 'permalink' => 'https://old.example/about/', 'template' => 'template-full-width.php']),
        ContentImports::wpItem(14, ['type' => 'page', 'slug' => 'contact', 'title' => 'Contact', 'permalink' => 'https://old.example/contact/', 'template' => 'template-unmapped.php']),
    ], choices: ['templates' => ['template-full-width.php' => $template]]);
    $post = cphItem($this->source->id, 12);

    expect($post->fields[BuiltInFields::STICKY])->toBeTrue()
        ->and($post->comment_status->value)->toBe('closed')
        ->and($post->fields[BuiltInFields::EXCERPT])->toBe('The ferries & the tide.')
        ->and($post->fields[BuiltInFields::FEATURED_IMAGE])->toBe(['id' => $photo->id, 'alt' => null, 'decorative' => false])
        ->and(cphItem($this->source->id, 13)->fields[BuiltInFields::TEMPLATE] ?? '')->toBe('')
        ->and(cphItem($this->source->id, 14)->fields[BuiltInFields::TEMPLATE] ?? '')->toBe('')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->not->toContain('post.template-default')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 14)))->not->toContain('post.template-default');
});

it('keeps the SEO plugin’s fields, and the canonical by ID or for the second pass, never the old address (I-330)', function (): void {
    $ferries = pageArticle($this->author, 'Ferries');

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['seo' => ['title' => 'Harbour news &amp; tides', 'description' => 'What came in.', 'canonical' => 'https://old.example/ferries/', 'noindex' => true, 'had_variables' => ['title']]]),
        ContentImports::wpItem(13, ['slug' => 'second', 'title' => 'Second', 'permalink' => 'https://old.example/second/', 'seo' => ['canonical' => 'https://old.example/later-story/']]),
    ]);
    $first = cphItem($this->source->id, 12);

    expect($first->fields[BuiltInFields::SEO_TITLE])->toBe('Harbour news & tides')
        ->and($first->fields[BuiltInFields::META_DESCRIPTION])->toBe('What came in.')
        ->and($first->fields[BuiltInFields::NOINDEX])->toBeTrue()
        ->and($first->fields[BuiltInFields::CANONICAL])->toBe(['item' => $ferries->id])
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toContain('seo.variables')
        ->and(cphItem($this->source->id, 13)->fields[BuiltInFields::CANONICAL] ?? null)->toBeNull()
        ->and(ContentImports::row($this->source->id, 13)?->facts['pending_canonical'])->toBe('/later-story/');
});

it('brings private items as private and protected ones with their password, kept sealed and nowhere else, a refused one included (I-1700)', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = [$event->message, $event->context];
    });
    $huge = array_map(fn (int $n): array => wpBlock('core/paragraph', "<p>Line {$n}</p>"), range(1, 11000));

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['status' => 'private', 'slug' => 'staff-only', 'title' => 'Staff only', 'permalink' => 'https://old.example/staff-only/']),
        ContentImports::wpItem(13, ['password' => 'harbour-secret-2024', 'slug' => 'members', 'title' => 'Members', 'permalink' => 'https://old.example/members/']),
        // Refused whole (too large for one document): its failure must not carry the password either.
        ContentImports::wpItem(14, ['password' => 'harbour-secret-2024', 'slug' => 'members-archive', 'title' => 'Members’ archive', 'permalink' => 'https://old.example/members-archive/', 'blocks' => $huge]),
    ]);
    $everything = json_encode([
        DB::table('contents')->get(), DB::table('content_revisions')->get(), DB::table('import_map')->get(), DB::table('import_messages')->get(),
        DB::table('import_runs')->get(), DB::table('jobs')->get(), DB::table('failed_jobs')->get(), $logged,
    ]);

    expect(cphItem($this->source->id, 12)->status)->toBe('published')
        ->and(cphItem($this->source->id, 12)->visibility)->toBe(Visibility::Private)
        ->and(addrCurrent('content', cphItem($this->source->id, 12)->id))->toBeNull()
        ->and(cphItem($this->source->id, 13)->status)->toBe('published')
        ->and(cphItem($this->source->id, 13)->visibility)->toBe(Visibility::Protected)
        ->and(ReaderPasswords::open(cphItem($this->source->id, 13)))->toBe('harbour-secret-2024')
        ->and(addrCurrent('content', cphItem($this->source->id, 13)->id))->toBe('members')
        ->and(ContentImports::row($this->source->id, 12)?->facts['visibility'])->toBe('private')
        ->and(ContentImports::row($this->source->id, 13)?->facts['visibility'])->toBe('protected')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->not->toContain('post.private-as-draft')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->not->toContain('post.protected-as-draft')
        ->and(ContentImports::row($this->source->id, 14)?->status)->toBe(ImportStatus::Failed)
        ->and(addrGet('/staff-only/')->getStatusCode())->toBe(404)
        ->and($everything)->not->toContain('harbour-secret-2024');
});

it('keeps a private item private and drops its password, saying so (I-1700)', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(15, ['status' => 'private', 'password' => 'harbour', 'slug' => 'board', 'title' => 'Board minutes', 'permalink' => 'https://old.example/board/'])]);

    expect(cphItem($this->source->id, 15)->visibility)->toBe(Visibility::Private)
        ->and(DB::table('contents')->where('id', cphItem($this->source->id, 15)->id)->value('reader_password'))->toBeNull()
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 15)))->toContain('post.password-dropped');
});

it('writes nothing when it brings again a protected item WordPress hasn’t changed (I-1700)', function (): void {
    $post = ContentImports::wpItem(13, ['password' => 'harbour-secret-2024', 'slug' => 'members', 'title' => 'Members', 'permalink' => 'https://old.example/members/']);
    ContentImports::run($this->companion, $this->source, [$post]);
    $before = (array) DB::table('contents')->where('id', cphItem($this->source->id, 13)->id)->first(['reader_password', 'updated_at', 'working_revision_id']);
    DB::table('import_map')->where('id', ContentImports::row($this->source->id, 13)?->id)->update(['status' => ImportStatus::NeedsUpdate->value]);
    $this->travel(5)->seconds();

    ContentImports::run($this->companion, $this->source, [$post]);

    expect((array) DB::table('contents')->where('id', cphItem($this->source->id, 13)->id)->first(['reader_password', 'updated_at', 'working_revision_id']))->toBe($before)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->not->toContain('post.changed-here');
});

it('keeps a public item’s fingerprint as it always was: nine parts, no visibility (I-1700)', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);
    $item = cphItem($this->source->id, 12);
    $at = fn (mixed $moment): ?string => $moment === null ? null : CarbonImmutable::parse((string) $moment)->utc()->format('Y-m-d\TH:i:s\Z');
    $nine = [(string) $item->slug, 'published', (string) $item->author_id, $item->comment_status->value, $at($item->published_at), null, null, (string) $item->working_revision_id, (string) $item->published_revision_id];

    expect(ImportedContents::fingerprint($item))->toBe(sha1(json_encode($nine, JSON_THROW_ON_ERROR)))
        ->and(ContentImports::row($this->source->id, 12)?->facts['written'])->toBe(sha1(json_encode($nine, JSON_THROW_ON_ERROR)));
});

it('takes an item it put in the trash out again when WordPress restores it (I-1709)', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(15, ['status' => 'trash', 'status_before_trash' => 'publish', 'trashed_at' => 1759309200, 'slug' => 'old-news__trashed', 'title' => 'Old news', 'permalink' => 'https://old.example/?p=15'])]);
    expect(cphItem($this->source->id, 15)->trashed())->toBeTrue();
    $this->travel(5)->seconds();

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(15, ['slug' => 'old-news', 'title' => 'Old news', 'permalink' => 'https://old.example/old-news/', 'modified_gmt' => '2026-10-05 10:00:00'])]);

    expect(cphItem($this->source->id, 15)->trashed())->toBeFalse()
        ->and(cphItem($this->source->id, 15)->status)->toBe('published')
        ->and(addrCurrent('content', cphItem($this->source->id, 15)->id))->toBe('old-news')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 15)))->not->toContain('post.trashed-here');
});

it('brings a scheduled item with its schedule, a draft, one in review, and one from WordPress’s trash into Teme’s (I-1709)', function (): void {
    $future = CarbonImmutable::now('UTC')->addDays(5)->startOfMinute();

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['status' => 'future', 'date_gmt' => $future->format('Y-m-d H:i:s'), 'date' => $future->format('Y-m-d H:i:s')]),
        ContentImports::wpItem(13, ['status' => 'draft', 'slug' => '', 'title' => 'Notes for later', 'date_gmt' => '0000-00-00 00:00:00', 'permalink' => 'https://old.example/?p=13']),
        ContentImports::wpItem(14, ['status' => 'pending', 'slug' => 'review-me', 'title' => 'Review me', 'permalink' => 'https://old.example/?p=14']),
        ContentImports::wpItem(15, ['status' => 'trash', 'status_before_trash' => 'publish', 'trashed_at' => 1759309200, 'slug' => 'old-news__trashed', 'title' => 'Old news', 'permalink' => 'https://old.example/?p=15']),
    ]);

    expect(cphItem($this->source->id, 12)->status)->toBe('scheduled')
        ->and(ScheduledPublication::query()->where('content_id', cphItem($this->source->id, 12)->id)->first()?->run_at->equalTo($future))->toBeTrue()
        ->and(cphItem($this->source->id, 13)->slug)->toBe('notes-for-later')
        ->and(cphItem($this->source->id, 13)->published_at)->toBeNull()
        ->and(cphItem($this->source->id, 14)->status)->toBe('review')
        ->and(ContentReview::query()->where('content_id', cphItem($this->source->id, 14)->id)->whereNull('decided_at')->exists())->toBeTrue()
        ->and(cphItem($this->source->id, 15)->trashed())->toBeTrue()
        ->and(cphItem($this->source->id, 15)->slug)->toBe('old-news')
        ->and(CarbonImmutable::parse((string) cphItem($this->source->id, 15)->deleted_at)->toIso8601String())->toBe('2025-10-01T09:00:00+00:00')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 15)))->toContain('post.trashed');
});

it('keeps WordPress’s address answering for a child page, and says so (I-1710)', function (): void {
    // Pages at flat addresses: nothing puts the page under its parent (I-3363).
    addrChange($this->admin);
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(14, ['type' => 'page', 'slug' => 'team', 'title' => 'Our team', 'parent' => 13, 'menu_order' => 2, 'permalink' => 'https://old.example/about/team/'])]);
    $row = ContentImports::row($this->source->id, 14);

    expect(addrCurrent('content', (string) $row?->teme_id))->toBe('team')
        ->and($row?->facts['parent'])->toBe(13)
        ->and($row?->facts['path'])->toBe('about/team')
        ->and(ContentImports::codes($row))->toContain('post.address-moved')
        ->and(addrLocation(addrGet('/about/team/')->assertStatus(301)))->toBe('/team/');
});

it('keeps a dated address the same when the timezone came across first', function (): void {
    cmSettings(['timezone' => 'Europe/Berlin'], 'general');
    addrChange($this->admin, ['patterns' => ['post' => '/{year}/{month}/{day}/{slug}/']]);

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['date' => '2019-05-04 00:30:00', 'permalink' => 'https://old.example/2019/05/04/harbour-news/'])]);
    $row = ContentImports::row($this->source->id, 12);

    expect(addrCurrent('content', (string) $row?->teme_id))->toBe('2019/05/04/harbour-news')
        ->and(DB::table('paths')->where('target_id', $row?->teme_id)->where('is_current', false)->count())->toBe(0)
        ->and(ContentImports::codes($row))->not->toContain('post.address-moved');
});

it('keeps WordPress’s dated address and an old slug’s answering when the day differs from Teme’s', function (): void {
    // A site twelve hours ahead of UTC on WordPress, its time zone not one Teme took: Teme stays in UTC.
    addrChange($this->admin, ['patterns' => ['post' => '/{year}/{month}/{day}/{slug}/']]);

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, [
        'date' => '2019-05-04 00:30:00', 'date_gmt' => '2019-05-03 12:30:00', 'old_slugs' => ['harbor-news'],
        'permalink' => 'https://old.example/2019/05/04/harbour-news/',
    ])]);
    $row = ContentImports::row($this->source->id, 12);

    expect(addrCurrent('content', (string) $row?->teme_id))->toBe('2019/05/03/harbour-news')
        ->and(DB::table('paths')->where('target_id', $row?->teme_id)->where('is_current', false)->pluck('path')->all())->toContain('2019/05/04/harbour-news', '2019/05/04/harbor-news')
        ->and(ContentImports::codes($row))->toContain('post.address-moved')
        ->and(addrLocation(addrGet('/2019/05/04/harbour-news/')->assertStatus(301)))->toBe('/2019/05/03/harbour-news/')
        ->and(addrLocation(addrGet('/2019/05/04/harbor-news/')->assertStatus(301)))->toBe('/2019/05/03/harbour-news/');
});

it('records an error when WordPress’s own address is another item’s here, so the check can’t pass a wrong page', function (): void {
    pageArticle($this->author, 'Old news');

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['permalink' => 'https://old.example/old-news/'])]);
    $row = ContentImports::row($this->source->id, 12);
    $taken = ImportMessageRecord::query()->where('map_id', $row?->id)->where('code', 'post.address-taken')->first();

    expect($row?->status)->toBe(ImportStatus::Imported)
        ->and($taken?->level->value)->toBe('error')
        ->and($taken?->message)->toContain('/old-news/, which leads to the post "Old news" here');
});

it('gives the second of two items wanting one address the next free one, saying so', function (): void {
    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(21, ['slug' => 'news', 'title' => 'News', 'permalink' => 'https://old.example/news/']),
        ContentImports::wpItem(22, ['type' => 'page', 'slug' => 'news', 'title' => 'News page', 'permalink' => 'https://old.example/news-page/']),
    ]);
    $page = ContentImports::row($this->source->id, 22);

    expect(cphItem($this->source->id, 22)->slug)->toBe('news-2')
        ->and(ContentImports::codes($page))->toContain('post.slug-changed')
        ->and(addrCurrent('content', (string) $page?->teme_id))->toBe('news-2')
        ->and(addrLocation(addrGet('/news-page/')->assertStatus(301)))->toBe('/news-2/');
});

it('stops the run for an address an item this source didn’t make already has (I-1706)', function (): void {
    addrPublished($this->admin, 'Harbour news', 'page');

    $run = ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);

    expect($run->refresh()->state)->toBe(ImportRunState::Stopped)
        ->and($run->stop_reason)->toBe('address-clash')
        ->and($run->stop_message)->toContain('Teme already has the page "Harbour news" at /harbour-news/, and “Harbour news” from WordPress needs that address too')
        ->and(Content::query()->where('type', 'post')->count())->toBe(0);
});

it('keeps a reusable block’s content for the second pass, and the references and links items leave for it (I-333)', function (): void {
    $ferries = pageArticle($this->author, 'Ferries');

    ContentImports::run($this->companion, $this->source, json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/content.json')), true)['items']);
    $block = ContentImports::row($this->source->id, 88);
    $post = ContentImports::row($this->source->id, 12);

    expect($block?->status)->toBe(ImportStatus::Skipped)
        ->and($block?->teme_id)->toBeNull()
        ->and($block?->facts['kind'])->toBe('reusable')
        ->and(json_encode($block?->facts['document']))->toContain('Get the harbour news every week.')
        ->and(ContentImports::codes($block))->toBe(['post.reusable-block'])
        ->and(array_map(fn (array $reference): array => [$reference['type'], $reference['id']], $post?->facts['references'] ?? []))->toBe([['post', '88']])
        ->and($post?->facts['pending_links'])->toBe(['/later-story/'])
        ->and(json_encode(ContentRevision::query()->findOrFail(cphItem($this->source->id, 12)->working_revision_id)->document))->toContain('teme:content\/'.$ferries->id);
});

it('attaches what the converter said to the item it converted, and marks media it lacked', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['blocks' => [
        wpBlock('core/paragraph', '<p>Boats came in at dawn.</p>'),
        wpBlock('core/image', '<figure class="wp-block-image"><img src="https://old.example/wp-content/uploads/2024/05/gone.jpg" alt="Gone"/></figure>', ['id' => 99]),
    ]])]);
    $row = ContentImports::row($this->source->id, 12);

    expect(ContentImports::codes($row))->toContain('image.missing')
        ->and($row?->facts['missing'])->toBeTrue();
});

it('skips a type Teme doesn’t import and a status it doesn’t, saying so', function (): void {
    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['type' => 'idb_movie']),
        ContentImports::wpItem(13, ['status' => 'auto-draft', 'slug' => 'auto', 'title' => 'Auto']),
    ]);

    expect(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::Skipped)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toBe(['post.other-type'])
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->toBe(['post.status-skipped'])
        ->and(Content::query()->count())->toBe(0);
});

it('fails an item Teme refuses whole, and imports the rest', function (): void {
    $huge = array_map(fn (int $n): array => wpBlock('core/paragraph', "<p>Line {$n}</p>"), range(1, 11000));

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['blocks' => $huge]), ContentImports::wpItem(13, ['slug' => 'small', 'title' => 'Small', 'permalink' => 'https://old.example/small/'])]);

    expect(ContentImports::row($this->source->id, 12)?->status)->toBe(ImportStatus::Failed)
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toBe(['object.refused'])
        ->and(ContentImports::row($this->source->id, 13)?->status)->toBe(ImportStatus::Imported)
        ->and(Content::query()->count())->toBe(1);
});

it('imports each item once, and a run with nothing changed writes nothing', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);
    $before = [DB::table('contents')->get(), DB::table('content_revisions')->count(), DB::table('paths')->count()];
    $this->travel(5)->seconds();

    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);

    expect([DB::table('contents')->get(), DB::table('content_revisions')->count(), DB::table('paths')->count()])->toEqual($before)
        ->and(ImportMapEntry::query()->where('object_type', 'post')->count())->toBe(1);
});

it('follows WordPress’s later changes until a person changes the item here, then says so and never undoes them', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);
    $this->travel(5)->seconds();
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['title' => 'Harbour news, updated', 'modified_gmt' => '2019-06-01 10:00:00'])]);
    $item = cphItem($this->source->id, 12);
    $updated = $item->title;

    pageSaveDraft($item, 'A person’s own title');
    $this->travel(5)->seconds();
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12, ['title' => 'WordPress again', 'modified_gmt' => '2019-07-01 10:00:00'])]);
    $row = ContentImports::row($this->source->id, 12);

    expect($updated)->toBe('Harbour news, updated')
        ->and(ContentRevision::query()->findOrFail(cphItem($this->source->id, 12)->working_revision_id)->title)->toBe('A person’s own title')
        ->and($row?->outcome)->toBe(ImportOutcome::Updated)
        ->and(ContentImports::codes($row))->toContain('post.changed-here');
});

it('leaves an item deleted here deleted, and one in Teme’s trash in the trash', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12), ContentImports::wpItem(13, ['slug' => 'second', 'title' => 'Second', 'permalink' => 'https://old.example/second/'])]);
    cphItem($this->source->id, 12)->forceDelete();
    pageTrash(cphItem($this->source->id, 13), $this->admin);
    $this->travel(5)->seconds();

    ContentImports::run($this->companion, $this->source, [
        ContentImports::wpItem(12, ['modified_gmt' => '2019-06-01 10:00:00']),
        ContentImports::wpItem(13, ['slug' => 'second', 'title' => 'Second, updated', 'permalink' => 'https://old.example/second/', 'modified_gmt' => '2019-06-01 10:00:00']),
    ]);

    expect(ContentImports::codes(ContentImports::row($this->source->id, 12)))->toBe(['post.deleted-here'])
        ->and(Content::withTrashed()->count())->toBe(1)
        ->and(cphItem($this->source->id, 13)->trashed())->toBeTrue()
        ->and(cphItem($this->source->id, 13)->title)->toBe('Second')
        ->and(ContentImports::codes(ContentImports::row($this->source->id, 13)))->toContain('post.trashed-here');
});

it('asks the companion for posts, pages and reusable blocks in every status Teme brings, 50 a page, and only what changed in a catch-up (I-345)', function (): void {
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)]);
    $this->travel(5)->seconds();
    ContentImports::run($this->companion, $this->source, [ContentImports::wpItem(12)], ImportRunKind::CatchUp);
    [$full, $catchUp] = $this->companion->requestsTo('content');

    expect($full['query']['types'])->toBe('post,page,wp_block')
        ->and($full['query']['statuses'])->toBe('publish,future,draft,pending,private,trash')
        ->and($full['query']['limit'])->toBe('50')
        ->and($full['query'])->not->toHaveKey('include')
        ->and($full['query'])->not->toHaveKey('modified_after')
        ->and($catchUp['query'])->toHaveKey('modified_after');
});

it('stores no old-site address in any item, its fields or its words (rule 9)', function (): void {
    pageArticle($this->author, 'Ferries');

    ContentImports::run($this->companion, $this->source, json_decode((string) file_get_contents(base_path('tests/Fixtures/Import/Companion/content.json')), true)['items']);

    expect(json_encode([DB::table('contents')->get(['fields', 'slug', 'title']), DB::table('content_revisions')->get(['document', 'fields'])]))->not->toContain('old.example');
});

it('registers the posts and pages phase at 500 for every run, and its waiting step at 490 for full runs', function (): void {
    $phases = app(ImportPhases::class);
    $ours = [$phases->find('content-waiting'), $phases->find('content')];

    expect(array_map(fn ($phase): ?int => $phase?->order(), $ours))->toBe([490, 500])
        ->and($ours[0])->toBeInstanceOf(ContentWaitingStep::class)
        ->and($ours[1])->toBeInstanceOf(ContentPhase::class)
        ->and(array_map(fn ($phase): bool => (bool) $phase?->runsIn(ImportRunKind::CatchUp), $ours))->toBe([false, true]);
});

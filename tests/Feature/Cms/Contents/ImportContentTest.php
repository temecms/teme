<?php

declare(strict_types=1);

use App\Domain\Comments\Discussion;
use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * Every row the import could have written, counted.
 *
 * @return array{contents: int, revisions: int, paths: int, schedules: int, reviews: int}
 */
function ipcCounts(): array
{
    return [
        'contents' => DB::table('contents')->count(),
        'revisions' => DB::table('content_revisions')->count(),
        'paths' => DB::table('paths')->where('target_type', 'content')->count(),
        'schedules' => DB::table('scheduled_publications')->count(),
        'reviews' => DB::table('content_reviews')->count(),
    ];
}

/**
 * Teme's address pattern for posts set to WordPress's /%year%/%monthnum%/%day%/%postname%/.
 */
function ipcDatedAddresses(User $admin): void
{
    addrChange($admin, ['patterns' => ['post' => '/{year}/{month}/{day}/{slug}/']]);
}

it('writes a published post as WordPress had it: one revision of the import’s, its dates, author, discussion and guid (I-332)', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author));
    $content = Content::query()->findOrFail($result['contentId']);
    $revision = ContentRevision::query()->findOrFail($content->working_revision_id);

    expect($content->status)->toBe('published')
        ->and($content->title)->toBe('Harbour news')
        ->and($content->slug)->toBe('harbour-news')
        ->and((int) $content->author_id)->toBe($this->author->id)
        ->and($content->comment_status)->toBe(Discussion::Open)
        ->and($content->guid)->toBe('https://old.example/?p=12')
        ->and(CarbonImmutable::parse((string) $content->published_at)->utc()->toIso8601String())->toBe('2019-05-03T22:30:00+00:00')
        ->and(CarbonImmutable::parse((string) $content->created_at)->utc()->toIso8601String())->toBe('2019-05-03T22:30:00+00:00')
        ->and(CarbonImmutable::parse((string) $content->updated_at)->utc()->toIso8601String())->toBe('2019-05-04T08:00:00+00:00')
        ->and($content->published_revision_id)->toBe($content->working_revision_id)
        ->and($revision->origin)->toBe('import')
        ->and($revision->kind)->toBe('import')
        ->and((int) $revision->author_id)->toBe($this->author->id)
        ->and(CarbonImmutable::parse((string) $revision->created_at)->utc()->toIso8601String())->toBe('2019-05-04T08:00:00+00:00')
        ->and($revision->plain_text)->toBe('Boats came in at dawn.')
        ->and(addrCurrent('content', $content->id))->toBe('harbour-news')
        ->and(ContentCounts::for('post'))->toBe(['published' => 1])
        ->and(DB::table('contents')->where('id', $content->id)->value('search_text'))->toBe('Boats came in at dawn.')
        ->and($result['written'])->toBe(ImportedContents::fingerprint($content))
        ->and($result['moved'])->toBeNull();

    addrGet('/harbour-news/')->assertOk()->assertSee('Boats came in at dawn.');
});

it('imports as a person who may import, the item and its revision still its author’s', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $content = Content::query()->findOrFail($result['contentId']);

    expect((int) $content->author_id)->toBe($this->author->id)
        ->and((int) ContentRevision::query()->whereKey($content->working_revision_id)->value('author_id'))->toBe($this->author->id);
});

it('writes a draft with no address, no published revision and nothing to search', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author, ['status' => 'draft', 'publishedAt' => null]));
    $content = Content::query()->findOrFail($result['contentId']);

    expect($content->status)->toBe('draft')
        ->and($content->published_at)->toBeNull()
        ->and($content->published_revision_id)->toBeNull()
        ->and(addrCurrent('content', $content->id))->toBeNull()
        ->and(DB::table('paths')->where('target_id', $content->id)->count())->toBe(0)
        ->and(DB::table('contents')->where('id', $content->id)->value('search_text'))->toBeNull()
        ->and(ContentCounts::for('post'))->toBe(['draft' => 1]);
});

it('puts an item in review, submitted by its author when WordPress last changed it', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author, ['status' => 'review', 'publishedAt' => null]));
    $review = ContentReview::query()->where('content_id', $result['contentId'])->firstOrFail();

    expect(Content::query()->findOrFail($result['contentId'])->status)->toBe('review')
        ->and((int) $review->submitted_by)->toBe($this->author->id)
        ->and($review->decided_at)->toBeNull()
        ->and(CarbonImmutable::parse((string) $review->submitted_at)->utc()->toIso8601String())->toBe('2019-05-04T08:00:00+00:00');
});

it('schedules an item for its time, published by the person the import names', function (): void {
    $when = CarbonImmutable::now('UTC')->addDays(3)->startOfMinute();
    $result = ContentImports::import(ContentImports::item($this->author, ['status' => 'scheduled', 'publishedAt' => $when, 'schedulerId' => (string) $this->admin->id]));
    $content = Content::query()->findOrFail($result['contentId']);
    $schedule = ScheduledPublication::query()->where('content_id', $content->id)->firstOrFail();

    expect($content->status)->toBe('scheduled')
        ->and(CarbonImmutable::parse((string) $content->scheduled_for)->equalTo($when))->toBeTrue()
        ->and($schedule->action)->toBe('publish')
        ->and($schedule->run_at->equalTo($when))->toBeTrue()
        ->and($schedule->revision_id)->toBe($content->working_revision_id)
        ->and((int) $schedule->created_by)->toBe($this->admin->id)
        ->and($schedule->isPending())->toBeTrue()
        ->and(addrCurrent('content', $content->id))->toBeNull();
});

it('hands a schedule whose time has passed to Teme’s scheduler as it is, due at once', function (): void {
    $passed = CarbonImmutable::now('UTC')->subDay()->startOfMinute();
    $result = ContentImports::import(ContentImports::item($this->author, ['status' => 'scheduled', 'publishedAt' => $passed, 'schedulerId' => (string) $this->admin->id]));

    expect(ScheduledPublication::query()->where('content_id', $result['contentId'])->whereNull('completed_at')->where('run_at', '<=', now())->count())->toBe(1);
});

it('puts a trashed item in Teme’s trash with WordPress’s own date, its slug free for a live item (I-1709)', function (): void {
    $trashed = CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC');
    $result = ContentImports::import(ContentImports::item($this->author, ['trashedAt' => $trashed]));
    $content = Content::withTrashed()->findOrFail($result['contentId']);
    ContentImports::import(ContentImports::item($this->author, ['wordpressPath' => null]));

    expect($content->trashed())->toBeTrue()
        ->and(CarbonImmutable::parse((string) $content->deleted_at)->equalTo($trashed))->toBeTrue()
        ->and($content->status)->toBe('published')
        ->and(addrCurrent('content', $content->id))->toBeNull()
        ->and(DB::table('paths')->where('target_id', $content->id)->count())->toBe(0)
        ->and(ContentCounts::for('post'))->toEqualCanonicalizing(['published' => 1, 'trash' => 1]);
});

it('imports a post closed for comments when WordPress closed them, though posts start open', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author, ['discussion' => Discussion::Closed]));

    expect(Content::query()->findOrFail($result['contentId'])->comment_status)->toBe(Discussion::Closed);
});

it('files it under its terms and sets its fields, the primary category and featured image among them (I-869)', function (): void {
    $news = typesTerm($this->admin, 'category', 'News');
    $boats = typesTerm($this->admin, 'category', 'Boats');
    $tides = typesTerm($this->admin, 'tag', 'Tides');
    $photo = mediaUpload($this->admin, mediaJpeg());

    $result = ContentImports::import(ContentImports::item($this->author, [
        'terms' => [$boats->id, $news->id, $tides->id],
        'fields' => [
            BuiltInFields::PRIMARY_CATEGORY => $news->id,
            BuiltInFields::EXCERPT => 'The ferries ran late.',
            BuiltInFields::STICKY => true,
            BuiltInFields::FEATURED_IMAGE => ['id' => $photo->id, 'alt' => null, 'decorative' => false],
        ],
    ]));
    $content = Content::query()->findOrFail($result['contentId']);

    expect(ContentRevision::query()->findOrFail($content->working_revision_id)->terms)->toBe([$boats->id, $news->id, $tides->id])
        ->and(DB::table('content_terms')->where('content_id', $content->id)->where('scope', 'published')->orderBy('position')->pluck('term_id')->all())->toBe([$boats->id, $news->id, $tides->id])
        ->and($content->fields[BuiltInFields::PRIMARY_CATEGORY])->toBe($news->id)
        ->and($content->fields[BuiltInFields::EXCERPT])->toBe('The ferries ran late.')
        ->and($content->fields[BuiltInFields::STICKY])->toBeTrue()
        ->and(DB::table('media_usages')->where('content_id', $content->id)->where('media_id', $photo->id)->exists())->toBeTrue();
});

it('keeps WordPress’s own address answering when Teme’s date differs, and never at the home', function (): void {
    ipcDatedAddresses($this->admin);
    // WordPress's local date was the 4th, two hours ahead of UTC; Teme's timezone here is UTC.
    $result = ContentImports::import(ContentImports::item($this->author, ['wordpressPath' => '2019/05/04/harbour-news']));
    $page = ContentImports::import(ContentImports::item($this->author, ['type' => 'page', 'slug' => 'welcome', 'title' => 'Welcome', 'wordpressPath' => '']));

    expect(addrCurrent('content', $result['contentId']))->toBe('2019/05/03/harbour-news')
        ->and($result['moved'])->toBe(['from' => '2019/05/04/harbour-news', 'to' => '2019/05/03/harbour-news'])
        ->and(addrLocation(addrGet('/2019/05/04/harbour-news/')->assertStatus(301)))->toBe('/2019/05/03/harbour-news/')
        ->and(DB::table('paths')->where('path', '')->exists())->toBeFalse()
        ->and($page['moved'])->toBeNull();
});

it('keeps a child page’s nested address answering with a 301 to its flat one (I-1710)', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author, ['type' => 'page', 'title' => 'Our team', 'slug' => 'team', 'wordpressPath' => 'about/team']));

    expect(addrCurrent('content', $result['contentId']))->toBe('team')
        ->and($result['moved'])->toBe(['from' => 'about/team', 'to' => 'team'])
        ->and(addrLocation(addrGet('/about/team/')->assertStatus(301)))->toBe('/team/');
});

it('writes the old slugs’ and old dates’ addresses as WordPress finds them (I-061)', function (): void {
    ipcDatedAddresses($this->admin);
    $result = ContentImports::import(ContentImports::item($this->author, ['wordpressPath' => '2019/05/03/harbour-news', 'oldSlugs' => ['harbor-news'], 'oldDates' => ['2019-04-30']]));
    $history = DB::table('paths')->where('target_id', $result['contentId'])->where('is_current', false)->pluck('path')->all();

    expect($history)->toEqualCanonicalizing(['2019/05/03/harbor-news', '2019/04/30/harbour-news', '2019/04/30/harbor-news'])
        ->and($result['moved'])->toBeNull()
        ->and($result['taken'])->toBe(0);

    foreach (['/2019/05/03/harbor-news/', '/2019/04/30/harbour-news/', '/2019/04/30/harbor-news/'] as $old) {
        expect(addrLocation(addrGet($old)->assertStatus(301)))->toBe('/2019/05/03/harbour-news/');
    }
});

it('leaves an address another item holds alone, writes each old address once, and keeps at most 50', function (): void {
    pageArticle($this->author, 'Harbor news');
    $slugs = ['harbor-news', 'harbor-news', ...array_map(fn (int $n): string => "old-name-{$n}", range(1, 60))];
    $result = ContentImports::import(ContentImports::item($this->author, ['oldSlugs' => $slugs]));

    expect($result['taken'])->toBe(1)
        ->and($result['many'])->toBeTrue()
        ->and(DB::table('paths')->where('target_id', $result['contentId'])->where('is_current', false)->count())->toBe(ImportedContents::MOST_OLD_ADDRESSES - 1)
        ->and(addrGet('/harbor-news/')->assertOk()->getContent())->not->toContain('Boats came in at dawn.');
});

it('says when WordPress’s own address is another item’s current one here, so the check can’t pass a wrong page', function (): void {
    pageArticle($this->author, 'Harbor news');
    $result = ContentImports::import(ContentImports::item($this->author, ['wordpressPath' => 'harbor-news']));

    expect($result['clash'])->toBe(['from' => 'harbor-news', 'holder' => 'the post "Harbor news"'])
        ->and($result['moved'])->toBeNull()
        ->and($result['taken'])->toBe(0)
        ->and(DB::table('paths')->where('path', 'harbor-news')->value('target_id'))->not->toBe($result['contentId']);
});

it('leaves no history row for a draft, an item in the trash, or an address the site keeps for itself', function (): void {
    $draft = ContentImports::import(ContentImports::item($this->author, ['status' => 'draft', 'publishedAt' => null, 'wordpressPath' => 'old-draft']));
    $trashed = ContentImports::import(ContentImports::item($this->author, ['trashedAt' => CarbonImmutable::now(), 'wordpressPath' => 'old-trashed']));
    $reserved = ContentImports::import(ContentImports::item($this->author, ['slug' => 'storm-report', 'title' => 'Storm report', 'wordpressPath' => 'feed/storm-report']));

    expect(DB::table('paths')->whereIn('target_id', [$draft['contentId'], $trashed['contentId']])->count())->toBe(0)
        ->and(DB::table('paths')->where('target_id', $reserved['contentId'])->pluck('path')->all())->toBe(['storm-report'])
        ->and($reserved['taken'])->toBe(1)
        ->and($reserved['moved'])->toBeNull();
});

it('turns a link to an article on this site into a link by ID on save, and says which links it changed (I-1669)', function (): void {
    $ferries = pageArticle($this->author, 'Ferries');
    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [[
        'type' => 'text', 'text' => 'the ferries',
        'marks' => [['type' => 'link', 'attrs' => ['href' => '/ferries/', 'target' => null, 'rel' => null, 'class' => null, 'title' => null]]],
    ]]]]];

    $result = ContentImports::import(ContentImports::item($this->author, ['document' => $document]));
    $stored = Content::query()->findOrFail($result['contentId']);

    expect($result['links'])->toBe([['from' => '/ferries/', 'to' => 'teme:content/'.$ferries->id]])
        ->and(json_encode(ContentRevision::query()->findOrFail($stored->working_revision_id)->document))->toContain('teme:content\/'.$ferries->id)
        ->and(DB::table('content_links')->where('from_content_id', $stored->id)->where('to_content_id', $ferries->id)->exists())->toBeTrue();
});

it('refuses an address another item already has, writing nothing', function (): void {
    // A page at /harbour-news/: the post's slug is free among posts, its address isn't.
    addrPublished($this->admin, 'Harbour news', 'page');
    $before = ipcCounts();

    expect(fn () => ContentImports::import(ContentImports::item($this->author)))
        ->toThrow(ValidationException::class, 'The address /harbour-news/ is used by the page "Harbour news".')
        ->and(ipcCounts())->toBe($before);
});

it('refuses what Teme’s own rules refuse, writing nothing', function (string $case, string $key): void {
    $tag = typesTerm($this->admin, 'tag', 'Tides');
    lifecycleDraft($this->author, 'Taken draft');
    $changes = match ($case) {
        'a title too long' => ['title' => str_repeat('a', 513)],
        'a slug with an underscore' => ['slug' => 'harbour_news'],
        'a slug a live draft of its type has' => ['slug' => 'taken-draft'],
        'WordPress’s own word for a status' => ['status' => 'pending'],
        'a published item with no date' => ['publishedAt' => null],
        'an author who isn’t here' => ['authorId' => '987654'],
        'a tag on a page' => ['type' => 'page', 'terms' => [$tag->id]],
        'a field a page doesn’t have' => ['type' => 'page', 'fields' => [BuiltInFields::STICKY => true]],
        'a template the theme doesn’t offer' => ['type' => 'page', 'fields' => [BuiltInFields::TEMPLATE => 'no-such-template']],
        'a document the editor can’t hold' => ['document' => ['type' => 'doc', 'content' => [['type' => 'marquee']]]],
    };
    $before = ipcCounts();

    try {
        ContentImports::import(ContentImports::item($this->author, $changes));
        $this->fail('The item was imported.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toContain($key);
    }

    expect(ipcCounts())->toBe($before);
})->with([
    ['a title too long', 'title'],
    ['a slug with an underscore', 'slug'],
    ['a slug a live draft of its type has', 'slug'],
    ['WordPress’s own word for a status', 'status'],
    ['a published item with no date', 'published_at'],
    ['an author who isn’t here', 'author'],
    ['a tag on a page', 'terms.0'],
    ['a field a page doesn’t have', 'fields'],
    ['a template the theme doesn’t offer', 'fields.'.BuiltInFields::TEMPLATE],
    ['a document the editor can’t hold', 'document'],
]);

it('measures its limits in characters, never bytes', function (): void {
    $result = ContentImports::import(ContentImports::item($this->author, ['title' => str_repeat('é', 512), 'slug' => str_repeat('é', 200), 'wordpressPath' => null]));

    expect(mb_strlen(Content::query()->findOrFail($result['contentId'])->title))->toBe(512)
        ->and(mb_strlen(Content::query()->findOrFail($result['contentId'])->slug))->toBe(200);
});

it('writes nothing when the actor may not import', function (string $who): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = ipcCounts();

    expect(fn () => ContentImports::import(ContentImports::item($this->author), $who === 'an editor' ? $editor : null, shell: false))->toThrow(AuthorizationException::class)
        ->and(ipcCounts())->toBe($before);
})->with(['an editor', 'no one, away from the operator’s shell']);

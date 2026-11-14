<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\CompleteImportedContent;
use App\Domain\Contents\Operations\Input\CompleteImportedContentInput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ContentImports;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->author = User::factory()->create(['name' => 'Ada Lovelace'])->assignRole('author');
});

/**
 * The second pass's save of an imported item, as $actor (the administrator by default).
 *
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $fields
 *
 * @return array{changedHere: bool, wrote: bool, written: string, revisionId: string}
 */
function cplComplete(string $contentId, string $written, array $document, array $fields = [], ?User $actor = null): array
{
    return app(CompleteImportedContent::class)->execute(new CompleteImportedContentInput($contentId, $written, $document, $fields, (string) ($actor ?? test()->admin)->id));
}

/**
 * A document of two paragraphs, the second linking to $targetId when given.
 *
 * @return array<string, mixed>
 */
function cplDocument(?string $targetId = null): array
{
    $link = $targetId === null ? [] : ['marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$targetId]]]];

    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Boats came in at dawn.']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Open daily from nine.', ...$link]]],
    ]];
}

it('writes one revision of the import’s at the working revision’s time and moves both pointers (I-333)', function (): void {
    $target = ContentImports::import(ContentImports::item($this->author, ['title' => 'Tides', 'slug' => 'tides', 'wordpressPath' => 'tides', 'guid' => null]), $this->admin);
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $before = Content::query()->findOrFail($imported['contentId']);
    $first = ContentRevision::query()->findOrFail($before->working_revision_id);

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument($target['contentId']));
    $after = Content::query()->findOrFail($imported['contentId']);
    $revision = ContentRevision::query()->findOrFail($after->working_revision_id);

    expect($done['wrote'])->toBeTrue()
        ->and($done['changedHere'])->toBeFalse()
        ->and($done['revisionId'])->toBe((string) $revision->id)
        ->and((string) $revision->id)->not->toBe((string) $first->id)
        ->and($after->published_revision_id)->toBe($revision->id)
        ->and($revision->origin)->toBe('import')
        ->and($revision->kind)->toBe('import')
        ->and($revision->title)->toBe('Harbour news')
        ->and((int) $revision->author_id)->toBe($this->author->id)
        ->and($revision->created_at->equalTo($first->created_at))->toBeTrue()
        ->and($revision->plain_text)->toBe('Boats came in at dawn. Open daily from nine.')
        ->and($revision->word_count)->toBe(9)
        ->and(CarbonImmutable::parse((string) $after->updated_at)->equalTo(CarbonImmutable::parse((string) $before->updated_at)))->toBeTrue()
        ->and(DB::table('content_links')->where('from_content_id', $imported['contentId'])->where('to_content_id', $target['contentId'])->pluck('scope')->sort()->values()->all())->toBe(['published', 'working'])
        ->and((string) $after->search_text)->toContain('Open daily from nine.');
});

it('returns the fingerprint the item has after it, for the import’s record (I-332)', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument());

    expect($done['written'])->toBe(ImportedContents::fingerprint(Content::query()->findOrFail($imported['contentId'])))
        ->and($done['written'])->not->toBe($imported['written']);
});

it('writes nothing when the words and fields are those it has, whatever order their keys come in', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $content = Content::query()->findOrFail($imported['contentId']);
    $stored = ContentRevision::query()->findOrFail($content->working_revision_id)->document;
    // The same document with each node's keys in another order.
    $reordered = ['content' => array_map(fn (array $node): array => array_reverse($node, true), $stored['content']), 'type' => 'doc'];

    $done = cplComplete($imported['contentId'], $imported['written'], $reordered);

    expect($done['wrote'])->toBeFalse()
        ->and($done['written'])->toBe($imported['written'])
        ->and($done['revisionId'])->toBe((string) $content->working_revision_id)
        ->and(ContentRevision::query()->where('content_id', $imported['contentId'])->count())->toBe(1);
});

it('leaves an item changed here as it is (I-332)', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    DB::table('contents')->where('id', $imported['contentId'])->update(['slug' => 'harbour-news-today']);

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument());

    expect($done['changedHere'])->toBeTrue()
        ->and($done['wrote'])->toBeFalse()
        ->and(ContentRevision::query()->where('content_id', $imported['contentId'])->count())->toBe(1);
});

it('moves a scheduled item’s pending schedule to the new revision, keeping its time and who set it', function (): void {
    $at = CarbonImmutable::now('UTC')->addDays(3)->startOfMinute();
    $imported = ContentImports::import(ContentImports::item($this->author, ['status' => 'scheduled', 'publishedAt' => $at, 'schedulerId' => (string) $this->admin->id, 'wordpressPath' => null]), $this->admin);

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument());
    $pending = ScheduledPublication::query()->where('content_id', $imported['contentId'])
        ->whereNull('completed_at')->whereNull('failed_at')->whereNull('cancelled_at')->get();

    expect($pending)->toHaveCount(1)
        ->and((string) $pending->first()->revision_id)->toBe($done['revisionId'])
        ->and($pending->first()->run_at->equalTo($at))->toBeTrue()
        ->and((int) $pending->first()->created_by)->toBe($this->admin->id)
        ->and(Content::query()->findOrFail($imported['contentId'])->published_revision_id)->toBeNull();
});

it('moves an item in review’s open review to the new revision, keeping who submitted it and when', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author, ['status' => 'review', 'publishedAt' => null, 'wordpressPath' => null]), $this->admin);
    $first = ContentReview::query()->where('content_id', $imported['contentId'])->whereNull('decided_at')->firstOrFail();

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument());
    $open = ContentReview::query()->where('content_id', $imported['contentId'])->whereNull('decided_at')->get();

    expect($open)->toHaveCount(1)
        ->and((string) $open->first()->revision_id)->toBe($done['revisionId'])
        ->and((int) $open->first()->submitted_by)->toBe((int) $first->submitted_by)
        ->and($open->first()->submitted_at->equalTo($first->submitted_at))->toBeTrue();
});

it('moves only a draft’s working pointer', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author, ['status' => 'draft', 'publishedAt' => null, 'wordpressPath' => null]), $this->admin);

    $done = cplComplete($imported['contentId'], $imported['written'], cplDocument());
    $after = Content::query()->findOrFail($imported['contentId']);

    expect((string) $after->working_revision_id)->toBe($done['revisionId'])
        ->and($after->published_revision_id)->toBeNull()
        ->and($after->status)->toBe('draft');
});

it('refuses a document Teme can’t save, writing nothing', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    // More nodes than a document may hold (I-1790): 10,100 paragraphs of one text each.
    $huge = ['type' => 'doc', 'content' => array_fill(0, 10100, ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Again.']]])];

    expect(fn (): array => cplComplete($imported['contentId'], $imported['written'], $huge))->toThrow(ValidationException::class)
        ->and(ContentRevision::query()->where('content_id', $imported['contentId'])->count())->toBe(1);
});

it('writes nothing when authorization fails', function (): void {
    $imported = ContentImports::import(ContentImports::item($this->author), $this->admin);
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn (): array => cplComplete($imported['contentId'], $imported['written'], cplDocument(), [], $editor))->toThrow(AuthorizationException::class)
        ->and(ContentRevision::query()->where('content_id', $imported['contentId'])->count())->toBe(1)
        ->and(Content::query()->findOrFail($imported['contentId'])->working_revision_id)->not->toBeNull();
});

<?php

declare(strict_types=1);

use App\Domain\Contents\ImportedContents;
use App\Domain\Contents\ImportedItem;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentReview;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Models\ScheduledPublication;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\UpdateImportedContentInput;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\Operations\UpdateImportedContent;
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
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * An item ContentImports::item() makes, with these changes, imported; the
 * item and the record the import keeps of it.
 *
 * @param  array<string, mixed>  $changes
 *
 * @return array{0: Content, 1: string}
 */
function upcImported(User $author, array $changes = []): array
{
    $result = ContentImports::import(ContentImports::item($author, $changes));

    return [Content::withTrashed()->findOrFail($result['contentId']), $result['written']];
}

/**
 * UpdateImportedContent for $content, as the operator unless $actor is
 * given, at the server's shell unless $shell is false.
 *
 * @return array{changedHere: bool, wrote: bool, written: string, moved: array{from: string, to: string}|null, taken: int, many: bool, clash: array{from: string, holder: string}|null, links: list<array{from: string, to: string|null}>}
 */
function upcUpdate(Content $content, string $written, ImportedItem $item, ?User $actor = null, bool $shell = true): array
{
    $input = new UpdateImportedContentInput((string) $content->id, $written, $item, $actor === null ? null : (string) $actor->id);
    $run = fn (): array => app(UpdateImportedContent::class)->execute($input);

    return $actor === null && $shell ? scElevated($run) : $run();
}

it('brings an item the import left as it was up to WordPress’s new words, title and terms, as a new revision of the import’s', function (): void {
    $tides = typesTerm($this->admin, 'tag', 'Tides');
    [$content, $written] = upcImported($this->author);
    $first = $content->working_revision_id;

    $result = upcUpdate($content, $written, ContentImports::item($this->author, [
        'title' => 'Harbour news, updated', 'terms' => [$tides->id], 'document' => ContentImports::document('The ferries ran late.'),
        'modifiedAt' => CarbonImmutable::parse('2019-06-01 10:00:00', 'UTC'),
    ]));
    $content->refresh();
    $revision = ContentRevision::query()->findOrFail($content->working_revision_id);

    expect($result['changedHere'])->toBeFalse()
        ->and($result['wrote'])->toBeTrue()
        ->and($content->working_revision_id)->not->toBe($first)
        ->and($content->published_revision_id)->toBe($content->working_revision_id)
        ->and($content->title)->toBe('Harbour news, updated')
        ->and($revision->origin)->toBe('import')
        ->and($revision->terms)->toBe([$tides->id])
        ->and($revision->plain_text)->toBe('The ferries ran late.')
        ->and(ContentRevision::query()->whereKey($first)->exists())->toBeTrue()
        ->and(CarbonImmutable::parse((string) $content->updated_at)->utc()->toIso8601String())->toBe('2019-06-01T10:00:00+00:00')
        ->and($result['written'])->toBe(ImportedContents::fingerprint($content))
        ->and($result['written'])->not->toBe($written);
});

it('writes nothing when WordPress’s item hasn’t changed, though JSONB gave its two fields back in its own order', function (): void {
    // Two fields or more: JSONB may store them in another order than they were written.
    $fields = [BuiltInFields::SEO_TITLE => 'Harbour news, tides and ferries', BuiltInFields::EXCERPT => 'The ferries ran late.'];
    [$content, $written] = upcImported($this->author, ['fields' => $fields]);
    $before = DB::table('contents')->where('id', $content->id)->first();

    $result = upcUpdate($content, $written, ContentImports::item($this->author, ['fields' => $fields]));

    expect($result['wrote'])->toBeFalse()
        ->and($result['written'])->toBe($written)
        ->and(ContentRevision::query()->where('content_id', $content->id)->count())->toBe(1)
        ->and(DB::table('contents')->where('id', $content->id)->first())->toEqual($before);
});

it('writes nothing when WordPress’s item comes again with the same words and new block ids, and keeps the stored ids when only its title changed (I-3362)', function (): void {
    $words = fn (string $heading, string $paragraph): array => ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => $heading], 'content' => [['type' => 'text', 'text' => 'Tides']]],
        ['type' => 'paragraph', 'attrs' => ['id' => $paragraph], 'content' => [['type' => 'text', 'text' => 'The ferries ran late.']]],
    ]];
    $again = $words('01K5Z9P2QH7N3X4C8V6B0M1T3F', '01K5Z9P2QH7N3X4C8V6B0M1T3G');
    [$content, $written] = upcImported($this->author, ['document' => $words('01K5Z9P2QH7N3X4C8V6B0M1T3D', '01K5Z9P2QH7N3X4C8V6B0M1T3E')]);

    $same = upcUpdate($content, $written, ContentImports::item($this->author, ['document' => $again]));
    $retitled = upcUpdate($content->refresh(), $same['written'], ContentImports::item($this->author, [
        'document' => $again, 'title' => 'Harbour news, updated', 'modifiedAt' => CarbonImmutable::parse('2019-06-01 10:00:00', 'UTC'),
    ]));
    $document = (array) ContentRevision::query()->findOrFail($content->refresh()->working_revision_id)->document;

    expect($same['wrote'])->toBeFalse()
        ->and($retitled['wrote'])->toBeTrue()
        ->and(ContentRevision::query()->where('content_id', $content->id)->count())->toBe(2)
        ->and(array_map(fn (array $node): string => $node['attrs']['id'], $document['content']))->toBe(['01K5Z9P2QH7N3X4C8V6B0M1T3D', '01K5Z9P2QH7N3X4C8V6B0M1T3E']);
});

it('keeps the stored ids of every block WordPress didn’t change when one word of the body changed', function (): void {
    $words = fn (string $heading, string $card, string $paragraph, string $text): array => ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2, 'id' => $heading], 'content' => [['type' => 'text', 'text' => 'Tides']]],
        ['type' => 'paragraph', 'attrs' => ['id' => $card], 'content' => [['type' => 'text', 'text' => 'High water at noon.']]],
        ['type' => 'paragraph', 'attrs' => ['id' => $paragraph], 'content' => [['type' => 'text', 'text' => $text]]],
    ]];
    [$content, $written] = upcImported($this->author, ['document' => $words('01K5Z9P2QH7N3X4C8V6B0M1T3D', '01K5Z9P2QH7N3X4C8V6B0M1T3E', '01K5Z9P2QH7N3X4C8V6B0M1T3F', 'The ferries ran late.')]);

    $result = upcUpdate($content, $written, ContentImports::item($this->author, [
        'document' => $words('01K5Z9P2QH7N3X4C8V6B0M1T3G', '01K5Z9P2QH7N3X4C8V6B0M1T3H', '01K5Z9P2QH7N3X4C8V6B0M1T3J', 'The ferries ran early.'),
        'modifiedAt' => CarbonImmutable::parse('2019-06-01 10:00:00', 'UTC'),
    ]));
    $document = (array) ContentRevision::query()->findOrFail($content->refresh()->working_revision_id)->document;

    expect($result['wrote'])->toBeTrue()
        ->and(array_map(fn (array $node): string => $node['attrs']['id'], $document['content']))->toBe(['01K5Z9P2QH7N3X4C8V6B0M1T3D', '01K5Z9P2QH7N3X4C8V6B0M1T3E', '01K5Z9P2QH7N3X4C8V6B0M1T3J'])
        ->and($document['content'][2]['content'][0]['text'])->toBe('The ferries ran early.');
});

it('keeps a person’s change, whatever they changed, and says so', function (string $change): void {
    [$content, $written] = upcImported($this->author);

    match ($change) {
        'a new address' => addrRename($content, 'harbour-report', $this->editor),
        'a saved draft' => pageSaveDraft($content, 'A person’s title'),
        'the trash' => pageTrash($content, $this->editor),
        'unpublishing' => app(UnpublishContent::class)->execute(new ContentActionInput((string) $content->id, (string) $this->editor->id)),
    };
    $after = DB::table('contents')->where('id', $content->id)->first();
    $revisions = ContentRevision::query()->where('content_id', $content->id)->count();

    $result = upcUpdate($content->refresh(), $written, ContentImports::item($this->author, ['title' => 'WordPress’s new title']));

    expect($result['changedHere'])->toBeTrue()
        ->and($result['wrote'])->toBeFalse()
        ->and($result['written'])->toBe($written)
        ->and(DB::table('contents')->where('id', $content->id)->first())->toEqual($after)
        ->and(ContentRevision::query()->where('content_id', $content->id)->count())->toBe($revisions);
})->with(['a new address', 'a saved draft', 'the trash', 'unpublishing']);

it('publishes an item WordPress published since, at its address, keeping WordPress’s answering', function (): void {
    [$content, $written] = upcImported($this->author, ['status' => 'draft', 'publishedAt' => null, 'wordpressPath' => null]);

    $result = upcUpdate($content, $written, ContentImports::item($this->author, ['wordpressPath' => '2019/05/04/harbour-news']));
    $content->refresh();

    expect($content->status)->toBe('published')
        ->and($content->published_revision_id)->toBe($content->working_revision_id)
        ->and(addrCurrent('content', $content->id))->toBe('harbour-news')
        ->and($result['moved'])->toBe(['from' => '2019/05/04/harbour-news', 'to' => 'harbour-news']);

    addrGet('/harbour-news/')->assertOk();
});

it('takes an item back to a draft when WordPress unpublished it, its old address no longer current', function (): void {
    [$content, $written] = upcImported($this->author);

    upcUpdate($content, $written, ContentImports::item($this->author, ['status' => 'draft']));
    $content->refresh();

    expect($content->status)->toBe('draft')
        ->and(addrCurrent('content', $content->id))->toBeNull()
        ->and(DB::table('contents')->where('id', $content->id)->value('search_text'))->toBeNull();
});

it('moves its address when WordPress changed its slug, the old one answering with a 301', function (): void {
    [$content, $written] = upcImported($this->author);

    upcUpdate($content, $written, ContentImports::item($this->author, ['slug' => 'harbour-report', 'wordpressPath' => 'harbour-report', 'oldSlugs' => ['harbour-news']]));

    expect(addrCurrent('content', $content->id))->toBe('harbour-report')
        ->and(addrLocation(addrGet('/harbour-news/')->assertStatus(301)))->toBe('/harbour-report/');
});

it('reschedules an item WordPress rescheduled, replacing its schedule', function (): void {
    $first = CarbonImmutable::now('UTC')->addDays(3)->startOfMinute();
    $later = $first->addDays(2);
    [$content, $written] = upcImported($this->author, ['status' => 'scheduled', 'publishedAt' => $first, 'schedulerId' => (string) $this->admin->id]);

    upcUpdate($content, $written, ContentImports::item($this->author, ['status' => 'scheduled', 'publishedAt' => $later, 'schedulerId' => (string) $this->admin->id]));
    $schedules = ScheduledPublication::query()->where('content_id', $content->id)->orderBy('created_at')->orderBy('id')->get();

    expect($schedules)->toHaveCount(2)
        ->and($schedules->firstWhere('cancel_reason', 'replaced'))->not->toBeNull()
        ->and($schedules->first(fn (ScheduledPublication $row): bool => $row->isPending())?->run_at->equalTo($later))->toBeTrue();
});

it('puts an item WordPress trashed into Teme’s trash, cancelling its schedule (I-1709)', function (): void {
    $trashed = CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC');
    [$content, $written] = upcImported($this->author, ['status' => 'scheduled', 'publishedAt' => CarbonImmutable::now('UTC')->addDay(), 'schedulerId' => (string) $this->admin->id]);

    upcUpdate($content, $written, ContentImports::item($this->author, ['status' => 'draft', 'publishedAt' => null, 'trashedAt' => $trashed]));
    $content = Content::withTrashed()->findOrFail($content->id);

    expect($content->trashed())->toBeTrue()
        ->and(CarbonImmutable::parse((string) $content->deleted_at)->equalTo($trashed))->toBeTrue()
        ->and($content->status)->toBe('draft')
        ->and(ScheduledPublication::query()->where('content_id', $content->id)->value('cancel_reason'))->toBe('trashed');
});

it('takes an item out of the trash when WordPress restored it, with the free address name the import gives', function (): void {
    [$content, $written] = upcImported($this->author, ['trashedAt' => CarbonImmutable::now('UTC')->subDay()]);
    ContentImports::import(ContentImports::item($this->author, ['wordpressPath' => null]));

    upcUpdate($content, $written, ContentImports::item($this->author, ['slug' => 'harbour-news-2']));
    $content->refresh();

    expect($content->trashed())->toBeFalse()
        ->and($content->slug)->toBe('harbour-news-2')
        ->and(addrCurrent('content', $content->id))->toBe('harbour-news-2');
});

it('keeps following WordPress after Teme’s scheduler published an item the import scheduled', function (): void {
    $soon = CarbonImmutable::now('UTC')->addMinute()->startOfMinute();
    [$content, $written] = upcImported($this->author, ['status' => 'scheduled', 'publishedAt' => $soon, 'schedulerId' => (string) $this->admin->id]);
    $this->travel(2)->minutes();
    $this->artisan('teme:content:run-due-publications')->assertSuccessful();

    expect($content->refresh()->status)->toBe('published')
        ->and(ImportedContents::fingerprint($content))->toBe($written);

    $result = upcUpdate($content, $written, ContentImports::item($this->author, ['title' => 'WordPress’s new title', 'publishedAt' => $soon]));

    expect($result['changedHere'])->toBeFalse()
        ->and($content->refresh()->title)->toBe('WordPress’s new title');
});

it('withdraws an open review of words WordPress has since changed, and opens one of the new words', function (): void {
    [$content, $written] = upcImported($this->author, ['status' => 'review', 'publishedAt' => null]);
    $first = ContentReview::query()->where('content_id', $content->id)->firstOrFail();

    upcUpdate($content, $written, ContentImports::item($this->author, ['status' => 'review', 'publishedAt' => null, 'document' => ContentImports::document('The ferries ran late.')]));
    $open = ContentReview::query()->where('content_id', $content->id)->whereNull('decided_at')->firstOrFail();

    expect($first->refresh()->decision)->toBe('withdrawn')
        ->and($open->id)->not->toBe($first->id)
        ->and($open->revision_id)->toBe($content->refresh()->working_revision_id);
});

it('ends a review when WordPress published the item, and starts one when WordPress asked for it', function (): void {
    [$reviewed, $writtenReviewed] = upcImported($this->author, ['status' => 'review', 'publishedAt' => null]);
    [$draft, $writtenDraft] = upcImported($this->author, ['status' => 'draft', 'publishedAt' => null, 'slug' => 'harbour-draft']);

    upcUpdate($reviewed, $writtenReviewed, ContentImports::item($this->author));
    upcUpdate($draft, $writtenDraft, ContentImports::item($this->author, ['status' => 'review', 'publishedAt' => null, 'slug' => 'harbour-draft']));

    expect(ContentReview::query()->where('content_id', $reviewed->id)->value('decision'))->toBe('published')
        ->and(ContentReview::query()->where('content_id', $draft->id)->whereNull('decided_at')->exists())->toBeTrue()
        ->and($draft->refresh()->status)->toBe('review');
});

it('refuses what it can’t do, writing nothing', function (string $case, string $key): void {
    [$content, $written] = upcImported($this->author);
    $item = ContentImports::item($this->author, ['title' => 'WordPress’s new title']);

    if ($case === 'an item deleted here') {
        $content->forceDelete();
    }

    $record = $case === 'a record it can’t read' ? 'not a fingerprint' : $written;
    $item = $case === 'another type' ? ContentImports::item($this->author, ['type' => 'page']) : $item;
    $revisions = ContentRevision::query()->count();

    try {
        upcUpdate($content, $record, $item);
        $this->fail('The item was updated.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toContain($key);
    }

    expect(ContentRevision::query()->count())->toBe($revisions);
})->with([
    ['an item deleted here', 'content'],
    ['a record it can’t read', 'written'],
    ['another type', 'type'],
]);

it('writes nothing when the actor may not import', function (string $who): void {
    [$content, $written] = upcImported($this->author);
    $before = DB::table('contents')->where('id', $content->id)->first();

    expect(fn () => upcUpdate($content, $written, ContentImports::item($this->author, ['title' => 'WordPress’s new title']), $who === 'an editor' ? $this->editor : null, shell: false))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('contents')->where('id', $content->id)->first())->toEqual($before)
        ->and(ContentRevision::query()->where('content_id', $content->id)->count())->toBe(1);
})->with(['an editor', 'no one, away from the operator’s shell']);

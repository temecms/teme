<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->news = taxonomyTerm('category', 'News');
    $this->sport = taxonomyTerm('category', 'Sport');
    $this->tag = taxonomyTerm('tag', 'Football');
});

/**
 * @param  list<string>  $terms
 */
function taxonomyDraftWith(User $author, array $terms): Content
{
    return app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Tagged',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $author->id,
        terms: $terms,
    ));
}

/**
 * @return list<string> term IDs in position order
 */
function taxonomyRows(Content $content, string $scope): array
{
    /** @var list<string> */
    return DB::table('content_terms')
        ->where('content_id', $content->id)
        ->where('scope', $scope)
        ->orderBy('position')
        ->pluck('term_id')
        ->all();
}

function taxonomyPublish(Content $content, User $actor): Content
{
    $content->refresh();

    return app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $content->working_revision_id, (string) $actor->id));
}

it('stores the assignment in the revision, in the editor\'s order', function (): void {
    $content = taxonomyDraftWith($this->author, [$this->sport->id, $this->news->id, $this->tag->id]);

    expect($content->workingRevision->terms)->toBe([$this->sport->id, $this->news->id, $this->tag->id])
        ->and(taxonomyRows($content, 'working'))->toBe([$this->sport->id, $this->news->id, $this->tag->id])
        ->and(taxonomyRows($content, 'published'))->toBe([]);
});

it('counts a term only once the post is published', function (): void {
    $content = taxonomyDraftWith($this->author, [$this->news->id]);

    expect($this->news->fresh()->published_count)->toBe(0);

    taxonomyPublish($content, $this->author);

    expect($this->news->fresh()->published_count)->toBe(1)
        ->and(taxonomyRows($content, 'published'))->toBe([$this->news->id]);
});

it('does not change what is live when a published post\'s categories are edited', function (): void {
    $content = taxonomyPublish(taxonomyDraftWith($this->author, [$this->news->id]), $this->author);

    app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $content->id,
        title: 'Tagged',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
        terms: [$this->sport->id],
    ));

    expect(taxonomyRows($content, 'published'))->toBe([$this->news->id])
        ->and(taxonomyRows($content, 'working'))->toBe([$this->sport->id])
        ->and($this->news->fresh()->published_count)->toBe(1)
        ->and($this->sport->fresh()->published_count)->toBe(0);

    taxonomyPublish($content, $this->author);

    expect(taxonomyRows($content, 'published'))->toBe([$this->sport->id])
        ->and($this->news->fresh()->published_count)->toBe(0)
        ->and($this->sport->fresh()->published_count)->toBe(1);
});

it('takes a trashed or unpublished post out of the counts, and a restore puts it back', function (): void {
    $content = taxonomyPublish(taxonomyDraftWith($this->author, [$this->news->id]), $this->author);
    $act = fn (string $operation) => app($operation)->execute(new ContentActionInput($content->id, (string) $this->author->id));

    $act(TrashContent::class);
    expect($this->news->fresh()->published_count)->toBe(0)
        ->and(taxonomyRows($content, 'working'))->toBe([]);

    $act(RestoreContent::class);
    expect($this->news->fresh()->published_count)->toBe(1)
        ->and(taxonomyRows($content, 'working'))->toBe([$this->news->id]);

    $act(UnpublishContent::class);
    expect($this->news->fresh()->published_count)->toBe(0)
        ->and(taxonomyRows($content, 'published'))->toBe([]);
});

it('drops a term that no longer exists', function (): void {
    $content = taxonomyDraftWith($this->author, [$this->news->id, $this->sport->id]);
    $this->news->delete();

    app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $content->id,
        title: 'Tagged',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
        terms: $content->workingRevision->terms,
    ));

    expect(taxonomyRows($content, 'working'))->toBe([$this->sport->id]);
});

it('refuses an unknown term ID', function (): void {
    expect(fn () => taxonomyDraftWith($this->author, ['01JZZZZZZZZZZZZZZZZZZZZZZZ']))
        ->toThrow(ValidationException::class);
});

it('counts every live item a term is on, published or not', function (): void {
    $content = taxonomyDraftWith($this->author, [$this->news->id]);
    taxonomyDraftWith($this->author, [$this->news->id, $this->sport->id]);

    expect($this->news->fresh()->working_count)->toBe(2)
        ->and($this->sport->fresh()->working_count)->toBe(1);

    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));

    expect($this->news->fresh()->working_count)->toBe(1);
});

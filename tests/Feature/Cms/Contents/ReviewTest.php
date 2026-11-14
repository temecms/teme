<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\ApproveReview;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\ReviewDecisionInput;
use App\Domain\Contents\Operations\ReturnForChanges;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Queries\EditorSession;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    // Contributors submit: they hold content.create, content.edit.own and
    // content.delete.own, and not content.publish. Authors publish their own.
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('submits the revision as it stands, so later edits do not change what is being reviewed', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    docSave($content, $this->contributor, docWith(docText('The version I submitted.')));
    $submitted = $content->refresh()->working_revision_id;

    schedSubmit($content->refresh(), $this->contributor);
    docSave($content->refresh(), $this->contributor, docWith(docText('Something I changed after.')));

    expect(DB::table('content_reviews')->where('content_id', $content->id)->value('revision_id'))->toBe($submitted);
});

it('puts a new article in review, and keeps a live one published while its update waits', function (): void {
    $new = lifecycleDraft($this->contributor, 'Night market');
    schedSubmit($new, $this->contributor);

    $live = lifecycleDraft($this->contributor, 'Harbour festival');
    listsPublish($live, $this->editor);
    docSave($live->refresh(), $this->contributor, docWith(docText('A correction.')));
    $reviewId = schedSubmit($live, $this->contributor);

    expect($new->refresh()->status)->toBe('review')
        ->and($live->refresh()->status)->toBe('published')
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('revision_id'))->toBe($live->working_revision_id)
        ->and((int) DB::table('content_counts')->where('type', 'post')->where('bucket', 'review')->where('author_id', 0)->sum('count'))->toBe(1);
});

it('lets an editor approve, which publishes the pinned revision', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    docSave($content, $this->contributor, docWith(docText('The version I submitted.')));
    $submitted = $content->refresh()->working_revision_id;
    $reviewId = schedSubmit($content, $this->contributor);
    docSave($content->refresh(), $this->contributor, docWith(docText('Something I changed after.')));

    schedApprove($reviewId, $this->editor);

    $review = DB::table('content_reviews')->where('id', $reviewId)->first();

    expect($content->refresh()->status)->toBe('published')
        ->and($content->published_revision_id)->toBe($submitted)
        ->and($content->working_revision_id)->not->toBe($submitted)
        ->and($review->decision)->toBe('approved')
        ->and($review->decided_by)->toBe($this->editor->id);
});

it('lets an editor return it with a reason, which the writer sees', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    $reviewId = schedSubmit($content, $this->contributor);

    schedReturn($reviewId, $this->editor, 'Needs a source for the second paragraph.');

    $session = EditorSession::for($content->id, (string) $this->contributor->id);

    expect($content->refresh()->status)->toBe('draft')
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('decision'))->toBe('returned')
        ->and($session->review['state'])->toBe('returned')
        ->and($session->review['note'])->toBe('Needs a source for the second paragraph.');
});

it('requires a reason when returning, and writes nothing without one', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    $reviewId = schedSubmit($content, $this->contributor);

    expect(fn () => app(ReturnForChanges::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->editor->id, '   ')))
        ->toThrow(ValidationException::class)
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('decided_at'))->toBeNull()
        ->and($content->refresh()->status)->toBe('review');
});

it('refuses a submission from someone who may not edit the article, and writes nothing', function (): void {
    $stranger = User::factory()->create()->assignRole('contributor');
    $content = lifecycleDraft($this->contributor, 'Night market');

    expect(fn () => schedSubmit($content, $stranger))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_reviews')->count())->toBe(0)
        ->and($content->refresh()->status)->toBe('draft');
});

it('closes a pending review when the article is published by hand', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    $reviewId = schedSubmit($content, $this->contributor);

    listsPublish($content, $this->editor);

    $review = DB::table('content_reviews')->where('id', $reviewId)->first();

    expect($content->refresh()->status)->toBe('published')
        ->and($review->decision)->toBe('published')
        ->and($review->decided_by)->toBe($this->editor->id)
        ->and($review->decided_at)->not->toBeNull();
});

it('withdraws a pending review when the article is trashed, and returns it to draft', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    $reviewId = schedSubmit($content, $this->contributor);

    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->contributor->id));

    expect(DB::table('content_reviews')->where('id', $reviewId)->value('decision'))->toBe('withdrawn')
        ->and(DB::table('contents')->where('id', $content->id)->value('status'))->toBe('draft');
});

it('refuses a decision from someone who may not publish, and writes nothing', function (): void {
    $content = lifecycleDraft($this->contributor, 'Night market');
    $reviewId = schedSubmit($content, $this->contributor);

    expect(fn () => app(ApproveReview::class)->execute(new ReviewDecisionInput($reviewId, (string) $this->contributor->id)))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('content_reviews')->where('id', $reviewId)->value('decided_at'))->toBeNull()
        ->and($content->refresh()->status)->toBe('review');
});

it('keeps the history of decisions rather than overwriting one row', function (): void {
    // Submitted, returned, submitted again, approved: four facts, not one.
    $content = lifecycleDraft($this->contributor, 'Night market');
    $first = schedSubmit($content, $this->contributor);
    schedReturn($first, $this->editor, 'Add a photo credit.');
    $this->travel(1)->minutes();
    $second = schedSubmit($content, $this->contributor);
    schedApprove($second, $this->editor);

    $rows = DB::table('content_reviews')->where('content_id', $content->id)->orderBy('submitted_at')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('id')->all())->toBe([$first, $second])
        ->and($rows->pluck('decision')->all())->toBe(['returned', 'approved'])
        ->and($rows[0]->note)->toBe('Add a photo credit.')
        ->and($rows->whereNotNull('decided_at'))->toHaveCount(2);
});

it('offers Submit for review to a contributor, and Publish to whoever may publish the item', function (): void {
    $author = User::factory()->create()->assignRole('author');
    $theirs = lifecycleDraft($this->contributor, 'Night market');
    $own = lifecycleDraft($author, 'Harbour festival');

    expect(EditorSession::for($theirs->id, (string) $this->contributor->id)->can['publish'])->toBeFalse()
        ->and(EditorSession::for($theirs->id, (string) $this->editor->id)->can['publish'])->toBeTrue()
        // An author publishes their own draft, so is not offered Submit for review.
        ->and(EditorSession::for($own->id, (string) $author->id)->can['publish'])->toBeTrue();
});

it('counts new items under In review, and marks a live article whose update is in review', function (): void {
    $new = lifecycleDraft($this->contributor, 'Night market');
    schedSubmit($new, $this->contributor);
    $live = lifecycleDraft($this->contributor, 'Harbour festival');
    listsPublish($live, $this->editor);
    docSave($live->refresh(), $this->contributor, docWith(docText('A correction.')));
    schedSubmit($live, $this->contributor);

    $this->actingAs($this->editor)->get('/admin/posts?tab=review')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tabs', fn ($tabs) => $tabs->firstWhere('key', 'review')['count'] === 1)
            ->has('rows', 1)
            ->where('rows.0.id', $new->id)
            ->where('rows.0.status', 'review'));

    $this->actingAs($this->editor)->get('/admin/posts?tab=published')
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.id', $live->id)
            ->where('rows.0.review.canDecide', true));

    $this->actingAs($this->contributor)->get('/admin/posts?tab=published')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.review.canDecide', false));
});

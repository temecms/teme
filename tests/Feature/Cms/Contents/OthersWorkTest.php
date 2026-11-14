<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Queries\ContentList;
use App\Domain\Contents\Queries\ContentListRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->otherContributor = User::factory()->create()->assignRole('contributor');

    $this->theirDraft = lifecycleDraft($this->otherContributor, 'Their draft');
    $this->myDraft = lifecycleDraft($this->contributor, 'My draft');
});

it('lists other people\'s drafts by title, read-only', function (): void {
    $rows = collect(app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $this->contributor->id))['rows'])->keyBy('title');

    expect($rows)->toHaveKeys(['Their draft', 'My draft'])
        ->and($rows['Their draft']['canEdit'])->toBeFalse()
        ->and($rows['My draft']['canEdit'])->toBeTrue();
});

it('agrees with the policy on every row, for every role', function (string $role): void {
    $viewer = User::factory()->create()->assignRole($role);
    $rows = app(ContentList::class)->page(new ContentListRequest(type: 'post', viewerId: $viewer->id))['rows'];

    foreach ($rows as $row) {
        expect($row['canEdit'])->toBe($viewer->can('update', Content::findOrFail($row['id'])));
    }
})->with(['administrator', 'editor', 'author', 'contributor']);

it('refuses to open someone else\'s draft in the editor, and says why', function (): void {
    $this->actingAs($this->contributor)
        ->get(route('content.edit', ['content' => $this->theirDraft->id]))
        ->assertForbidden()
        ->assertSee("You can't edit this item.");

    $this->actingAs($this->editor)
        ->get(route('content.edit', ['content' => $this->theirDraft->id]))
        ->assertOk();
});

it('refuses every other way of opening someone else\'s draft', function (string $route): void {
    $id = $this->theirDraft->id;

    $response = match ($route) {
        'content.revisions.index' => $this->actingAs($this->contributor)->getJson(route($route, ['content' => $id])),
        'content.compare' => $this->actingAs($this->contributor)->get(route($route, ['content' => $id])),
        'content.preview-links.index' => $this->actingAs($this->contributor)->getJson(route($route, ['content' => $id])),
        'content.preview-links.store' => $this->actingAs($this->contributor)->postJson(route($route, ['content' => $id])),
        'content.details' => $this->actingAs($this->contributor)->patch(route($route, ['type' => 'posts', 'content' => $id]), [
            'title' => 'Mine now',
            'expected_revision_id' => $this->theirDraft->working_revision_id,
        ]),
    };

    $response->assertForbidden();

    expect($this->theirDraft->refresh()->title)->toBe('Their draft')
        ->and(DB::table('preview_links')->where('content_id', $id)->count())->toBe(0);
})->with(['content.revisions.index', 'content.compare', 'content.preview-links.index', 'content.preview-links.store', 'content.details']);

it('refuses to submit, schedule or decide on someone else\'s item, and writes nothing', function (string $route): void {
    // An author may publish their own work, so this proves the refusal is
    // about the item, not about the ability.
    $author = User::factory()->create()->assignRole('author');
    $reviewId = schedSubmit($this->theirDraft, $this->otherContributor);
    $item = $this->theirDraft->refresh();
    $id = $item->id;

    $response = match ($route) {
        'content.review.store' => $this->actingAs($author)->postJson(route($route, ['content' => $id]), [
            'revision_id' => $item->working_revision_id,
        ]),
        'content.schedule.store' => $this->actingAs($author)->postJson(route($route, ['content' => $id]), [
            'revision_id' => $item->working_revision_id,
            'action' => 'publish',
            'run_at' => now()->addDay()->toIso8601String(),
        ]),
        'content.schedule.destroy' => $this->actingAs($author)->deleteJson(route($route, ['content' => $id])),
        'content.review.approve' => $this->actingAs($author)->postJson(route($route, ['content' => $id]), [
            'review_id' => $reviewId,
        ]),
        'content.review.send-back' => $this->actingAs($author)->postJson(route($route, ['content' => $id]), [
            'review_id' => $reviewId,
            'note' => 'Not mine to judge.',
        ]),
    };

    $response->assertForbidden();

    expect($item->refresh()->status)->toBe('review')
        ->and(DB::table('content_reviews')->where('content_id', $id)->count())->toBe(1)
        ->and(DB::table('content_reviews')->where('content_id', $id)->whereNull('decided_at')->count())->toBe(1)
        ->and(DB::table('scheduled_publications')->where('content_id', $id)->count())->toBe(0);
})->with(['content.review.store', 'content.schedule.store', 'content.schedule.destroy', 'content.review.approve', 'content.review.send-back']);

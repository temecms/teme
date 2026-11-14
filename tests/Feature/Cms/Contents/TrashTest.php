<?php

declare(strict_types=1);

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

function lifecycleAct(string $operation, Content $content, User $actor): mixed
{
    return app($operation)->execute(new ContentActionInput($content->id, (string) $actor->id));
}

function lifecyclePublish(Content $content, User $actor): void
{
    app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $content->working_revision_id, (string) $actor->id));
}

it('trashes an item, keeps its status, and counts it as trash', function (): void {
    $content = lifecycleDraft($this->author, 'About');
    lifecyclePublish($content, $this->author);

    lifecycleAct(TrashContent::class, $content, $this->author);

    $trashed = Content::withTrashed()->findOrFail($content->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->status)->toBe('published')
        ->and(ContentCounts::for('post'))->toBe(['trash' => 1])
        ->and(ContentCounts::for('post'))->toBe(lifecycleTruth('post'));
});

it('frees the slug of a trashed item for new content', function (): void {
    $first = lifecycleDraft($this->author, 'About');
    lifecycleAct(TrashContent::class, $first, $this->author);

    expect(lifecycleDraft($this->author, 'About')->slug)->toBe('about');
});

it('restores an item exactly as it was', function (): void {
    $content = lifecycleDraft($this->author, 'About');
    lifecyclePublish($content, $this->author);
    lifecycleAct(TrashContent::class, $content, $this->author);

    $result = lifecycleAct(RestoreContent::class, $content, $this->author);

    expect($result->slug)->toBe('about')
        ->and($result->previousSlug)->toBeNull()
        ->and(Content::findOrFail($content->id)->status)->toBe('published')
        ->and(ContentCounts::for('post'))->toBe(['published' => 1]);
});

it('restores into a taken slug with a suffix, and says so', function (): void {
    $old = lifecycleDraft($this->author, 'About');
    lifecycleAct(TrashContent::class, $old, $this->author);
    lifecycleDraft($this->author, 'About');

    $result = lifecycleAct(RestoreContent::class, $old, $this->author);

    expect($result->slug)->toBe('about-2')
        ->and($result->previousSlug)->toBe('about')
        ->and(Content::findOrFail($old->id)->slug)->toBe('about-2');
});

it('unpublishes to a draft', function (): void {
    $content = lifecycleDraft($this->author);
    lifecyclePublish($content, $this->author);

    lifecycleAct(UnpublishContent::class, $content, $this->author);

    expect(Content::findOrFail($content->id)->status)->toBe('draft')
        ->and(ContentCounts::for('post'))->toBe(['draft' => 1]);
});

it('deletes a trashed item for good, with its revisions', function (): void {
    $content = lifecycleDraft($this->author);
    lifecycleAct(TrashContent::class, $content, $this->author);

    lifecycleAct(DeleteContentPermanently::class, $content, $this->author);

    expect(Content::withTrashed()->find($content->id))->toBeNull()
        ->and(ContentRevision::query()->where('content_id', $content->id)->exists())->toBeFalse()
        ->and(ContentCounts::for('post'))->toBe([]);
});

it('refuses to delete for good an item that is not in the trash', function (): void {
    $content = lifecycleDraft($this->author);

    expect(fn () => lifecycleAct(DeleteContentPermanently::class, $content, $this->author))
        ->toThrow(ValidationException::class);
});

it('refuses to unpublish an item that is not published', function (): void {
    expect(fn () => lifecycleAct(UnpublishContent::class, lifecycleDraft($this->author), $this->author))
        ->toThrow(ValidationException::class);
});

it('writes nothing when someone may not remove the item', function (string $operation): void {
    $content = lifecycleDraft($this->author);
    lifecyclePublish($content, $this->author);
    lifecycleAct(TrashContent::class, $content, $this->author);

    if ($operation !== RestoreContent::class && $operation !== DeleteContentPermanently::class) {
        lifecycleAct(RestoreContent::class, $content, $this->author);
    }

    $stranger = User::factory()->create()->assignRole('author');
    $before = Content::withTrashed()->findOrFail($content->id)->only(['status', 'deleted_at', 'slug']);
    $counts = ContentCounts::for('post');

    expect(fn () => lifecycleAct($operation, $content, $stranger))->toThrow(AuthorizationException::class);

    expect(Content::withTrashed()->findOrFail($content->id)->only(['status', 'deleted_at', 'slug']))->toEqual($before)
        ->and(ContentCounts::for('post'))->toBe($counts);
})->with([TrashContent::class, RestoreContent::class, DeleteContentPermanently::class, UnpublishContent::class]);

it('lets an editor remove anyone\'s content', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $content = lifecycleDraft($this->author);

    lifecycleAct(TrashContent::class, $content, $editor);

    expect(Content::withTrashed()->findOrFail($content->id)->trashed())->toBeTrue();
});

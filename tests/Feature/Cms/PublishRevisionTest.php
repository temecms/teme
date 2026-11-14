<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\BuiltInFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->author = User::factory()->create()->assignRole('author');

    $this->content = app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Ready to publish',
        document: ['type' => 'doc', 'content' => []],
        fields: [BuiltInFields::SEO_TITLE => 'Ready to publish'],
        actorId: (string) $this->author->id,
    ));
});

it('moves the published pointer and stamps published_at', function (): void {
    $revisionId = $this->content->working_revision_id;

    $published = app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $this->content->id,
        revisionId: $revisionId,
        actorId: (string) $this->author->id,
    ));

    expect($published->status)->toBe('published')
        ->and($published->published_revision_id)->toBe($revisionId)
        ->and($published->published_at)->not->toBeNull();
});

it('projects the published revision fields onto the content row', function (): void {
    $published = app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $this->content->id,
        revisionId: $this->content->working_revision_id,
        actorId: (string) $this->author->id,
    ));

    expect($published->fields)->toBe([BuiltInFields::SEO_TITLE => 'Ready to publish']);
});

it('publishes a later revision without destroying the earlier one', function (): void {
    $first = $this->content->working_revision_id;

    app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $this->content->id,
        revisionId: $first,
        actorId: (string) $this->author->id,
    ));

    $edited = app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $this->content->id,
        title: 'Second version',
        document: ['type' => 'doc', 'content' => []],
        fields: [BuiltInFields::SEO_TITLE => 'Second version'],
        expectedRevisionId: $first,
        actorId: (string) $this->author->id,
    ));

    $republished = app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $this->content->id,
        revisionId: $edited->working_revision_id,
        actorId: (string) $this->author->id,
    ));

    expect($republished->publishedRevision->title)->toBe('Second version')
        ->and($republished->fields)->toBe([BuiltInFields::SEO_TITLE => 'Second version'])
        ->and($republished->revisions()->count())->toBe(2);
});

it('refuses a contributor without content.publish', function (): void {
    $contributor = User::factory()->create()->assignRole('contributor');

    $theirContent = app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Contributor draft',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $contributor->id,
    ));

    expect(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $theirContent->id,
        revisionId: $theirContent->working_revision_id,
        actorId: (string) $contributor->id,
    )))->toThrow(AuthorizationException::class);

    expect($theirContent->fresh()->status)->toBe('draft');
});

it('refuses to publish a revision belonging to different content', function (): void {
    $other = app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'post',
        title: 'Someone else',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $this->author->id,
    ));

    expect(fn () => app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $this->content->id,
        revisionId: $other->working_revision_id,
        actorId: (string) $this->author->id,
    )))->toThrow(ValidationException::class);
});

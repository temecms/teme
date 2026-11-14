<?php

declare(strict_types=1);

use App\Domain\Contents\ContentCounts;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
});

it('counts a new draft for everyone and for its author', function (): void {
    lifecycleDraft($this->author);
    lifecycleDraft($this->author, 'Another');

    expect(ContentCounts::for('post'))->toBe(['draft' => 2])
        ->and(ContentCounts::for('post', $this->author->id))->toBe(['draft' => 2])
        ->and(ContentCounts::for('page'))->toBe([]);
});

it('moves a count from draft to published on publish', function (): void {
    $content = lifecycleDraft($this->author);
    lifecycleDraft($this->author, 'Stays a draft');

    app(PublishRevision::class)->execute(new PublishRevisionInput(
        contentId: $content->id,
        revisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
    ));

    expect(ContentCounts::for('post'))->toBe(['draft' => 1, 'published' => 1])
        ->and(ContentCounts::for('post'))->toBe(lifecycleTruth('post'));
});

it('does not count a republish twice', function (): void {
    $content = lifecycleDraft($this->author);
    $publish = fn () => app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, $content->working_revision_id, (string) $this->author->id));

    $publish();
    $publish();

    expect(ContentCounts::for('post'))->toBe(['published' => 1]);
});

it('projects the working title onto contents', function (): void {
    $content = lifecycleDraft($this->author, 'First title');

    $updated = app(SaveDraft::class)->execute(new SaveDraftInput(
        contentId: $content->id,
        title: '  Second title  ',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        expectedRevisionId: $content->working_revision_id,
        actorId: (string) $this->author->id,
    ));

    expect($content->title)->toBe('First title')
        ->and($updated->title)->toBe('Second title');
});

<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a ulid primary key', function (): void {
    $content = Content::factory()->create();

    expect($content->id)->toBeString()->toHaveLength(26);
});

it('casts jsonb columns to arrays', function (): void {
    $content = Content::factory()->create(['fields' => ['seo_title' => 'Hello']]);

    expect($content->fresh()->fields)->toBe(['seo_title' => 'Hello']);
});

it('resolves published and working revisions independently', function (): void {
    $content = Content::factory()->create();

    $published = ContentRevision::create([
        'content_id' => $content->id,
        'document' => ['type' => 'doc', 'content' => []],
        'fields' => [],
        'title' => 'Published title',
        'origin' => 'human',
    ]);

    $working = ContentRevision::create([
        'content_id' => $content->id,
        'document' => ['type' => 'doc', 'content' => []],
        'fields' => [],
        'title' => 'Working title',
        'origin' => 'human',
    ]);

    $content->update([
        'published_revision_id' => $published->id,
        'working_revision_id' => $working->id,
    ]);

    $content->refresh();

    expect($content->publishedRevision->title)->toBe('Published title')
        ->and($content->workingRevision->title)->toBe('Working title')
        ->and($content->revisions)->toHaveCount(2);
});

it('deletes revisions when the content is force deleted', function (): void {
    $content = Content::factory()->create();

    ContentRevision::create([
        'content_id' => $content->id,
        'document' => ['type' => 'doc', 'content' => []],
        'fields' => [],
        'title' => 'Only revision',
        'origin' => 'human',
    ]);

    $content->update(['published_revision_id' => null, 'working_revision_id' => null]);
    $content->forceDelete();

    expect(ContentRevision::count())->toBe(0);
});

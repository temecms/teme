<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\CancelScheduledPublication;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->contributor = User::factory()->create()->assignRole('contributor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('schedules a draft to publish, without publishing it now', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('Ready to go.')));

    schedFor($content, $this->editor, now()->addDay()->toIso8601String());

    expect($content->refresh()->status)->toBe('scheduled')
        ->and($content->refresh()->published_revision_id)->toBeNull();
});

it('schedules an update to a live article, leaving what is published alone', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    docSave($content, $this->author, docWith(docText('The published text.')));
    listsPublish($content->refresh(), $this->editor);
    $published = $content->refresh()->published_revision_id;
    docSave($content->refresh(), $this->author, docWith(docText('The update, for Friday.')));

    schedFor($content, $this->editor, now()->addDay()->toIso8601String(), 'update');

    expect($content->refresh()->published_revision_id)->toBe($published)
        ->and($content->refresh()->status)->toBe('published');
});

it('refuses a time in the past', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    expect(fn () => schedFor($content, $this->editor, now()->subHour()->toIso8601String()))
        ->toThrow(ValidationException::class);
});

it('keeps one pending schedule per article, replacing an earlier one', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');

    schedFor($content, $this->editor, now()->addDay()->toIso8601String());
    schedFor($content, $this->editor, now()->addDays(2)->toIso8601String());

    $pending = DB::table('scheduled_publications')->where('content_id', $content->id)
        ->whereNull('completed_at')->whereNull('failed_at')->whereNull('cancelled_at');

    expect($pending->count())->toBe(1)
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->where('cancel_reason', 'replaced')->count())->toBe(1);
});

it('cancels a schedule and returns the article to its previous state', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedFor($content, $this->editor, now()->addDay()->toIso8601String());

    app(CancelScheduledPublication::class)->execute(new ContentActionInput($content->id, (string) $this->editor->id));

    expect($content->refresh()->status)->toBe('draft')
        ->and($content->refresh()->scheduled_for)->toBeNull()
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->value('cancel_reason'))->toBe('cancelled');
});

it('cancels the schedule when the article is published by hand', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedFor($content, $this->editor, now()->addDay()->toIso8601String());

    listsPublish($content->refresh(), $this->editor);

    expect($content->refresh()->status)->toBe('published')
        ->and($content->refresh()->scheduled_for)->toBeNull()
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->value('cancel_reason'))->toBe('published')
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->value('cancelled_at'))->not->toBeNull();
});

it('cancels the schedule when the article is trashed, and returns it to draft', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    schedFor($content, $this->editor, now()->addDay()->toIso8601String());

    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->editor->id));

    expect($content->refresh()->status)->toBe('draft')
        ->and($content->refresh()->scheduled_for)->toBeNull()
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->value('cancel_reason'))->toBe('trashed');
});

it('cancels a scheduled update when the article is unpublished', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    listsPublish($content, $this->editor);
    docSave($content->refresh(), $this->author, docWith(docText('The update, for Friday.')));
    schedFor($content, $this->editor, now()->addDay()->toIso8601String(), 'update');

    app(UnpublishContent::class)->execute(new ContentActionInput($content->id, (string) $this->editor->id));

    expect($content->refresh()->status)->toBe('draft')
        ->and($content->refresh()->scheduled_for)->toBeNull()
        ->and(DB::table('scheduled_publications')->where('content_id', $content->id)->value('cancel_reason'))->toBe('unpublished');
});

it('keeps contents.scheduled_for equal to the pending schedule', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour festival');
    $when = now()->addDay()->startOfMinute();

    schedFor($content, $this->editor, $when->toIso8601String());

    expect($content->refresh()->scheduled_for->equalTo($when))->toBeTrue();
});

it('writes nothing when the actor may not publish', function (): void {
    $content = lifecycleDraft($this->contributor, 'Harbour festival');

    expect(fn () => schedFor($content, $this->contributor, now()->addDay()->toIso8601String()))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('scheduled_publications')->count())->toBe(0)
        ->and($content->refresh()->status)->toBe('draft');
});

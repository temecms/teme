<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\Input\TranslateContentInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Contents\Operations\TranslateContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Contents\RestoredContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
    $this->folder = cacheOn();
});

afterEach(fn () => cacheOff($this->folder));

function glAct(string $operation, string $id, User $actor): mixed
{
    return app($operation)->execute(new ContentActionInput($id, (string) $actor->id));
}

function glTranslate(string $id, string $language, User $actor): Content
{
    return app(TranslateContent::class)->execute(new TranslateContentInput($id, $language, (string) $actor->id))->content;
}

it('purges the group\'s pages when a version is published, scheduled to go live, unpublished, trashed, made private or deleted for good', function (string $change): void {
    $site = $this->site;
    $editor = $site->editor;

    [$group, $act] = match ($change) {
        'published' => [$site->pageGroup, fn () => listsPublish(Content::query()->findOrFail($site->pageFr), $editor)],
        'scheduled' => (function () use ($site, $editor): array {
            $row = schedDue(Content::query()->findOrFail($site->pageFr), $editor);

            return [$site->pageGroup, fn () => app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput($row, (string) $editor->id))];
        })(),
        'unpublished' => [$site->postGroup, fn () => glAct(UnpublishContent::class, $site->postFr, $editor)],
        'trashed' => [$site->postGroup, fn () => pageTrash(Content::query()->findOrFail($site->postFr), $editor)],
        'made private' => [$site->postGroup, fn () => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($site->postFr, 'private', null, (string) $editor->id))],
        'deleted for good' => (function () use ($site, $editor): array {
            pageTrash(Content::query()->findOrFail($site->postFr), $editor);

            return [$site->postGroup, fn () => glAct(DeleteContentPermanently::class, $site->postFr, $editor)];
        })(),
    };

    cacheShown(['/shows-the-group/' => ['translations:'.$group], '/shows-another/' => ['translations:another']]);

    DB::transaction(function () use ($act): void {
        $act();

        expect(cacheKept('/shows-the-group/'))->toBe(['/shows-the-group/']);
    });

    expect(cacheKept('/shows-the-group/', '/shows-another/'))->toBe(['/shows-another/']);
})->with(['published', 'scheduled', 'unpublished', 'trashed', 'made private', 'deleted for good']);

it('restores a trashed version into its group while its language is free', function (): void {
    pageTrash(Content::query()->findOrFail($this->site->postEs), $this->site->editor);
    cacheShown(['/shows-the-group/' => ['translations:'.$this->site->postGroup]]);

    $result = glAct(RestoreContent::class, $this->site->postEs, $this->site->editor);

    expect($result)->toBeInstanceOf(RestoredContent::class)
        ->and($result->leftGroup)->toBeFalse()
        ->and(DB::table('contents')->where('id', $this->site->postEs)->value('translation_group'))->toBe($this->site->postGroup)
        ->and(cacheKept('/shows-the-group/'))->toBe([]);
});

it('restores a trashed version outside its group when its language is taken, and says so', function (): void {
    pageTrash(Content::query()->findOrFail($this->site->postFr), $this->site->editor);
    $other = glTranslate($this->site->post, 'fr', $this->site->editor);
    cacheShown(['/shows-the-group/' => ['translations:'.$this->site->postGroup]]);

    $this->actingAs($this->site->editor)
        ->from('/admin/posts')
        ->post(route('content.action', ['type' => 'posts', 'content' => $this->site->postFr, 'action' => 'restore']))
        ->assertInertiaFlash('toast.message', 'Restored. Another French version exists, so this one is no longer linked to its translations.');

    $restored = Content::query()->findOrFail($this->site->postFr);

    expect($restored->translation_group)->toBeNull()
        ->and($restored->language)->toBe('fr')
        ->and($other->refresh()->translation_group)->toBe($this->site->postGroup)
        ->and(cacheKept('/shows-the-group/'))->toBe([]);

    // The result says so too, for a caller other than the screen.
    pageTrash($restored, $this->site->editor);
    DB::table('contents')->where('id', $restored->id)->update(['translation_group' => $this->site->postGroup]);
    $result = glAct(RestoreContent::class, $restored->id, $this->site->editor);

    expect([$result->leftGroup, $result->languageLabel])->toBe([true, 'French']);
});

it('keeps the other versions linked when one is deleted for good, and forgets the revision they came from', function (): void {
    $french = glTranslate($this->site->alone, 'fr', $this->site->editor);
    $spanish = glTranslate($this->site->alone, 'es', $this->site->editor);
    $group = (string) $french->translation_group;
    expect(ContentRevision::query()->findOrFail($french->working_revision_id)->source_revision_id)->not->toBeNull();

    pageTrash(Content::query()->findOrFail($this->site->alone), $this->site->editor);
    glAct(DeleteContentPermanently::class, $this->site->alone, $this->site->editor);

    expect(DB::table('contents')->whereIn('id', [$french->id, $spanish->id])->pluck('translation_group')->unique()->values()->all())->toBe([$group])
        ->and(DB::table('content_revisions')->whereIn('content_id', [$french->id, $spanish->id])->whereNotNull('source_revision_id')->count())->toBe(0);
});

it('changes nothing for an item with no group', function (): void {
    cacheShown(['/alone/' => ['content:'.$this->site->alone], '/shows-the-group/' => ['translations:'.$this->site->postGroup]]);

    pageTrash(Content::query()->findOrFail($this->site->alone), $this->site->editor);
    expect(cacheKept('/alone/', '/shows-the-group/'))->toBe(['/shows-the-group/']);

    $result = glAct(RestoreContent::class, $this->site->alone, $this->site->editor);

    expect($result->leftGroup)->toBeFalse()
        ->and(DB::table('contents')->where('id', $this->site->alone)->value('translation_group'))->toBeNull()
        ->and(cacheKept('/shows-the-group/'))->toBe(['/shows-the-group/']);
});

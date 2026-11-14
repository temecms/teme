<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\ScheduledPublicationInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\RunScheduledPublication;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentVisibilityChanged;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->author = User::factory()->create()->assignRole('author');
    $this->nobody = User::factory()->create();
    $this->act = fn (string $operation, Content $item, User $by): mixed => app($operation)->execute(new ContentActionInput($item->id, (string) $by->id));
});

it('sends ContentSaved when a draft is created and each time it is saved', function (): void {
    $heard = hkHear(ContentSaved::class);

    $draft = lifecycleDraft($this->author, 'Harbour news');
    $saved = docSave($draft, $this->author, docWith(docText('The ferry is late.')));

    expect($heard)->toHaveCount(2)
        ->and([$heard[0]->created, $heard[1]->created])->toBe([true, false])
        ->and([$heard[0]->revisionId, $heard[1]->revisionId])->toBe([(string) $draft->working_revision_id, (string) $saved->working_revision_id])
        ->and($heard[0]->revisionId)->not->toBe($heard[1]->revisionId)
        ->and($heard[1]->item->id)->toBe($draft->id)
        ->and($heard[1]->item->type)->toBe($draft->type)
        ->and($heard[1]->item->title)->toBe('Harbour news')
        ->and($heard[1]->item->status)->toBe('draft')
        ->and($heard[1]->item->url)->toBeNull()
        ->and($heard[1]->actorId)->toBe((string) $this->author->id);
});

it('sends ContentPublished with the item’s address, its revision and whether it is the first time', function (): void {
    $item = lifecycleDraft($this->author, 'Harbour news');
    $heard = hkHear(ContentPublished::class);

    listsPublish($item, $this->editor);
    ($this->act)(UnpublishContent::class, $item, $this->editor);
    $this->travel(1)->minutes();
    listsPublish($item, $this->editor);
    $item->refresh();

    expect($heard)->toHaveCount(2)
        ->and([$heard[0]->first, $heard[1]->first])->toBe([true, false])
        ->and($heard[1]->revisionId)->toBe((string) $item->published_revision_id)
        ->and($heard[1]->item->status)->toBe('published')
        ->and($heard[1]->item->url)->not->toBeNull()
        ->and($heard[1]->item->url)->toBe(Addresses::urlFor($item))
        ->and($heard[1]->actorId)->toBe((string) $this->editor->id)
        ->and($heard[1]->publishedAt::class)->toBe(DateTimeImmutable::class)
        ->and($heard[1]->publishedAt->getTimezone()->getName())->toBe('UTC')
        ->and($heard[1]->publishedAt->getTimestamp())->toBe($item->published_at->getTimestamp());
});

it('sends ContentPublished once when a scheduled publication runs, acting for whoever scheduled it', function (): void {
    $item = lifecycleDraft($this->author, 'Harbour news');
    $heard = hkHear(ContentScheduled::class, ContentPublished::class);
    $when = now()->addMinute();

    schedFor($item, $this->editor, $when->toIso8601String());
    $this->travel(2)->minutes();
    app(RunScheduledPublication::class)->execute(new ScheduledPublicationInput((string) schedRow($item)->id, (string) $this->editor->id));

    expect(array_map(fn (object $hook): string => $hook::class, $heard->getArrayCopy()))->toBe([ContentScheduled::class, ContentPublished::class])
        ->and($heard[0]->publishAt::class)->toBe(DateTimeImmutable::class)
        ->and($heard[0]->publishAt->getTimezone()->getName())->toBe('UTC')
        ->and($heard[0]->publishAt->getTimestamp())->toBe($when->getTimestamp())
        ->and($heard[0]->revisionId)->toBe((string) $item->working_revision_id)
        ->and($heard[0]->actorId)->toBe((string) $this->editor->id)
        ->and($heard[1]->actorId)->toBe((string) $this->editor->id)
        ->and($heard[1]->first)->toBeTrue();
});

it('sends ContentUnpublished and ContentTrashed with the address the item had', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $url = Addresses::urlFor($item);
    $heard = hkHear(ContentUnpublished::class, ContentTrashed::class);

    ($this->act)(UnpublishContent::class, $item, $this->editor);
    listsPublish($item, $this->editor);
    ($this->act)(TrashContent::class, $item, $this->editor);
    ($this->act)(TrashContent::class, $item, $this->editor);

    expect($url)->not->toBeNull()
        ->and(array_map(fn (object $hook): string => $hook::class, $heard->getArrayCopy()))->toBe([ContentUnpublished::class, ContentTrashed::class])
        ->and([$heard[0]->formerUrl, $heard[1]->formerUrl])->toBe([$url, $url])
        ->and([$heard[0]->item->url, $heard[1]->item->url])->toBe([null, null])
        ->and($heard[0]->item->status)->toBe('draft')
        ->and($heard[1]->actorId)->toBe((string) $this->editor->id);
});

it('sends ContentRestored and ContentDeleted', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $url = Addresses::urlFor($item);
    ($this->act)(TrashContent::class, $item, $this->editor);
    $heard = hkHear(ContentRestored::class, ContentDeleted::class);

    ($this->act)(RestoreContent::class, $item, $this->editor);
    ($this->act)(RestoreContent::class, $item, $this->editor);
    ($this->act)(TrashContent::class, $item, $this->editor);
    ($this->act)(DeleteContentPermanently::class, $item, $this->editor);

    expect(array_map(fn (object $hook): string => $hook::class, $heard->getArrayCopy()))->toBe([ContentRestored::class, ContentDeleted::class])
        ->and($heard[0]->item->id)->toBe($item->id)
        ->and($url)->not->toBeNull()
        ->and($heard[0]->item->url)->toBe($url)
        ->and($heard[0]->actorId)->toBe((string) $this->editor->id)
        ->and([$heard[1]->id, $heard[1]->type, $heard[1]->title, $heard[1]->actorId])->toBe([$item->id, $item->type, 'Harbour news', (string) $this->editor->id])
        ->and(Content::withTrashed()->find($item->id))->toBeNull();
});

it('sends ContentVisibilityChanged once, after commit, when an item is made private and public again', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $url = Addresses::urlFor($item);
    $heard = hkHear(ContentVisibilityChanged::class);
    $levels = [];
    app(HookDispatcher::class)->subscriber('core')->on(ContentVisibilityChanged::class, function () use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    Visibilities::change($item, 'private', $this->editor);

    expect($heard)->toHaveCount(1)
        ->and([$heard[0]->from, $heard[0]->to])->toBe(['public', 'private'])
        ->and($heard[0]->formerUrl)->not->toBeNull()
        ->and($heard[0]->formerUrl)->toBe($url)
        ->and($heard[0]->item->url)->toBeNull()
        ->and($heard[0]->actorId)->toBe((string) $this->editor->id)
        ->and($levels)->toBe([hkBaseLevel()]);

    $public = Visibilities::change($item, 'public', $this->editor);

    expect($heard)->toHaveCount(2)
        ->and([$heard[1]->from, $heard[1]->to])->toBe(['private', 'public'])
        ->and($heard[1]->item->url)->not->toBeNull()
        ->and($heard[1]->item->url)->toBe(Addresses::urlFor($public))
        ->and($heard[1]->formerUrl)->toBeNull();
});

it('sends nothing when visibility doesn’t change, and never the password', function (): void {
    $item = addrPublished($this->author, 'Harbour news');
    $heard = hkHear(ContentVisibilityChanged::class);

    Visibilities::change($item, 'public', $this->editor);

    expect($heard)->toHaveCount(0);

    Visibilities::change($item, 'protected', $this->editor, 'Lighthouse-Keeper-41');

    expect($heard)->toHaveCount(1)
        ->and([$heard[0]->from, $heard[0]->to])->toBe(['public', 'protected']);

    Visibilities::change($item, 'protected', $this->editor, 'Lighthouse-Keeper-42');

    expect($heard)->toHaveCount(1)
        ->and(var_export($heard->getArrayCopy(), true))->not->toContain('Lighthouse-Keeper');
});

it('announces nothing when an Operation is refused', function (Closure $refused): void {
    $this->draft = lifecycleDraft($this->author, 'Harbour draft');
    $this->published = addrPublished($this->author, 'Harbour news');
    $this->trashed = addrPublished($this->author, 'Harbour gone');
    ($this->act)(TrashContent::class, $this->trashed, $this->editor);
    $heard = hkHear(ContentSaved::class, ContentPublished::class, ContentUnpublished::class, ContentScheduled::class, ContentTrashed::class, ContentRestored::class, ContentVisibilityChanged::class, ContentDeleted::class);

    expect($refused)->toThrow(AuthorizationException::class)->and($heard->getArrayCopy())->toBe([]);
})->with([
    'CreateDraft' => [fn () => app(CreateDraft::class)->execute(new CreateDraftInput(type: test()->draft->type, title: 'Nope', document: ['type' => 'doc', 'content' => []], fields: [], actorId: (string) test()->nobody->id))],
    'SaveDraft' => [fn () => docSave(test()->draft, test()->nobody, docWith(docText('Nope.')))],
    'PublishRevision' => [fn () => listsPublish(test()->draft, test()->nobody)],
    'UnpublishContent' => [fn () => app(UnpublishContent::class)->execute(new ContentActionInput(test()->published->id, (string) test()->nobody->id))],
    'SchedulePublication' => [fn () => schedFor(test()->draft, test()->nobody, now()->addDay()->toIso8601String())],
    'TrashContent' => [fn () => app(TrashContent::class)->execute(new ContentActionInput(test()->published->id, (string) test()->nobody->id))],
    'RestoreContent' => [fn () => app(RestoreContent::class)->execute(new ContentActionInput(test()->trashed->id, (string) test()->nobody->id))],
    'ChangeVisibility' => [fn () => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput(test()->published->id, 'private', null, (string) test()->nobody->id))],
    'DeleteContentPermanently' => [function (): void {
        app()->instance(Elevation::class, new DeniedElevation);
        app(DeleteContentPermanently::class)->execute(new ContentActionInput(test()->trashed->id, null));
    }],
]);

it('tells every content hook’s item in the site’s language (I-3360)', function (): void {
    cmSettings(['language' => 'pt-BR'], 'general');
    $heard = hkHear(ContentSaved::class, ContentScheduled::class, ContentPublished::class, ContentUnpublished::class, ContentVisibilityChanged::class, ContentTrashed::class, ContentRestored::class);
    $item = lifecycleDraft($this->author, 'Harbour news');
    schedFor($item, $this->editor, now()->addHour()->toIso8601String());
    listsPublish($item, $this->editor);
    Visibilities::change($item, 'private', $this->editor);
    ($this->act)(UnpublishContent::class, $item, $this->editor);
    ($this->act)(TrashContent::class, $item, $this->editor);
    ($this->act)(RestoreContent::class, $item, $this->editor);

    $told = array_map(fn (object $hook): array => [$hook::class, $hook->item->language], $heard->getArrayCopy());

    expect(array_unique(array_column($told, 0)))->toEqualCanonicalizing([ContentSaved::class, ContentScheduled::class, ContentPublished::class, ContentUnpublished::class, ContentVisibilityChanged::class, ContentTrashed::class, ContentRestored::class])
        ->and(array_unique(array_column($told, 1)))->toBe(['pt-BR']);
});

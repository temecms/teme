<?php

declare(strict_types=1);

use App\Domain\Hooks\HookDispatcher;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\ImportTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\ImportTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Cache\PagesPurged;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Media\MediaDeleted;
use Teme\Contracts\Media\MediaRestored;
use Teme\Contracts\Media\MediaTrashed;
use Teme\Contracts\Media\MediaUpdated;
use Teme\Contracts\Taxonomy\TermAssigned;
use Teme\Contracts\Taxonomy\TermCreated;
use Teme\Contracts\Taxonomy\TermDeleted;
use Teme\Contracts\Taxonomy\TermUpdated;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('refuses a plugin the hook its capability would let it hear when it did not ask for it', function (string $hook, string $capability): void {
    expect(fn () => hkSubscriber('acme/one', [], [class_basename($hook)])->on($hook, fn () => null))
        ->toThrow(HookRefused::class, "without asking for {$capability} in its teme-plugin.json");
    expect(hkSubscriber('acme/one', [$capability], [class_basename($hook)])->on($hook, fn () => null)->id)->toContain(class_basename($hook));
})->with([
    'MediaUpdated' => [MediaUpdated::class, 'media.read'],
    'MediaTrashed' => [MediaTrashed::class, 'media.read'],
    'MediaRestored' => [MediaRestored::class, 'media.read'],
    'MediaDeleted' => [MediaDeleted::class, 'media.read'],
    'TermCreated' => [TermCreated::class, 'content.read'],
    'TermUpdated' => [TermUpdated::class, 'content.read'],
    'TermDeleted' => [TermDeleted::class, 'content.read'],
    'TermAssigned' => [TermAssigned::class, 'content.read'],
    'PagesPurged' => [PagesPurged::class, 'content.read'],
]);

it('sends no media or term hook when the change rolls back', function (): void {
    $media = mediaUpload($this->editor, hkPng(64, 48), 'Harbour.png');
    $term = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Harbour news', null, null, null, (string) $this->editor->id));
    $other = app(CreateTerm::class)->execute(new SaveTermInput('category', 'Ferries', null, null, null, (string) $this->editor->id));
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ([MediaUpdated::class, MediaTrashed::class, MediaRestored::class, MediaDeleted::class, TermCreated::class, TermUpdated::class, TermDeleted::class] as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard[] = $hook;
        });
    }

    $by = (string) $this->editor->id;
    $changes = [
        fn () => app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, $by, 'Harbour at dusk', 'A boat', null, null)),
        fn () => app(TrashMedia::class)->execute(new MediaActionInput($media->id, $by)),
        fn () => app(CreateTerm::class)->execute(new SaveTermInput('category', 'Docks', null, null, null, $by)),
        fn () => app(UpdateTerm::class)->execute(new SaveTermInput('category', 'Harbour stories', null, null, null, $by, (string) $term->id)),
        fn () => app(DeleteTerm::class)->execute(new DeleteTermInput((string) $other->id, $by)),
    ];

    foreach ($changes as $change) {
        expect(fn () => DB::transaction(function () use ($change): void {
            $change();

            throw new RuntimeException('The outer change failed on purpose.');
        }))->toThrow(RuntimeException::class);
    }

    expect($heard->getArrayCopy())->toBe([]);
});

it('sends no term hook for a term an import run writes', function (): void {
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ([TermCreated::class, TermUpdated::class, TermDeleted::class, TermAssigned::class] as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard[] = $hook;
        });
    }

    scElevated(fn () => app(ImportTerm::class)->execute(new ImportTermInput('category', 'Harbour news', 'harbour-news', null, null, null, null)));

    expect($heard->getArrayCopy())->toBe([]);
});

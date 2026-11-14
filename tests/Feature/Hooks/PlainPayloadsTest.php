<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\DeleteContentPermanently;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Teme\Contracts\Comments\CommentStatusChanged;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentVisibilityChanged;
use Teme\Contracts\Media\MediaStored;
use Teme\Contracts\Settings\SettingsChanged;
use Tests\Fixtures\Contents\Visibilities;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->act = fn (string $operation, string $id): mixed => app($operation)->execute(new ContentActionInput($id, (string) $this->admin->id));
});

it('gives listeners only plain values, never a Carbon, model or Laravel object', function (string $hook, Closure $make): void {
    $heard = hkHear($hook);
    $make();

    expect($heard->count())->toBeGreaterThan(0);

    foreach ($heard as $payload) {
        expect(hkOffenders($payload))->toBe([]);
    }
})->with([
    'ContentSaved' => [ContentSaved::class, fn () => lifecycleDraft(test()->admin, 'Harbour news')],
    'ContentPublished' => [ContentPublished::class, fn () => listsPublish(lifecycleDraft(test()->admin, 'Harbour news'), test()->admin)],
    'ContentUnpublished' => [ContentUnpublished::class, fn () => (test()->act)(UnpublishContent::class, addrPublished(test()->admin, 'Harbour news')->id)],
    'ContentScheduled' => [ContentScheduled::class, fn () => schedFor(lifecycleDraft(test()->admin, 'Harbour news'), test()->admin, now()->addDay()->toIso8601String())],
    'ContentTrashed' => [ContentTrashed::class, fn () => (test()->act)(TrashContent::class, addrPublished(test()->admin, 'Harbour news')->id)],
    'ContentRestored' => [ContentRestored::class, function (): void {
        $id = addrPublished(test()->admin, 'Harbour news')->id;
        (test()->act)(TrashContent::class, $id);
        (test()->act)(RestoreContent::class, $id);
    }],
    'ContentVisibilityChanged' => [ContentVisibilityChanged::class, fn () => Visibilities::change(addrPublished(test()->admin, 'Harbour news'), 'private', test()->admin)],
    'ContentDeleted' => [ContentDeleted::class, function (): void {
        $id = lifecycleDraft(test()->admin, 'Harbour news')->id;
        (test()->act)(TrashContent::class, $id);
        (test()->act)(DeleteContentPermanently::class, $id);
    }],
    'MediaStored' => [MediaStored::class, fn () => mediaUpload(test()->admin, hkPng(40, 30), 'Harbour.png')],
    'CommentStatusChanged' => [CommentStatusChanged::class, fn () => cmModerate(cmComment(cmItem(test()->admin), 'pending'), 'approved', test()->admin)],
    'SettingsChanged' => [SettingsChanged::class, fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Plant Notes'], [], [], 0, (string) test()->admin->id))],
]);

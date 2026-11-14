<?php

declare(strict_types=1);

use App\Domain\Ai\Privacy\AiActivityHolder;
use App\Domain\Comments\Privacy\CommentsHolder;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Inbox\Privacy\InboxHolder;
use App\Domain\People\Privacy\PeopleHolder;
use App\Domain\Plugins\Privacy\PluginConsentHolder;
use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\Operations\Input\RecordPrivacyRequestInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyErasureInput;
use App\Domain\Privacy\Operations\Input\RunPrivacyExportInput;
use App\Domain\Privacy\Operations\RecordPrivacyRequest;
use App\Domain\Privacy\Operations\RunPrivacyErasure;
use App\Domain\Privacy\Operations\RunPrivacyExport;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyRequestsHolder;
use App\Domain\Security\Privacy\SecurityLogHolder;
use App\Domain\Settings\Privacy\SiteSettingsHolder;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Users\Privacy\AccountHolder;
use App\Domain\Users\Privacy\PictureHolder;
use App\Domain\Users\Privacy\SignInHolder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Hooks\HookRefused;
use Teme\Contracts\Privacy\CollectPersonalDataHolders;
use Teme\Contracts\Privacy\ErasePage;
use Teme\Contracts\Privacy\ExportGroup;
use Teme\Contracts\Privacy\ExportPage;
use Teme\Contracts\Privacy\PersonalDataHolder;
use Tests\Fixtures\Privacy\RecordingHolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
    app()->instance(Elevation::class, new OperatorElevation);

    $this->plugin = new RecordingHolder('maps.places', 'Saved places', [
        new ExportPage([new ExportGroup('Saved place', ['Name' => 'Harbour'])], true),
    ], [
        new ErasePage(2, 0, [], true),
    ]);
    // As a plugin declaring the hook in its teme-plugin.json subscribes.
    app(HookDispatcher::class)->subscriber('acme/maps', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [$this->plugin]);
});

it('lists core’s eleven holders in their order, then a plugin’s', function (): void {
    expect(array_map(fn (PersonalDataHolder $holder): string => $holder::class, app(PersonalDataHolders::class)->all()))->toBe([
        CommentsHolder::class,
        AccountHolder::class,
        SignInHolder::class,
        SecurityLogHolder::class,
        SiteSettingsHolder::class,
        InboxHolder::class,
        AiActivityHolder::class,
        PluginConsentHolder::class,
        PeopleHolder::class,
        PictureHolder::class,
        PrivacyRequestsHolder::class,
        RecordingHolder::class,
    ])
        ->and(array_keys(app(PersonalDataHolders::class)->byKey()))->toBe([
            'comments', 'account', 'sign-in', 'security-log', 'site-settings', 'inbox', 'ai-activity', 'plugin-consent', 'people', 'picture', 'privacy-requests', 'maps.places',
        ]);
});

it('refuses a plugin’s holder that takes a key core’s holds', function (): void {
    app(HookDispatcher::class)->subscriber('acme/rogue', [], ['CollectPersonalDataHolders'])
        ->on(CollectPersonalDataHolders::class, fn (): array => [new RecordingHolder('comments', 'Comments again')]);

    expect(fn () => app(PersonalDataHolders::class)->all())->toThrow(ValidationException::class, 'Two holders of personal data share the key comments.');
});

it('exports and erases a plugin’s holder in its turn, after core’s', function (): void {
    $export = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('export', 'ada@example.com', 'Asked by email.', false));
    $erase = app(RecordPrivacyRequest::class)->execute(new RecordPrivacyRequestInput('erase', 'ada@example.com', 'Asked by email.', false));

    while (! app(RunPrivacyExport::class)->execute(new RunPrivacyExportInput($export))->done);
    while (! app(RunPrivacyErasure::class)->execute(new RunPrivacyErasureInput($erase))->done);

    $file = json_decode((string) Storage::disk('local')->get("privacy/{$export}.json"), true, flags: JSON_THROW_ON_ERROR);
    $results = PrivacyRequest::query()->findOrFail($erase)->results;

    expect(end($file['holders']))->toBe(['key' => 'maps.places', 'label' => 'Saved places', 'groups' => [['title' => 'Saved place', 'values' => ['Name' => 'Harbour']]]])
        ->and(end($results))->toMatchArray(['key' => 'maps.places', 'removed' => 2, 'done' => true])
        ->and($this->plugin->calls)->toBe([['export', 'ada@example.com', null, 1], ['erase', 'ada@example.com', null, 1]]);
});

it('refuses a plugin that didn’t declare the hook', function (): void {
    expect(fn () => app(HookDispatcher::class)->subscriber('acme/quiet', [], ['ContentPublished'])
        ->on(CollectPersonalDataHolders::class, fn (): array => []))->toThrow(HookRefused::class);
});

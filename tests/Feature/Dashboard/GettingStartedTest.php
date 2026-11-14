<?php

declare(strict_types=1);

use App\Domain\Dashboard\Queries\GettingStarted;
use App\Domain\Preferences\Operations\Input\UpdatePreferencesInput;
use App\Domain\Preferences\Operations\UpdatePreferences;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Users\Operations\Input\InviteUserInput;
use App\Domain\Users\Operations\InviteUser;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('shows an administrator the steps a new site has not done yet', function (): void {
    $steps = app(GettingStarted::class)->forUser($this->admin)['steps'];

    expect(array_column($steps, 'key'))->toBe(['site_title', 'timezone', 'invite', 'publish'])
        ->and(array_column($steps, 'done'))->toBe([false, false, false, false]);
});

it('ticks each step off from the site\'s real state', function (): void {
    Notification::fake();
    $this->withElevation();

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Harbour Notes', 'timezone' => 'Africa/Lagos'], [], [], 0, (string) $this->admin->id));

    expect(array_column(app(GettingStarted::class)->forUser($this->admin)['steps'], 'done'))->toBe([true, true, false, false]);

    app(InviteUser::class)->execute(new InviteUserInput('Ngozi Harry', 'ngozi@example.com', 'editor', (string) $this->admin->id));
    listsPublish(lifecycleDraft($this->admin, 'Night market'), $this->admin);

    // All four done: the checklist is gone.
    expect(app(GettingStarted::class)->forUser($this->admin))->toBeNull();
});

it('never shows the checklist to someone who cannot change the site\'s settings', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(app(GettingStarted::class)->forUser($editor))->toBeNull();
});

it('stays hidden for the person who hid it, and only for them', function (): void {
    $other = User::factory()->create()->assignRole('administrator');

    app(UpdatePreferences::class)->execute(new UpdatePreferencesInput(['dashboard.getting_started_hidden' => true], (string) $this->admin->id));

    expect(app(GettingStarted::class)->forUser($this->admin->refresh()))->toBeNull()
        ->and(app(GettingStarted::class)->forUser($other)['steps'])->toHaveCount(4);
});

it('leads its first-post step to the list, where Add new opens a draft in the editor (I-425)', function (): void {
    $steps = collect(app(GettingStarted::class)->forUser($this->admin)['steps'])->keyBy('key');

    expect($steps['publish']['url'])->toBe(route('content.index', ['type' => 'posts']));
});

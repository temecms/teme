<?php

declare(strict_types=1);

use App\Domain\Comments\Operations\ChangeDiscussionSettings;
use App\Domain\Comments\Operations\Input\ChangeDiscussionSettingsInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Settings\SettingsChanged;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('sends SettingsChanged with the keys that changed, never their values, for every settings write', function (): void {
    $heard = hkHear(SettingsChanged::class);

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Plant Notes'], [], [], 0, (string) $this->admin->id));
    app(ChangeDiscussionSettings::class)->execute(new ChangeDiscussionSettingsInput(['hold_all' => true], 0, (string) $this->admin->id));
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Plant Notes'], [], [], 1, (string) $this->admin->id));

    expect($heard)->toHaveCount(2)
        ->and([$heard[0]->group, $heard[0]->keys, $heard[0]->actorId])->toBe(['general', ['site_title'], (string) $this->admin->id])
        ->and([$heard[1]->group, $heard[1]->keys, $heard[1]->actorId])->toBe(['discussion', ['hold_all'], (string) $this->admin->id])
        ->and(var_export($heard->getArrayCopy(), true))->not->toContain('Plant Notes');
});

it('announces nothing when a settings change is refused', function (): void {
    $heard = hkHear(SettingsChanged::class);

    expect(fn () => app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Plant Notes'], [], [], 0, (string) $this->editor->id)))
        ->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

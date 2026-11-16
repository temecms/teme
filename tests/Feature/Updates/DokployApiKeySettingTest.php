<?php

declare(strict_types=1);

use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SecretStore;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Updates\UpdatesSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Teme\Contracts\Settings\SettingType;

/*
 * Settings › Updates: the Dokploy API key the updater uses, typed in the admin.
 * Kept encrypted, saved only by someone who may update Teme and has just
 * given their password again, and never shown again.
 */

uses(RefreshDatabase::class);

const DOKPLOY_KEY = 'dkp_test_4f9a1c7e2b8d';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->save = fn (User $actor, array $body, bool $confirmed = true) => ($confirmed ? $this->actingAs($actor)->withSession(scConfirmed()) : $this->actingAs($actor))
        ->from('/admin/settings/updates')
        ->put('/admin/settings/updates', $body);
});

afterEach(function (): void {
    aiServerKey('TEME_DOKPLOY_TOKEN', null);
});

function storedDokployKey(): ?string
{
    return app(SecretStore::class)->secret(UpdatesSettings::GROUP, UpdatesSettings::DOKPLOY_API_KEY);
}

it('registers the updates group: one secret, the Dokploy API key, for those who may update Teme', function (): void {
    $group = app(SettingsRegistry::class)->find('updates');

    expect($group)->not->toBeNull()
        ->and($group->label)->toBe('Updates')
        ->and($group->ability)->toBe('updates.manage')
        ->and($group->sensitive)->toBeTrue()
        ->and($group->screen)->toBeNull()
        ->and(array_map(fn ($setting): string => $setting->key, $group->settings))->toBe(['dokploy_api_key'])
        ->and($group->setting('dokploy_api_key')->type)->toBe(SettingType::Secret)
        ->and($group->setting('dokploy_api_key')->env)->toBe('TEME_DOKPLOY_TOKEN')
        ->and(UpdatesSettings::GROUP.'.'.UpdatesSettings::DOKPLOY_API_KEY)->toBe('updates.dokploy_api_key');
});

it('saves the key encrypted once the password is given again, and keeps it out of the page', function (): void {
    ($this->save)($this->admin, ['secrets' => ['dokploy_api_key' => '  '.DOKPLOY_KEY." \n"], 'expected_version' => 0])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/settings/updates');

    $row = (string) json_encode(DB::table('settings')->where('group_key', 'updates')->first());

    expect(storedDokployKey())->toBe(DOKPLOY_KEY)
        ->and($row)->not->toContain(DOKPLOY_KEY);

    $page = $this->actingAs($this->admin)->get('/admin/settings/updates')->assertOk();

    expect((string) $page->getContent())->not->toContain(DOKPLOY_KEY);
});

it('asks for the password first, and writes nothing until it is given', function (): void {
    ($this->save)($this->admin, ['secrets' => ['dokploy_api_key' => DOKPLOY_KEY], 'expected_version' => 0], false)
        ->assertRedirect(route('password.confirm'));

    expect(storedDokployKey())->toBeNull()
        ->and(DB::table('settings')->where('group_key', 'updates')->exists())->toBeFalse();
});

it('refuses anyone who may not update Teme, and writes nothing', function (): void {
    $this->actingAs($this->editor)->get('/admin/settings/updates')->assertForbidden();
    ($this->save)($this->editor, ['secrets' => ['dokploy_api_key' => DOKPLOY_KEY], 'expected_version' => 0])->assertForbidden();

    expect(fn () => scElevated(fn (): mixed => app(UpdateSettings::class)->execute(
        new UpdateSettingsInput('updates', [], [], ['dokploy_api_key' => DOKPLOY_KEY], 0, (string) $this->editor->id),
    )))->toThrow(AuthorizationException::class);

    expect(storedDokployKey())->toBeNull()
        ->and(DB::table('settings')->where('group_key', 'updates')->exists())->toBeFalse();
});

it('lets a key set on the server win, and refuses to change it here', function (): void {
    aiServerKey('TEME_DOKPLOY_TOKEN', 'dkp_from_the_server');

    ($this->save)($this->admin, ['secrets' => ['dokploy_api_key' => DOKPLOY_KEY], 'expected_version' => 0])
        ->assertSessionHasErrors(['secrets.dokploy_api_key' => 'This value is provided by the server and cannot be changed here.']);

    expect(storedDokployKey())->toBe('dkp_from_the_server')
        ->and(DB::table('settings')->where('group_key', 'updates')->exists())->toBeFalse();
});

it('never writes the key to a log line', function (): void {
    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context);
    });

    ($this->save)($this->admin, ['secrets' => ['dokploy_api_key' => DOKPLOY_KEY], 'expected_version' => 0])->assertSessionHasNoErrors();
    ($this->save)($this->admin, ['secrets' => ['dokploy_api_key' => DOKPLOY_KEY.'x'], 'expected_version' => 0])->assertSessionHasErrors();

    expect(implode("\n", $lines))->not->toContain(DOKPLOY_KEY)
        ->and((string) json_encode(DB::table('security_events')->get()))->not->toContain(DOKPLOY_KEY);
});

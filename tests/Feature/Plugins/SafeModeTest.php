<?php

declare(strict_types=1);

use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\SafeMode;
use App\Domain\Plugins\Seams\HookSubscriptions;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\RecordingSubscriptions;

uses(RefreshDatabase::class);

afterEach(fn () => PluginFixtures::forget());

it('treats each safe page as safe', fn (string $path) => expect(SafeMode::forPath($path))->toBeTrue())->with([
    'admin/login', 'admin/logout', 'admin/two-factor-challenge', 'admin/forgot-password', 'admin/reset-password', 'admin/reset-password/a1b2c3d4e5',
    'admin/user/confirm-password', 'admin/user/confirmed-password-status',
    'admin/email/verify', 'admin/email/verify/12/0a1b2c3d4e5f', 'admin/email/verification-notification',
    'admin/account/security', 'admin/user/two-factor-authentication', 'admin/user/confirmed-two-factor-authentication',
    'admin/user/two-factor-qr-code', 'admin/user/two-factor-recovery-codes', 'admin/user/two-factor-secret-key',
    'admin/plugins', 'admin/plugins/x', 'admin/tools/updates',
]);

it('starts plugins on ordinary pages', fn (string $path) => expect(SafeMode::forPath($path))->toBeFalse())->with([
    '/', 'admin', 'admin/content/post', 'admin/settings/general', 'admin/settings/plugin.example-sync', 'admin/tools/backups', 'sitemap.xml',
]);

it('starts plugins on the account pages beside the two-factor set-up', fn (string $path) => expect(SafeMode::forPath($path))->toBeFalse())->with([
    'admin/account/password', 'admin/account/profile', 'admin/account/security/x', 'admin/user/two-factor-authentication/x',
]);

it('treats each safe command as safe, and the worker and scheduler as not', fn (string $command, bool $safe) => expect(SafeMode::forCommand($command))->toBe($safe))->with([
    ['migrate', true], ['migrate:status', true], ['teme:plugins:enable', true], ['teme:docs:fields', true], ['optimize', true], ['optimize:clear', true],
    ['config:cache', true], ['teme:deploy', true], ['teme:updater', true], ['teme:updates:check', true], ['', true],
    ['queue:work', false], ['schedule:run', false], ['teme:redirects:count', false], ['tinker', false], ['teme:import', false],
]);

it('treats maintenance mode and the health check as safe', function (): void {
    expect(SafeMode::forCommand('down'))->toBeTrue()
        ->and(SafeMode::forCommand('up'))->toBeTrue()
        ->and(SafeMode::forPath('up'))->toBeTrue();
});

it('reads the command as Symfony does, an option before its name included', function (array $argv, bool $safe): void {
    expect(SafeMode::forArgv($argv))->toBe($safe);
})->with([
    'the name alone' => [['artisan', 'migrate'], true],
    'an option before it' => [['artisan', '--env=production', 'migrate', '--force'], true],
    'a short option before it' => [['artisan', '-v', 'down'], true],
    'an option before an unsafe one' => [['artisan', '--no-interaction', 'queue:work'], false],
    'nothing' => [['artisan'], true],
    'the environment, its value apart' => [['artisan', '--env', 'production', 'teme:plugins:disable', 'x'], true],
    'quiet, then the environment with its value' => [['artisan', '-q', '--env=local', 'teme:plugins', 'x'], true],
    'no interaction' => [['artisan', '--no-interaction', 'migrate'], true],
    'the environment before an unsafe one' => [['artisan', '--env', 'production', 'queue:work'], false],
]);

/*
 * Follow-up G1's end-to-end test, its second form: on the Security page and
 * each two-factor set-up route no plugin starts, even one that breaks at
 * start, while the page beside them starts it; and an administrator whose
 * role needs two-factor, not yet set up, is sent to the Security page and
 * gets it (I-115).
 */
it('starts no plugin on the pages an administrator needs to set up two-factor, and sends them there', function (string $path, bool $starts): void {
    $this->seed(RoleSeeder::class);
    $subscriptions = new RecordingSubscriptions;
    app()->instance(HookSubscriptions::class, $subscriptions);
    PluginFixtures::use('broken-start');
    PluginRecord::factory()->enabled()->create(['name' => 'tests/broken-start', 'code' => 'broken-start', 'consent' => ['capabilities' => ['content.read'], 'hosts' => [], 'hooks' => ['ContentPublished']]]);

    // As a site answering a request, not the test suite: active() reads the request.
    app()['env'] = 'production';
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
    app()->instance('request', Request::create('/'.$path));

    app(PluginLoader::class)->load();

    app()['env'] = 'testing';
    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), null);

    expect($subscriptions->calls('for'))->toHaveCount($starts ? 1 : 0)
        ->and(app(LoadedPlugins::class)->failure('tests/broken-start'))->toBe($starts ? 'The broken plugin broke.' : null);

    $admin = User::factory()->create()->assignRole('administrator');
    test()->withElevation();
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('security', ['two_factor_required_roles' => ['administrator']], [], [], 0, (string) $admin->id));

    $this->actingAs($admin)->get('/admin/account/profile')->assertRedirect(route('security.edit'));
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->get('/admin/account/security')->assertOk();
})->with([
    'the Security page' => ['admin/account/security', false],
    'turning two-factor on' => ['admin/user/two-factor-authentication', false],
    'confirming it' => ['admin/user/confirmed-two-factor-authentication', false],
    'its QR code' => ['admin/user/two-factor-qr-code', false],
    'its recovery codes' => ['admin/user/two-factor-recovery-codes', false],
    'its secret key' => ['admin/user/two-factor-secret-key', false],
    'the password page beside them' => ['admin/account/password', true],
]);

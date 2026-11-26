<?php

declare(strict_types=1);

use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginRedirects;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Redirects\Models\Redirect;
use App\Domain\Redirects\Operations\CreateRedirect;
use App\Domain\Redirects\Operations\DeleteRedirects;
use App\Domain\Redirects\Operations\Input\CreateRedirectInput;
use App\Domain\Redirects\Operations\Input\DeleteRedirectsInput;
use App\Domain\Redirects\Queries\RedirectList;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Redirects\RedirectWriter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);

    $this->enable = fn (string $code = 'sharely', array $capabilities = ['redirects'], string $state = PluginRecord::ENABLED): PluginRecord => PluginRecord::factory()->enabled()->create([
        'name' => 'tests/'.$code,
        'code' => $code,
        'version' => '1.0.0',
        'state' => $state,
        'consent' => ['capabilities' => $capabilities, 'hosts' => [], 'hooks' => []],
    ]);

    $this->door = fn (string $code = 'sharely'): RedirectWriter => app(PluginDoors::class)->for('tests/'.$code, $code, ['redirects'], [])[RedirectWriter::class];

    $this->rows = fn (): int => Redirect::query()->count();
});

it('offers RedirectWriter only when redirects is granted', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/sharely', 'sharely', ['redirects'], [])[RedirectWriter::class])->toBeInstanceOf(PluginRedirects::class)
        ->and($doors->for('tests/sharely', 'sharely', ['content.write'], []))->not->toHaveKey(RedirectWriter::class);
});

it('adds a redirect as the site, marked with the plugin, and lists it', function (): void {
    ($this->enable)();

    $moved = ($this->door)()->add('/old-page', '/new-page', 302, 'Moved by the plugin');
    $gone = ($this->door)()->gone('/retired');
    $outside = ($this->door)()->add('/elsewhere', 'https://example.org/there');
    $row = Redirect::query()->findOrFail($moved->id);

    expect($moved->from)->toBe('/old-page')
        ->and($moved->status)->toBe(302)
        ->and($moved->note)->toBe('Moved by the plugin')
        ->and($moved->answering)->toBeTrue()
        ->and($gone->status)->toBe(410)
        ->and($gone->to)->toBeNull()
        ->and($outside->to)->toBe('https://example.org/there')
        ->and($row->origin)->toBe('plugin')
        ->and($row->plugin)->toBe('sharely')
        ->and($row->created_by)->toBeNull()
        ->and(array_map(fn ($redirect) => $redirect->from, ($this->door)()->mine()))->toBe(['/old-page', '/retired', '/elsewhere']);
});

it('shows on Tools › Redirects who added it', function (): void {
    ($this->enable)();
    ($this->door)()->add('/old-page', '/new-page');

    $rows = app(RedirectList::class)->page('', 10)['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['origin'])->toBe('plugin')
        ->and($rows[0]['addedBy'])->toBe('sharely');
});

it('removes only its own redirects, never a person’s or another plugin’s', function (): void {
    ($this->enable)();
    ($this->enable)('other');
    $admin = User::factory()->create()->assignRole('administrator');
    $person = app(CreateRedirect::class)->execute(new CreateRedirectInput('/people-made', 301, '/target', actorId: (string) $admin->id));
    $others = ($this->door)('other')->add('/others-made', '/target');
    $mine = ($this->door)()->add('/mine', '/target');

    expect(($this->door)()->mine())->toHaveCount(1)
        ->and(($this->door)()->remove([$person['id'], $others->id, $mine->id, 'no-such-id']))->toBe(1)
        ->and(($this->door)()->mine())->toBe([])
        ->and(Redirect::query()->pluck('source')->sort()->values()->all())->toBe(['others-made', 'people-made']);
});

it('refuses what core refuses, and writes nothing', function (string $from, string $to, int $status): void {
    ($this->enable)();
    ($this->door)()->add('/taken', '/somewhere');
    $before = ($this->rows)();

    expect(fn () => ($this->door)()->add($from, $to, $status))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before);
})->with([
    'a path that already has a redirect' => ['/taken', '/elsewhere', 301],
    'a loop' => ['/loop', '/loop', 301],
    'a path the site itself uses' => ['/admin', '/elsewhere', 301],
    'a status that isn’t offered' => ['/new', '/elsewhere', 307],
    'no address to send to' => ['/new', '', 301],
]);

it('writes nothing for a plugin that isn’t enabled or wasn’t granted redirects', function (array $capabilities, string $state): void {
    ($this->enable)('sharely', $capabilities, $state);
    $before = ($this->rows)();

    expect(fn () => app(CreateRedirect::class)->execute(new CreateRedirectInput('/old', 301, '/new', plugin: 'sharely')))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->door)()->add('/old', '/new'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->remove(['anything']))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before);
})->with([
    'not granted' => [['content.write'], PluginRecord::ENABLED],
    'disabled' => [['redirects'], PluginRecord::DISABLED],
]);

it('removes nothing when the plugin is refused', function (): void {
    ($this->enable)();
    $mine = ($this->door)()->add('/mine', '/target');
    PluginRecord::query()->where('code', 'sharely')->update(['state' => PluginRecord::DISABLED]);

    expect(fn () => app(DeleteRedirects::class)->execute(new DeleteRedirectsInput([$mine->id], plugin: 'sharely')))->toThrow(AuthorizationException::class)
        ->and(($this->rows)())->toBe(1);
});

it('says when a page already answers the path, as core does', function (): void {
    ($this->enable)();

    $waiting = ($this->door)()->add('/2026', '/somewhere');

    expect($waiting->answering)->toBeFalse();
});

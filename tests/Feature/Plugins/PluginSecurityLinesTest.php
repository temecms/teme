<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginSecurityLines;
use App\Domain\Security\Privacy\SecurityLogHolder;
use App\Domain\Security\SecurityLog;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Privacy\PersonalDataSubject;
use Teme\Contracts\Security\SecurityLines;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->kinds = ['blocked' => 'Blocked sign-in', 'probe' => 'Probe'];
    $this->lines = fn (): PluginSecurityLines => new PluginSecurityLines('sharely', $this->kinds, app(SecurityLog::class));
    $this->rows = fn (): int => DB::table('security_events')->where('type', 'plugin.sharely')->count();
});

it('offers SecurityLines only when security.log is granted', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/sharely', 'sharely', ['security.log'], [])[SecurityLines::class])->toBeInstanceOf(PluginSecurityLines::class)
        ->and($doors->for('tests/sharely', 'sharely', ['content.write'], []))->not->toHaveKey(SecurityLines::class);
});

it('writes a line under the plugin’s name, with the kind, the text and the person it is about', function (): void {
    $user = User::factory()->create();

    ($this->lines)()->write('blocked', 'Three wrong passwords', userId: $user->id);

    $row = DB::table('security_events')->where('type', 'plugin.sharely')->sole();

    expect($row->subject)->toBe('user:'.$user->id)
        ->and($row->counted)->toBeFalse()
        ->and(json_decode((string) $row->context, true))->toBe(['kind' => 'blocked', 'text' => 'Three wrong passwords']);
});

it('shows a person the plugin’s lines about them in their data export', function (): void {
    $user = User::factory()->create(['email' => 'reader@example.test']);
    ($this->lines)()->write('probe', 'Looked for an admin page', userId: $user->id);

    $page = app(SecurityLogHolder::class)->export(new PersonalDataSubject($user->email, (string) $user->id), 1);
    $events = array_map(fn ($group): array => $group->values, $page->groups);

    expect(json_encode($events))->toContain('probe')
        ->and(json_encode($events))->toContain('Looked for an admin page');
});

it('refuses a kind the manifest does not declare, writing nothing', function (): void {
    expect(fn () => ($this->lines)()->write('unknown', 'Something'))->toThrow(InvalidArgumentException::class);
    expect(($this->rows)())->toBe(0);
});

it('refuses text that is empty or longer than 200 characters, writing nothing', function (string $text): void {
    expect(fn () => ($this->lines)()->write('probe', $text))->toThrow(InvalidArgumentException::class);
    expect(($this->rows)())->toBe(0);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'too long' => [str_repeat('a', 201)],
]);

it('takes at most 60 lines an hour from one plugin, and writes nothing past that', function (): void {
    $lines = ($this->lines)();

    foreach (range(1, 60) as $_) {
        expect($lines->write('probe', 'Probe'))->toBeTrue();
    }

    expect($lines->write('probe', 'One too many'))->toBeFalse()
        ->and(($this->rows)())->toBe(60);
});

it('refuses a security.log kind a plugin declares without asking for security.log', function (): void {
    $declared = [['id' => 'probe', 'label' => 'Probe']];

    expect(app(ContributionsValidator::class)->errors(['securityLog' => $declared], '1.0.0', []))
        ->toBe(['It declares kinds of security log line, but the plugin doesn’t ask for security.log.'])
        ->and(app(ContributionsValidator::class)->errors(['securityLog' => $declared], '1.0.0', ['security.log']))->toBe([]);
});

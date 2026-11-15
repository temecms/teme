<?php

declare(strict_types=1);

use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\Operations\Input\SetImportSourceHostsInput;
use App\Domain\Import\Operations\SetImportSourceHosts;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    importFake();
    $this->source = importSource($this->admin);
});

it('stores other hosts bare, lower case, and without www', function (): void {
    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['Stage.Example.com', 'www.old-example.com'],
        (string) $this->admin->id,
    )));

    expect($this->source->fresh()->other_hosts)->toBe(['stage.example.com', 'old-example.com']);
});

it('clears other hosts when the list is empty', function (): void {
    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['stage.example.com'],
        (string) $this->admin->id,
    )));

    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        [],
        (string) $this->admin->id,
    )));

    expect($this->source->fresh()->other_hosts)->toBe([]);
});

it('drops a listed host when the next save omits it', function (): void {
    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['stage.example.com', 'cdn.example.com'],
        (string) $this->admin->id,
    )));

    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['cdn.example.com'],
        (string) $this->admin->id,
    )));

    expect($this->source->fresh()->other_hosts)->toBe(['cdn.example.com']);
});

it('refuses each invalid host by its sentence', function (array $hosts, string $words): void {
    $act = fn () => scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        $hosts,
        (string) $this->admin->id,
    )));

    expect($act)->toThrow(function (ValidationException $refusal) use ($words): void {
        expect($refusal->errors()['hosts'][0] ?? null)->toBe($words);
    });
    expect($this->source->fresh()->other_hosts ?? [])->toBe([]);
})->with([
    'a scheme' => [['https://stage.example.com'], 'Enter a host name only, such as stage.example.com.'],
    'a path' => [['stage.example.com/path'], 'Enter a host name only, such as stage.example.com.'],
    'a port' => [['stage.example.com:8443'], 'Enter a host name only, such as stage.example.com.'],
    'a user' => [['ada@stage.example.com'], 'Enter a host name only, such as stage.example.com.'],
    'no dot' => [['localhost'], 'Enter a host name only, such as stage.example.com.'],
    'the home host' => [['old.example'], 'That is this site’s own address already.'],
    'the home host with www' => [['www.old.example'], 'That is this site’s own address already.'],
    'a duplicate' => [['stage.example.com', 'Stage.Example.com'], 'Enter a host name only, such as stage.example.com.'],
    'more than ten' => [[
        'a.example.com', 'b.example.com', 'c.example.com', 'd.example.com', 'e.example.com',
        'f.example.com', 'g.example.com', 'h.example.com', 'i.example.com', 'j.example.com',
        'k.example.com',
    ], 'Ten other addresses at most.'],
]);

it('writes nothing when the person may not import', function (): void {
    $before = DB::table('import_sources')->orderBy('id')->get()->toJson();

    expect(fn () => scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['stage.example.com'],
        (string) $this->editor->id,
    ))))->toThrow(AuthorizationException::class, 'Not permitted to import a site.')
        ->and(DB::table('import_sources')->orderBy('id')->get()->toJson())->toBe($before);
});

it('refuses while a run is running and writes nothing', function (): void {
    $this->source->forceFill(['other_hosts' => ['stage.example.com']])->save();
    ImportRecords::run($this->source->id, [
        'state' => 'running',
        'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()),
        'ended_at' => null,
    ]);
    $before = $this->source->fresh()->other_hosts;

    expect(fn () => scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['cdn.example.com'],
        (string) $this->admin->id,
    ))))->toThrow(function (ValidationException $refusal): void {
        expect($refusal->errors()['source'][0] ?? null)->toBe('Stop the import that’s running first: it needs the application password.');
    });

    expect($this->source->fresh()->other_hosts)->toBe($before);
});

it('asks a person for their password again, and not the operator', function (): void {
    expect(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['stage.example.com'],
        (string) $this->admin->id,
    )))->toThrow(ElevationRequiredException::class);

    expect(ImportSource::query()->findOrFail($this->source->id)->other_hosts ?? [])->toBe([]);

    scElevated(fn () => app(SetImportSourceHosts::class)->execute(new SetImportSourceHostsInput(
        $this->source->id,
        ['stage.example.com'],
        null,
    )));

    expect(ImportSource::query()->findOrFail($this->source->id)->other_hosts)->toBe(['stage.example.com']);
});

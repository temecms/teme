<?php

declare(strict_types=1);

use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\Extensions\ExtensionsFolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => '0.5.0']);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->folder = ExtensionsFolder::make();
});

afterEach(function (): void {
    $this->folder->delete();
});

it('stamps a release that still fits the new image, without Composer', function (): void {
    $old = ExtensionsFolder::pinsWith(['fixtures/core-lib' => '1.2.0']);
    $id = $this->folder->install('fixtures/adapts', '1.0.0', pins: $old);
    $lock = file_get_contents($this->folder->store->path($id).'/composer.lock');
    $new = ExtensionsFolder::pinsWith(['fixtures/core-lib' => '1.3.0']);

    expect($this->folder->runner($new)->prepare())->toBeNull()
        ->and($this->folder->store->all())->toBe([$id])
        ->and($this->folder->store->path($id).'/compat/'.$new['hash'])->toBeFile()
        ->and($this->folder->store->path($id).'/cache/'.$new['hash'].'/teme-plugins.php')->toBeFile()
        ->and(file_get_contents($this->folder->store->path($id).'/composer.lock'))->toBe($lock)
        ->and(DB::table('extension_requests')->count())->toBe(0);
})->group('composer');

it('rebuilds with minimal changes a release the new image’s versions no longer fit', function (): void {
    $old = ExtensionsFolder::pinsWith(['fixtures/core-lib' => '1.2.0']);
    $this->folder->install('fixtures/hello-extension', '1.0.0', pins: $old);
    $id = $this->folder->install('fixtures/adapts', '1.0.0', pins: $old);
    $versions = fn (string $release): array => array_column(json_decode((string) file_get_contents($this->folder->store->path($release).'/composer.lock'), true)['packages'], 'version', 'name');

    // Asked for as ranges, so newer versions of both would fit: only the one that must move, moves.
    $project = json_decode((string) file_get_contents($this->folder->store->path($id).'/composer.json'), true);
    $project['require'] = ['fixtures/adapts' => '^1.0', 'fixtures/hello-extension' => '^1.0'];
    file_put_contents($this->folder->store->path($id).'/composer.json', json_encode($project));

    $new = ExtensionsFolder::pinsWith(['fixtures/core-lib' => '2.0.0']);

    expect($this->folder->runner($new)->prepare())->toBeNull();

    $rebuilt = $this->folder->store->current();
    $request = DB::table('extension_requests')->first();

    expect($rebuilt)->not->toBe($id)
        ->and($versions($rebuilt))->toBe(['fixtures/adapts' => '1.1.0', 'fixtures/hello-extension' => '1.0.0'])
        ->and($request->action)->toBe('rebuild')
        ->and($request->requested_by)->toBeNull()
        ->and($request->status)->toBe('done')
        ->and($this->folder->store->path($rebuilt).'/compat/'.$new['hash'])->toBeFile();
})->group('composer');

it('rebuilds a release holding a package the image now ships', function (): void {
    $id = $this->folder->install('fixtures/files-dependency', '1.0.0');
    $new = ExtensionsFolder::pinsWith(['fixtures/with-files' => '1.0.0']);

    expect($this->folder->runner($new)->prepare())->toBeNull();

    $rebuilt = $this->folder->store->current();

    expect($rebuilt)->not->toBe($id)
        ->and(array_column(json_decode((string) file_get_contents($this->folder->store->path($rebuilt).'/composer.lock'), true)['packages'], 'name'))->toBe(['fixtures/files-dependency']);
})->group('composer');

it('holds the release and says which package blocks when the rebuild fails', function (): void {
    $id = $this->folder->install('fixtures/adapts', '1.0.0', pins: ExtensionsFolder::pinsWith(['fixtures/core-lib' => '1.2.0']));
    $new = ExtensionsFolder::pinsWith(['fixtures/core-lib' => '3.0.0']);

    $held = $this->folder->runner($new)->prepare();

    expect($held)->toContain('fixtures/core-lib')
        ->and($this->folder->store->current())->toBe($id)
        ->and($this->folder->store->path($id).'/compat/'.$new['hash'])->not->toBeFile()
        ->and(DB::table('extension_requests')->value('status'))->toBe('failed');
})->group('composer');

it('rolls back no further than a release checked against this core', function (): void {
    $first = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $this->folder->install('fixtures/hello-extension', '1.1.0');
    unlink($this->folder->store->path($first).'/compat/'.ExtensionsFolder::pins()['hash']);

    DB::table('extension_requests')->insert(['id' => strtolower((string) Str::ulid()), 'action' => 'rollback', 'kind' => 'plugin', 'status' => 'requested', 'requested_at' => now()]);
    $runner = $this->folder->runner();

    for ($pass = 0; $pass < 10; $pass++) {
        $runner->tick();
    }

    expect(DB::table('extension_requests')->value('status'))->toBe('failed');
})->group('composer');

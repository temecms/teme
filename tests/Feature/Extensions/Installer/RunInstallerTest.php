<?php

declare(strict_types=1);

use App\Console\Commands\RunInstaller;
use App\Domain\Deployment\Health\Heartbeats;
use App\Domain\Extensions\Installer\InstallerRunner;
use App\Domain\Plugins\SafeMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Fixtures\Extensions\ExtensionsFolder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['teme.version' => '0.5.0']);
    $this->folder = ExtensionsFolder::make();
    config(['teme.extensions.path' => $this->folder->root]);
    @unlink(RunInstaller::READY);
});

afterEach(function (): void {
    $this->folder->delete();
    @unlink(RunInstaller::READY);
});

it('refuses to start on a server with no extensions folder', function (): void {
    config(['teme.extensions.path' => null]);

    $this->artisan('teme:installer', ['--prepare-only' => true])
        ->expectsOutputToContain('set TEME_EXTENSIONS_PATH')
        ->assertExitCode(1);

    expect(RunInstaller::READY)->not->toBeFile();
});

it('checks the current release against this image when it starts, then writes the ready file', function (): void {
    $id = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $stamp = $this->folder->store->path($id).'/compat/'.ExtensionsFolder::pins()['hash'];
    unlink($stamp);
    app()->instance(InstallerRunner::class, $this->folder->runner());

    $this->artisan('teme:installer', ['--prepare-only' => true])->assertExitCode(0);

    expect($stamp)->toBeFile()
        ->and(RunInstaller::READY)->toBeFile();
})->group('composer');

it('writes the ready file when the rebuild fails, so the site starts with its extensions held', function (): void {
    $id = $this->folder->install('fixtures/adapts', '1.0.0', pins: ExtensionsFolder::pinsWith(['fixtures/core-lib' => '1.2.0']));
    app()->instance(InstallerRunner::class, $this->folder->runner(ExtensionsFolder::pinsWith(['fixtures/core-lib' => '3.0.0'])));

    $this->artisan('teme:installer', ['--prepare-only' => true])
        ->expectsOutputToContain('held')
        ->assertExitCode(0);

    expect(RunInstaller::READY)->toBeFile()
        ->and($this->folder->store->current())->toBe($id);
})->group('composer');

it('carries the open request as far as it goes with --once, as the operator, and shows it is alive', function (): void {
    $id = strtolower((string) Str::ulid());
    DB::table('extension_requests')->insert([
        'id' => $id, 'action' => 'install', 'kind' => 'plugin', 'package' => 'fixtures/hello-extension', 'constraint' => '1.0.0',
        'source_kind' => ExtensionsFolder::SOURCE, 'status' => 'requested', 'requested_at' => now(),
    ]);
    app()->instance(InstallerRunner::class, $this->folder->runner());

    $this->artisan('teme:installer', ['--once' => true])->assertExitCode(0);

    $row = DB::table('extension_requests')->where('id', $id)->first();

    expect($row->status)->toBe('done', (string) $row->error)
        ->and($this->folder->store->current())->toBe($row->to_release)
        ->and(app(Heartbeats::class)->lastBeat(Heartbeats::INSTALLER))->not->toBeNull();
})->group('composer');

it('takes no step while another installer holds the lock', function (): void {
    config(['database.connections.another' => config('database.connections.'.config('database.default'))]);
    DB::connection('another')->select('SELECT pg_advisory_lock(hashtext(?))', [RunInstaller::LOCK]);
    $id = strtolower((string) Str::ulid());
    DB::table('extension_requests')->insert([
        'id' => $id, 'action' => 'install', 'kind' => 'plugin', 'package' => 'fixtures/hello-extension', 'constraint' => '1.0.0',
        'source_kind' => ExtensionsFolder::SOURCE, 'status' => 'requested', 'requested_at' => now(),
    ]);
    app()->instance(InstallerRunner::class, $this->folder->runner());

    $command = app(RunInstaller::class);
    $command->setLaravel(app());

    try {
        // One pass, without the start check, which would wait for the lock.
        $command->pass();
    } finally {
        DB::connection('another')->select('SELECT pg_advisory_unlock(hashtext(?))', [RunInstaller::LOCK]);
        DB::purge('another');
    }

    expect(DB::table('extension_requests')->where('id', $id)->value('status'))->toBe('requested');
});

it('runs the installer and the extensions commands with no plugin loaded', function (string $command): void {
    expect(SafeMode::forCommand($command))->toBeTrue();
})->with(['teme:installer', 'teme:extensions:pins', 'teme:extensions:caches']);

it('waits for the installer lock before the start check', function (): void {
    $id = $this->folder->install('fixtures/hello-extension', '1.0.0');
    $stamp = $this->folder->store->path($id).'/compat/'.ExtensionsFolder::pins()['hash'];
    unlink($stamp);
    app()->instance(InstallerRunner::class, $this->folder->runner());
    config(['database.connections.another' => config('database.connections.'.config('database.default'))]);
    DB::connection('another')->select('SELECT pg_advisory_lock(hashtext(?))', [RunInstaller::LOCK]);
    $stampedWhileHeld = null;

    Sleep::fake();
    // The other installer lets go during this one's first wait.
    Sleep::whenFakingSleep(function () use (&$stampedWhileHeld, $stamp): void {
        if ($stampedWhileHeld === null) {
            $stampedWhileHeld = is_file($stamp);
            DB::connection('another')->select('SELECT pg_advisory_unlock(hashtext(?))', [RunInstaller::LOCK]);
        }
    });

    try {
        $this->artisan('teme:installer', ['--prepare-only' => true])->assertExitCode(0);
    } finally {
        DB::purge('another');
    }

    expect($stampedWhileHeld)->toBeFalse()
        ->and($stamp)->toBeFile();
})->group('composer');

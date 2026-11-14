<?php

declare(strict_types=1);

use App\Domain\Backups\BackupSite;
use App\Domain\Backups\BackupTrigger;
use App\Domain\Backups\Manifest;
use App\Domain\Backups\Restore\MediaRestore;
use App\Domain\Backups\Restore\RestoreStopped;
use App\Domain\Backups\Tools\BackupSnapshots;
use App\Domain\Backups\Tools\Restic;
use App\Domain\Media\MediaDisks;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Backups\CommittedMedia;
use Tests\Fixtures\Backups\RunnableBackups;
use Tests\Fixtures\Backups\ScriptedRestic;
use Tests\Fixtures\Backups\TemporaryRepository;

uses(RefreshDatabase::class);

/** A backup of the media as it is now: its run at the first place, and its manifest. */
function mrBackUp(RunnableBackups $backups): array
{
    $result = app(BackupSite::class)->run(BackupTrigger::Command);
    expect($result->succeeded)->toBeTrue((string) $result->sentence);
    $run = $backups->runsAt(0)[0];
    $read = app(Restic::class)->run($backups->repositories[0]->repository, ['dump', $run->parts[BackupSnapshots::MANIFEST], BackupSnapshots::MANIFEST_FILE]);

    return [$run, Manifest::fromJson(json_encode($read->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
}

beforeEach(function (): void {
    app()->instance(Elevation::class, new OperatorElevation);
    $this->media = new CommittedMedia;
    $this->backups = null;
    $this->folders = [];
});

afterEach(function (): void {
    $this->backups?->delete();
    $this->media->delete();

    foreach ($this->folders as $folder) {
        TemporaryRepository::remove($folder);
    }
});

it('makes each media folder the backup’s exactly: missing files back, files it didn’t hold removed', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = $this->media->image();
    [$run, $manifest] = mrBackUp($this->backups);
    MediaDisks::originals()->delete("{$id}/1/original.jpg");
    MediaDisks::public()->put('stray/after-the-backup.webp', 'not in the backup');
    $pairs = MediaRestore::pairs($manifest);

    app(MediaRestore::class)->restore($this->backups->repositories[0]->repository, $run->parts[BackupSnapshots::MEDIA], $pairs['pairs'], 'Place 1');

    expect(MediaDisks::originals()->get("{$id}/1/original.jpg"))->toBe("original of {$id}")
        ->and(MediaDisks::public()->exists("{$id}/1/w640.webp"))->toBeTrue()
        ->and(MediaDisks::public()->exists('stray/after-the-backup.webp'))->toBeFalse();
});

it('restores into this server’s folders when the backup’s server kept them elsewhere', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    $id = $this->media->image();
    [$run, $manifest] = mrBackUp($this->backups);
    // This server keeps its media in other folders.
    foreach (['media' => 0755, 'media-originals' => 0700] as $disk => $mode) {
        $this->folders[] = $folder = TemporaryRepository::freshFolder();
        config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => $folder, 'throw' => true]]);
        Storage::forgetDisk($disk);
    }
    $pairs = MediaRestore::pairs($manifest);

    app(MediaRestore::class)->restore($this->backups->repositories[0]->repository, $run->parts[BackupSnapshots::MEDIA], $pairs['pairs'], 'Place 1');

    expect($pairs['pairs'][0]['to'])->toBe(rtrim(str_replace('\\', '/', $this->folders[1]), '/'))
        ->and(is_file($this->folders[1].DIRECTORY_SEPARATOR."{$id}/1/original.jpg"))->toBeTrue()
        ->and(is_file($this->folders[0].DIRECTORY_SEPARATOR."{$id}/1/thumb.webp"))->toBeTrue();
});

it('stops, in restic’s words, when a folder can’t be restored', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    [$run, $manifest] = mrBackUp($this->backups);
    $pairs = MediaRestore::pairs($manifest)['pairs'];
    $repository = $this->backups->repositories[0]->repository;

    ScriptedRestic::exits(3, ScriptedRestic::exitError(3, 'Fatal: some files couldn’t be restored'));
    expect(fn () => app(MediaRestore::class)->restore($repository, $run->parts[BackupSnapshots::MEDIA], $pairs, 'Place 1'))
        ->toThrow(RestoreStopped::class, 'restic couldn’t restore some media files from Place 1. The log has restic’s own words.');

    ScriptedRestic::exits(1, ScriptedRestic::exitError(1, 'Fatal: unable to open'));
    expect(fn () => app(MediaRestore::class)->restore($repository, $run->parts[BackupSnapshots::MEDIA], $pairs, 'Place 1'))
        ->toThrow(RestoreStopped::class, 'The backup tool couldn’t finish at Place 1.');
});

it('restores both disks with one call when they share a folder, and passes restic its flags', function (): void {
    $this->backups = RunnableBackups::atFolders(1);
    [$run, $manifest] = mrBackUp($this->backups);
    ScriptedRestic::exits(0);

    app(MediaRestore::class)->restore($this->backups->repositories[0]->repository, $run->parts[BackupSnapshots::MEDIA], [['role' => 'public', 'from' => '/srv/media', 'to' => TemporaryRepository::freshFolder()]], 'Place 1');

    Process::assertRanTimes(fn ($process): bool => in_array('--delete', (array) $process->command, true)
        && in_array('if-changed', (array) $process->command, true)
        && in_array($run->parts[BackupSnapshots::MEDIA].':/srv/media', (array) $process->command, true), 1);
});

<?php

declare(strict_types=1);

use App\Domain\Media\Jobs\DeleteMediaFiles;
use App\Domain\Media\MediaDisks;
use App\Domain\Media\VariantMaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Backups\OtherSession;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake((string) config('media.disk'));
    Storage::fake((string) config('media.originals_disk'));
    MediaDisks::public()->put('01JOLDITEM0000000000000000/1/w640.webp', 'webp file');
    MediaDisks::originals()->put('01JOLDITEM0000000000000000/1/original.jpg', 'original');
    $this->job = fn (): DeleteMediaFiles => (new DeleteMediaFiles([
        'public' => ['01JOLDITEM0000000000000000/1/w640.webp'],
        'originals' => ['01JOLDITEM0000000000000000/1/original.jpg'],
    ]))->withFakeQueueInteractions();
});

afterEach(function (): void {
    OtherSession::close();
});

it('waits while a backup holds the lock, deleting nothing, and asks again in a minute (I-358)', function (): void {
    OtherSession::holdBackupLock();
    $job = ($this->job)();

    app()->call([$job, 'handle']);

    $job->assertReleased(delay: 60);

    expect(MediaDisks::public()->exists('01JOLDITEM0000000000000000/1/w640.webp'))->toBeTrue()
        ->and(MediaDisks::originals()->exists('01JOLDITEM0000000000000000/1/original.jpg'))->toBeTrue();
});

it('deletes the files by their recorded paths once no backup runs', function (): void {
    $job = ($this->job)();

    app()->call([$job, 'handle']);

    $job->assertNotReleased();

    expect(MediaDisks::public()->exists('01JOLDITEM0000000000000000/1/w640.webp'))->toBeFalse()
        ->and(MediaDisks::originals()->exists('01JOLDITEM0000000000000000/1/original.jpg'))->toBeFalse();
});

it('waits a day at most, and fails after five real errors, never for waiting', function (): void {
    $job = new DeleteMediaFiles([]);

    expect($job->retryUntil()->getTimestamp())->toBe($job->until)
        ->and($job->until)->toBeGreaterThanOrEqual(now()->addDay()->subMinute()->getTimestamp())
        ->and($job->until)->toBeLessThanOrEqual(now()->addDay()->addMinute()->getTimestamp())
        ->and($job->maxExceptions)->toBe(5)
        ->and(DeleteMediaFiles::WAIT_SECONDS)->toBe(60);
});

it('queues a fresh copy on its last wait, so a deletion is never lost however long a backup runs', function (): void {
    Queue::fake();
    OtherSession::holdBackupLock();
    $job = ($this->job)();
    // A day of waiting has passed: one more release would outlast retryUntil().
    $this->travel(1)->days();

    app()->call([$job, 'handle']);

    $job->assertDeleted();
    $job->assertNotReleased();
    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $copy): bool => $copy->pathsByDisk === $job->pathsByDisk && $copy->until > $job->until);

    expect(MediaDisks::public()->exists('01JOLDITEM0000000000000000/1/w640.webp'))->toBeTrue();
});

it('deletes the variants a change no longer needs through the same wait, never at once', function (): void {
    Queue::fake();

    app(VariantMaker::class)->discard(['01JOLDITEM0000000000000000/1/w640.webp']);
    app(VariantMaker::class)->discard([]);

    Queue::assertPushed(DeleteMediaFiles::class, 1);
    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => $job->pathsByDisk === ['public' => ['01JOLDITEM0000000000000000/1/w640.webp']]);

    expect(MediaDisks::public()->exists('01JOLDITEM0000000000000000/1/w640.webp'))->toBeTrue();
});

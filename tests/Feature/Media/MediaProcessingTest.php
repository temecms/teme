<?php

declare(strict_types=1);

use App\Domain\Media\Exceptions\StaleMediaException;
use App\Domain\Media\Jobs\GenerateMediaVariants;
use App\Domain\Media\MediaVariants;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\RecordMediaVariantsInput;
use App\Domain\Media\Operations\Input\UploadMediaInput;
use App\Domain\Media\Operations\RecordMediaVariants;
use App\Domain\Media\Operations\RequestMediaProcessing;
use App\Domain\Media\Operations\UploadMedia;
use App\Domain\Media\VariantMaker;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
});

it('makes every variant after the upload commits, and marks the item ready', function (): void {
    // The sync queue runs the job as soon as the upload's transaction commits.
    $media = mediaUpload($this->author, mediaJpeg(1000, 500))->refresh();

    // toEqualCanonicalizing, not toBe: JSONB does not keep an object's keys
    // in the order they were written (B-072), so the five variant keys can
    // read back in any order.
    expect($media->processing)->toBe('ready')
        ->and($media->variant_set)->toBe(MediaVariants::SET)
        ->and(array_keys($media->variants))->toEqualCanonicalizing(['w320', 'w640', 'w960', 'w1000', 'thumb'])
        ->and($media->variants['w640'])->toMatchArray(['width' => 640, 'height' => 320]);

    foreach ($media->variants as $variant) {
        Storage::disk('media')->assertExists($variant['path']);
    }
});

it('does the same work twice without duplicating a file', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(700, 700))->refresh();
    $before = Storage::disk('media')->allFiles();

    (new GenerateMediaVariants($media->id, 1, JobActor::person($this->author->id)))->handle(app(VariantMaker::class), app(RecordMediaVariants::class));

    expect(Storage::disk('media')->allFiles())->toBe($before)
        ->and($media->refresh()->processing)->toBe('ready');
});

it('refuses to record variants for a file version that has been replaced', function (): void {
    $media = mediaUpload($this->author, mediaJpeg())->refresh();
    DB::table('media')->where('id', $media->id)->update(['file_version' => 2]);

    expect(fn () => app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($media->id, 1, MediaVariants::SET, [], null, (string) $this->author->id)))
        ->toThrow(StaleMediaException::class);
});

it('records a failure, and a retry queues the work again', function (): void {
    Queue::fake();
    $media = mediaUpload($this->author, mediaJpeg());

    app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($media->id, 1, MediaVariants::SET, null, 'Out of memory', (string) $this->author->id));

    expect($media->refresh()->processing)->toBe('failed')
        ->and($media->processing_error)->toBe('Out of memory');

    app(RequestMediaProcessing::class)->execute(new MediaActionInput($media->id, (string) $this->author->id));

    expect($media->refresh()->processing)->toBe('pending');
    Queue::assertPushed(GenerateMediaVariants::class, 2);
});

it('writes nothing when someone else\'s author records or retries', function (): void {
    Queue::fake();
    $media = mediaUpload($this->author, mediaJpeg());
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => app(RecordMediaVariants::class)->execute(new RecordMediaVariantsInput($media->id, 1, MediaVariants::SET, null, 'x', (string) $other->id)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestMediaProcessing::class)->execute(new MediaActionInput($media->id, (string) $other->id)))->toThrow(AuthorizationException::class)
        ->and($media->refresh()->processing)->toBe('pending');
});

it('records its own result for an operator upload, on a worker that never bound the operator', function (): void {
    // Simulates teme:media:add: the upload runs as the operator, but the
    // queue job that follows runs in a separate worker process, which never
    // binds OperatorElevation (docs/architecture/operations.md, "The
    // operator").
    $this->app->instance(Elevation::class, new OperatorElevation);
    $media = app(UploadMedia::class)->execute(
        new UploadMediaInput(mediaJpeg(), 'Holiday.jpg', null, false),
    );
    $this->app->instance(Elevation::class, new DeniedElevation);

    (new GenerateMediaVariants($media->id, 1, scElevated(fn (): JobActor => JobActor::operator())))->handle(app(VariantMaker::class), app(RecordMediaVariants::class));

    expect($media->refresh()->processing)->toBe('ready');
});

it('ends the operator\'s elevation with the job that bound it, so the worker\'s next job runs without it (issue 53)', function (): void {
    $this->app->instance(Elevation::class, new OperatorElevation);
    $media = app(UploadMedia::class)->execute(
        new UploadMediaInput(mediaJpeg(), 'Holiday.jpg', null, false),
    );
    $this->app->instance(Elevation::class, new DeniedElevation);

    // An operator's upload job, which binds the operator for its own work,
    // then any other job the same worker takes next.
    Queue::connection('database')->push(new GenerateMediaVariants($media->id, 1, scElevated(fn (): JobActor => JobActor::operator())));
    Queue::connection('database')->push(static function (): void {
        Cache::put('issue-53-probe', Operator::isPresent());
    });

    // One real worker, as queue:work runs in production. --memory=0: the
    // worker checks its memory after each job, and a test process over the
    // default 128 MB would stop it before the second job ran.
    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 0])->assertSuccessful();

    expect(Cache::get('issue-53-probe'))->toBeFalse();
});

it('regenerates every image made with an older variant set', function (): void {
    $media = mediaUpload($this->author, mediaJpeg(800, 600))->refresh();
    DB::table('media')->where('id', $media->id)->update(['variant_set' => 0, 'variants' => '{}']);

    $this->artisan('teme:media:regenerate')->assertSuccessful();

    expect($media->refresh()->variant_set)->toBe(MediaVariants::SET)
        ->and($media->variants)->toHaveKey('thumb');
});

it('queues an image\'s variants on the long connection and queue, which only the long worker takes', function (): void {
    // As production runs it: the long connection writes to the jobs table.
    config(['queue.connections.database-long.driver' => 'database']);
    $queued = [];

    Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued): void {
        $queued[] = [$event->connectionName, $event->queue, is_object($event->job) ? $event->job::class : (string) $event->job];
    });

    $media = mediaUpload($this->author, mediaJpeg(800, 600));

    expect($queued)->toBe([['database-long', 'long', GenerateMediaVariants::class]])
        ->and(DB::table('jobs')->pluck('queue')->all())->toBe(['long']);

    // The default worker leaves it.
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--memory' => 0])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($media->refresh()->processing)->toBe('pending');

    // The long worker makes the variants.
    $this->artisan('queue:work', ['connection' => 'database-long', '--queue' => 'long', '--once' => true, '--sleep' => 0, '--memory' => 0])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($media->refresh()->processing)->toBe('ready');
});

<?php

declare(strict_types=1);

use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Contents\Jobs\ReindexSearchJob;
use App\Domain\People\Credit;
use App\Domain\People\Operations\Input\UpdatePersonInput;
use App\Domain\People\Operations\UpdatePerson;
use App\Domain\Settings\Events\SettingsChanged;
use App\Domain\Support\Security\DeniedElevation;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\JobActor;
use App\Domain\Support\Security\Operator;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\People\Credited;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
});

it('carries a person by their ID, and binds nothing for them on a worker', function (): void {
    $actor = JobActor::person($this->admin->id);
    $actor->bind();

    expect($actor->userId)->toBe((string) $this->admin->id)
        ->and($actor->isOperator())->toBeFalse()
        ->and(Operator::isPresent())->toBeFalse()
        ->and(JobActor::of((string) $this->admin->id)->userId)->toBe((string) $this->admin->id);
});

it('refuses anything that isn\'t a person\'s ID', function (string $id): void {
    expect(fn () => JobActor::person($id))->toThrow(InvalidArgumentException::class, 'A job’s actor is a person’s ID, or the operator.');
})->with([
    'nothing' => [''],
    'a name' => ['ada'],
    'zero' => ['0'],
    'a negative number' => ['-4'],
    'a leading zero' => ['07'],
]);

it('refuses to make the operator\'s actor outside the operator\'s shell (I-392)', function (): void {
    expect(fn () => JobActor::operator())->toThrow(LogicException::class, 'Only the operator at the server’s shell queues work as the operator.')
        ->and(fn () => JobActor::of(null))->toThrow(LogicException::class, 'Only the operator at the server’s shell queues work as the operator.');
});

it('makes the operator\'s actor at the shell, and gives a worker the operator\'s elevation for that job', function (): void {
    $actor = scElevated(fn (): JobActor => JobActor::of(null));

    // A worker binds nothing but the default refusal; the payload carries the actor.
    app()->instance(Elevation::class, new DeniedElevation);
    $carried = unserialize(serialize($actor));
    $carried->bind();

    expect($carried->isOperator())->toBeTrue()
        ->and($carried->userId)->toBeNull()
        ->and(Operator::isPresent())->toBeTrue();
});

it('queues the address recompute for the operator\'s new address for a person, and runs it on a worker (I-392)', function (): void {
    addrChange($this->admin, ['patterns' => ['post' => '/{author}/{slug}/']]);
    $writer = User::factory()->create()->assignRole('author');
    $wire = Credited::guest('Wire', 'wire');
    $post = addrPublished($writer, 'Moving day');
    Credited::set($post->id, [Credit::person($wire)]);
    $before = addrCurrent('content', $post->id);

    Queue::fake();
    scElevated(fn () => app(UpdatePerson::class)->execute(new UpdatePersonInput(null, $wire, slug: 'wire-service')));

    $job = Queue::pushed(RecomputeAddresses::class)->first();
    expect($job)->not->toBeNull()
        ->and($job->actor->isOperator())->toBeTrue();

    app()->instance(Elevation::class, new DeniedElevation);
    cmRunJob($job);

    expect(addrCurrent('content', $post->id))->not->toBe($before)
        ->and(addrCurrent('content', $post->id))->toEndWith('/moving-day');
});

it('rebuilds search for a language change the operator made, on a worker (I-392)', function (): void {
    Queue::fake();
    scElevated(fn () => event(new SettingsChanged('general', ['language'], null)));

    $job = Queue::pushed(ReindexSearchJob::class)->first();
    expect($job)->not->toBeNull()
        ->and($job->actor->isOperator())->toBeTrue();

    app()->instance(Elevation::class, new DeniedElevation);

    expect(fn () => cmRunJob($job))->not->toThrow(Throwable::class);
});

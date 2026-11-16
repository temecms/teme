<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\FediverseActor;
use App\Domain\Fediverse\Models\Follower;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fediverse\ImportFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->companion = importFake();
    $this->companion->fediverse(ImportFixtures::whole(), [ImportFixtures::follower(40, 'https://remote.example/users/bob', [0])]);
    ImportFixtures::steps();
});

it('reports once that a companion on API 2 can’t move fediverse followers, never asks it, and writes nothing', function (): void {
    ImportFixtures::api($this->companion, 2);
    $run = ImportFixtures::run(importSource());

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->pluck('code')->all())->toBe(['fediverse.companion-too-old'])
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->value('message'))->toContain('update the companion')
        ->and($this->companion->requestsTo('fediverse'))->toBe([])
        ->and(FediverseActor::query()->count())->toBe(0)
        ->and(Follower::query()->count())->toBe(0);
});

it('asks a companion on API 3 for the plugin’s accounts once, then its followers and reply sources a page at a time', function (): void {
    ImportFixtures::api($this->companion, 3);
    $run = ImportFixtures::run(importSource());
    $asked = array_map(fn (array $request): array => $request['query'], $this->companion->requestsTo('fediverse'));

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($asked)->toBe([
            ['kind' => 'reply_sources', 'after_id' => '0', 'limit' => '500'],
            [],
            ['kind' => 'followers', 'after_id' => '0', 'limit' => '200'],
        ])
        ->and(collect($this->companion->requestsTo('fediverse'))->every(fn (array $request): bool => $request['signedIn']))->toBeTrue();
});

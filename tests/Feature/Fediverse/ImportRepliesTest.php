<?php

declare(strict_types=1);

use App\Domain\Fediverse\Models\FediverseObject;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Fediverse\ImportFixtures;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->companion = importFake();
    ImportFixtures::api($this->companion, 3);
    ImportFixtures::steps();
    $this->source = importSource();
});

it('links each imported fediverse reply to its comment, so a later Update from the fediverse finds it', function (): void {
    $item = ImportFixtures::post($this->source, 12);
    $reply = cmComment($item);
    $earlier = ImportRecords::run($this->source->id);
    ImportRecords::entry($this->source->id, $earlier, 'comment', '501', 'created', ['teme_type' => 'comment', 'teme_id' => $reply->id]);
    ImportRecords::entry($this->source->id, $earlier, 'comment', '503', 'skipped');
    $this->companion->fediverse(ImportFixtures::whole('blog', [ImportFixtures::actor(0, 'old.example')]), replySources: [
        ['id' => 501, 'source_id' => 'https://remote.example/users/bob/statuses/1'],
        ['id' => 502, 'source_id' => 'https://remote.example/users/bob/statuses/2'],
        ['id' => 503, 'source_id' => 'https://remote.example/users/bob/statuses/3'],
        ['id' => 504, 'source_id' => 'javascript:alert(1)'],
    ]);

    $run = ImportFixtures::run($this->source);
    // F2's reply operations find a reply's comment by the address its Update names.
    $found = FediverseObject::query()->whereKey('https://remote.example/users/bob/statuses/1')->where('kind', FediverseObject::REPLY)->value('comment_id');

    expect($found)->toBe($reply->id)
        ->and(FediverseObject::query()->where('kind', FediverseObject::REPLY)->count())->toBe(1)
        ->and(ImportMessageRecord::query()->where('run_id', $run->id)->pluck('code')->all())->toBe([]);
});

it('links a reply once, however many runs read it', function (): void {
    $item = ImportFixtures::post($this->source, 12);
    $reply = cmComment($item);
    ImportRecords::entry($this->source->id, ImportRecords::run($this->source->id), 'comment', '501', 'created', ['teme_type' => 'comment', 'teme_id' => $reply->id]);
    $this->companion->fediverse(ImportFixtures::whole('blog', [ImportFixtures::actor(0, 'old.example')]), replySources: [['id' => 501, 'source_id' => 'https://remote.example/users/bob/statuses/1']]);

    ImportFixtures::run($this->source);
    ImportFixtures::run($this->source);

    expect(FediverseObject::query()->where('kind', FediverseObject::REPLY)->pluck('comment_id')->all())->toBe([$reply->id]);
});

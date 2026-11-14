<?php

declare(strict_types=1);

use App\Domain\Ai\AiAvailability;
use App\Domain\Ai\AiCapability;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->contributor = User::factory()->create()->assignRole('contributor');
});

it('offers a task only while AI is on, its prompt holds, its model is chosen, ready and able, and the person holds ai.use (I-321)', function (string $case, bool $offered): void {
    $fake = aiFake();
    aiTask('fixture', [AiCapability::Text, AiCapability::ImagesIn], hash: $case === 'a prompt changed without a new version' ? str_repeat('0', 64) : null);
    aiChoose('fixture', match ($case) {
        'no model chosen' => '',
        'a provider no longer there' => 'gone:fake-vision',
        'a model no longer listed' => 'fake:retired',
        'a model that can\'t read images' => 'fake:fake-text',
        default => 'fake:fake-vision',
    });
    $fake->ready = $case !== 'a provider without its key';
    config(['teme.ai.enabled' => $case !== 'AI switched off']);
    $person = $case === 'a contributor' ? $this->contributor : $this->author;
    $availability = app(AiAvailability::class);

    expect($availability->enabled())->toBe($case !== 'AI switched off')
        ->and($availability->allows($person, 'fixture'))->toBe($offered)
        ->and(in_array('fixture', $availability->tasksFor($person), true))->toBe($offered)
        ->and($availability->taskReady('fixture'))->toBe($offered || $case === 'a contributor')
        ->and($availability->allows(null, 'fixture'))->toBeFalse()
        ->and($availability->allows($person, 'nothing'))->toBeFalse();
})->with([
    'everything in place' => ['everything in place', true],
    'AI switched off' => ['AI switched off', false],
    'a prompt changed without a new version' => ['a prompt changed without a new version', false],
    'no model chosen' => ['no model chosen', false],
    'a provider no longer there' => ['a provider no longer there', false],
    'a provider without its key' => ['a provider without its key', false],
    'a model no longer listed' => ['a model no longer listed', false],
    'a model that can\'t read images' => ['a model that can\'t read images', false],
    'a contributor' => ['a contributor', false],
]);

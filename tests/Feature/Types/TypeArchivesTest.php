<?php

declare(strict_types=1);

use App\Domain\Addresses\TypeArchives;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = ctAdmin();
    ltTypes();
});

it('gives each public type with an archive but the first an archive page of its own: Films, never Posts, never Recipes (I-3304)', function (): void {
    expect(TypeArchives::types())->toBe(['film'])
        ->and(TypeArchives::path('film'))->toBe('films')
        ->and(TypeArchives::path('post'))->toBeNull()
        ->and(TypeArchives::path('recipe'))->toBeNull()
        ->and(TypeArchives::at('films'))->toBe('film')
        ->and(TypeArchives::at('recipes'))->toBeNull()
        ->and(app(SettingsReader::class)->string('addresses', 'archive_film'))->toBe('films');
});

it('starts an archive page at the first word of its type’s own address, else at its admin address', function (): void {
    ctCreate(null, ['key' => 'reel', 'singular' => 'Reel', 'plural' => 'Reels', 'segment' => 'reels', 'icon' => 'film', 'pattern' => '/cinema/{slug}/']);
    ctCreate(null, ['key' => 'clip', 'singular' => 'Clip', 'plural' => 'Clips', 'segment' => 'clips', 'icon' => 'film', 'pattern' => '/{year}/{slug}/']);

    expect(TypeArchives::path('reel'))->toBe('cinema')
        ->and(TypeArchives::path('clip'))->toBe('clips');
});

it('drops a type’s archive page while its archive is ticked off, and gives it back at the address it had', function (): void {
    archMove($this->admin, ['film' => 'movies']);
    $update = fn (bool $archive) => scElevated(fn () => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition(['archive' => $archive]))));

    $update(false);

    expect(TypeArchives::types())->toBe([])
        ->and(TypeArchives::path('film'))->toBeNull()
        ->and(TypeArchives::at('movies'))->toBeNull();

    $update(true);

    expect(TypeArchives::path('film'))->toBe('movies');
});

it('keeps an old address for a type whose key is 32 characters long', function (): void {
    $key = 'documentary_features_from_abroad';
    ctCreate(null, ['key' => $key, 'singular' => 'Feature', 'plural' => 'Features', 'segment' => 'features', 'icon' => 'film']);

    archMove($this->admin, [$key => 'abroad']);

    expect(strlen($key))->toBe(32)
        ->and(DB::table('paths')->where('path', 'features')->first(['target_type', 'target_id', 'is_current']))
        ->toEqual((object) ['target_type' => 'type', 'target_id' => $key, 'is_current' => false]);
});

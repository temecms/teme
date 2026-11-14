<?php

declare(strict_types=1);

use App\Domain\Media\Models\Media;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    Queue::fake();
    Sleep::fake();
    // A closure: the same file is served twice, and each needs a body of its own.
    dlServe(['photos.example' => ['93.184.216.34'], 'inside.example' => ['10.0.0.5']], [
        'photos.example/*' => fn () => Http::response(dlJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->editor = User::factory()->create()->assignRole('editor');
});

it('answers with the new item, then with the same item for the same file (I-453)', function (): void {
    $first = $this->actingAs($this->editor)->postJson(route('media.from-address'), ['address' => 'https://photos.example/harbour.jpg'])->assertCreated();
    $second = $this->actingAs($this->editor)->postJson(route('media.from-address'), ['address' => 'https://photos.example/harbour.jpg'])->assertOk();

    expect($first->json('created'))->toBeTrue()
        ->and($second->json())->toBe(['id' => $first->json('id'), 'created' => false])
        ->and(Media::query()->sole()->id)->toBe($first->json('id'));
});

it('answers with the reason under the address', function (): void {
    $this->actingAs($this->editor)
        ->postJson(route('media.from-address'), ['address' => 'https://inside.example/a.jpg'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['address' => 'inside.example is on a private network, so Teme won’t connect to it.']);
});

it('refuses a visitor and anyone who may not upload, asking no site', function (): void {
    $this->postJson(route('media.from-address'), ['address' => 'https://photos.example/a.jpg'])->assertUnauthorized();

    $this->actingAs(User::factory()->create()->assignRole('contributor'))
        ->postJson(route('media.from-address'), ['address' => 'https://photos.example/a.jpg'])
        ->assertForbidden();

    expect(Media::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('sits behind media.upload, sixty a minute', function (): void {
    $route = app('router')->getRoutes()->getByName('media.from-address');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('admin/media/from-address')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('can:media.upload')
        ->and($route->gatherMiddleware())->toContain('throttle:60,1');
});

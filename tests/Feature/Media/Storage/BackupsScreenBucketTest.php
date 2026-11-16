<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Media\Storage\StorageEnvironment;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    Process::fake();
});

it('tells Tools › Backups when any media is in a bucket, and not otherwise (I-3456)', function (array $variables, bool $inBucket): void {
    StorageEnvironment::with($variables, function () use ($inBucket): void {
        $this->actingAs($this->admin)->get('/admin/tools/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('tools/backups/index')
                ->where('mediaInBucket', $inBucket));
    });
})->with([
    'on the server' => [[], false],
    'in the buckets' => [StorageEnvironment::bucket(), true],
    'originals on the server, the rest in the bucket' => [StorageEnvironment::bucket(['MEDIA_ORIGINALS_DISK' => 'media-originals']), true],
]);

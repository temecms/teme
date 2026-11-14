<?php

declare(strict_types=1);

use App\Domain\Media\Operations\Input\ReindexMediaUsagesInput;
use App\Domain\Media\Operations\ReindexMediaUsages;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->image = mediaUpload($this->author, mediaJpeg(400, 300));
    $this->content = refsDraft($this->author, refsFeatured($this->image));
});

it('re-derives lost usage rows from the revisions', function (): void {
    DB::table('media_usages')->delete();

    $this->artisan('teme:media:reindex-usages')->assertSuccessful();

    expect(refsRows($this->content))->toBe(['working' => $this->image->id]);
});

it('finds and fixes drift in the use counters', function (): void {
    DB::table('media')->where('id', $this->image->id)->update(['working_uses' => 9]);

    $this->artisan('teme:counts:rebuild --check')->assertFailed();
    $this->artisan('teme:counts:rebuild')->assertSuccessful();

    expect(refsUses($this->image))->toBe([1, 0]);
});

it('lets only the operator re-derive usage', function (): void {
    expect(fn () => app(ReindexMediaUsages::class)->execute(new ReindexMediaUsagesInput([$this->content->id])))
        ->toThrow(AuthorizationException::class);
});

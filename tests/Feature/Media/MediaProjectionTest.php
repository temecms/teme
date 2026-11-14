<?php

declare(strict_types=1);

use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\RestoreContent;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Contents\Operations\UnpublishContent;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->harbour = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    $this->market = mediaUpload($this->author, mediaJpeg(401, 300), 'market.jpg');
});

it('records a draft\'s featured image as a working use', function (): void {
    $content = refsDraft($this->author, refsFeatured($this->harbour));

    expect(refsRows($content))->toBe(['working' => $this->harbour->id])
        ->and(refsUses($this->harbour))->toBe([1, 0]);
});

it('adds the published use on publish, and keeps it while the draft moves on', function (): void {
    $content = refsDraft($this->author, refsFeatured($this->harbour));
    listsPublish($content, $this->author);

    refsSave($content, $this->author, refsFeatured($this->market));

    expect(refsRows($content))->toBe(['published' => $this->harbour->id, 'working' => $this->market->id])
        ->and(refsUses($this->harbour))->toBe([0, 1])
        ->and(refsUses($this->market))->toBe([1, 0]);
});

it('drops every use on trash and unpublish, and brings them back on restore', function (): void {
    $content = refsDraft($this->author, refsFeatured($this->harbour));
    listsPublish($content, $this->author);

    app(UnpublishContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    expect(refsUses($this->harbour))->toBe([1, 0]);

    app(TrashContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    expect(refsRows($content))->toBe([])
        ->and(refsUses($this->harbour))->toBe([0, 0]);

    app(RestoreContent::class)->execute(new ContentActionInput($content->id, (string) $this->author->id));
    expect(refsUses($this->harbour))->toBe([1, 0]);
});

it('skips a reference to an item deleted for good', function (): void {
    $content = refsDraft($this->author, refsFeatured($this->harbour));

    // Deleting the row cascades its usage rows away; the revision keeps the ID.
    DB::table('media')->where('id', $this->harbour->id)->delete();
    listsPublish($content, $this->author);

    expect(refsRows($content))->toBe([]);
});

<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\MediaUrls;
use App\Domain\Preferences\PreferenceRegistry;
use App\Domain\Preferences\Queries\PreferencesFor;
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
    $this->editor = User::factory()->create()->assignRole('editor');

    // A post with this image as its featured image.
    $this->featuring = function (string $title, ?string $mediaId): mixed {
        $post = lifecycleDraft($this->editor, $title);

        return $mediaId === null ? $post : docSave($post, $this->editor, docWith(docText('A caption.')), [
            BuiltInFields::FEATURED_IMAGE => ['id' => $mediaId, 'alt' => '', 'decorative' => false],
        ]);
    };
});

it('shows thumbnails on each list by default, until the person turns them off', function (): void {
    $registry = app(PreferenceRegistry::class);

    expect($registry->get('lists.post.columns')->default)->toContain('featured')
        ->and($registry->get('lists.page.columns')->default)->toContain('featured')
        ->and(PreferencesFor::user($this->editor)['lists.post.columns'])->toContain('featured');

    lscrPrefer($this->editor, ['lists.post.columns' => ['author', 'date']]);

    expect(PreferencesFor::user($this->editor)['lists.post.columns'])->toBe(['author', 'date']);
});

it('gives each row its featured image\'s smallest variant, never the original, and none to a row without one', function (): void {
    $image = mediaUpload($this->editor, mediaJpeg(1600, 900), 'quay.jpg');
    ($this->featuring)('Harbour news', $image->id);
    ($this->featuring)('Ferry fares', null);

    $response = $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();
    $rows = collect($response->inertiaProps('rows'))->keyBy('title');
    $variants = json_decode((string) DB::table('media')->where('id', $image->id)->value('variants'), true);

    expect($rows['Harbour news']['featuredImage']['thumbUrl'])->toBe(MediaUrls::variant($variants))
        ->and($rows['Ferry fares']['featuredImage'])->toBeNull()
        ->and($response->getContent())->not->toContain((string) DB::table('media')->where('id', $image->id)->value('original_path'));
});

it('reads the featured images in the same queries for one row as for twenty', function (): void {
    $image = mediaUpload($this->editor, mediaJpeg(800, 600), 'quay.jpg');
    ($this->featuring)('Post 1', $image->id);

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->editor)->get(route('content.index', ['type' => 'posts']))->assertOk();

        return count(DB::getQueryLog());
    };

    $count(); // warms the permission cache
    $one = $count();

    foreach (range(2, 20) as $n) {
        ($this->featuring)("Post {$n}", $image->id);
    }

    expect($count())->toBe($one);
});

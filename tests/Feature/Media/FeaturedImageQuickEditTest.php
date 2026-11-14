<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->image = mediaUpload($this->author, mediaJpeg(400, 300), 'harbour.jpg');
    $this->content = lifecycleDraft($this->author, 'Harbour news');
});

function refsQuickEdit(object $test, array $data): TestResponse
{
    $test->content->refresh();

    return $test->actingAs($test->author)->patch(route('content.details', ['type' => 'posts', 'content' => $test->content->id]), [
        'expected_revision_id' => $test->content->working_revision_id,
        ...$data,
    ]);
}

it('sets a featured image from quick edit, and shows it on the list row', function (): void {
    refsQuickEdit($this, ['featured_image_id' => $this->image->id, 'featured_image_alt' => 'Boats'])->assertSessionHasNoErrors();

    $this->actingAs($this->author)->get(route('content.index', ['type' => 'posts']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.featuredImage.id', $this->image->id)
            ->where('rows.0.featuredImage.alt', 'Boats')
            ->where('rows.0.featuredImage.decorative', false));

    expect($this->content->refresh()->workingRevision->fields[BuiltInFields::FEATURED_IMAGE])->toBe(['id' => $this->image->id, 'alt' => 'Boats', 'decorative' => false])
        ->and(refsUses($this->image))->toBe([1, 0]);
});

it('clears the featured image with an empty choice', function (): void {
    refsQuickEdit($this, ['featured_image_id' => $this->image->id]);
    refsQuickEdit($this, ['featured_image_id' => '']);

    expect($this->content->refresh()->workingRevision->fields[BuiltInFields::FEATURED_IMAGE])->toBeNull()
        ->and(refsUses($this->image))->toBe([0, 0]);
});

it('refuses to newly choose an image that is in the trash', function (): void {
    app(TrashMedia::class)->execute(new MediaActionInput($this->image->id, (string) $this->author->id));

    refsQuickEdit($this, ['featured_image_id' => $this->image->id])->assertSessionHasErrors();
});

it('says on publish which images have no alt text, and publishes anyway', function (): void {
    refsQuickEdit($this, ['featured_image_id' => $this->image->id]);
    $this->content->refresh();

    $this->actingAs($this->author)
        ->post(route('content.action', ['type' => 'posts', 'content' => $this->content->id, 'action' => 'publish']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Published. This image has no alt text: harbour.');

    expect($this->content->refresh()->status)->toBe('published');
});

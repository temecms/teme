<?php

declare(strict_types=1);

use App\Domain\Users\Operations\Input\SetPictureInput;
use App\Domain\Users\Operations\SetPicture;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create(['name' => 'Bea Author'])->assignRole('author');
});

function accountPictureGive(User $user): string
{
    $image = pageImage($user, 400, 400);
    app(SetPicture::class)->execute(new SetPictureInput((string) $user->id, $image->id, (string) $user->id));

    return $image->id;
}

it('hints that the site’s default picture is chosen under Settings › People', function (): void {
    expect(uiComponent('components/account/picture-panel.tsx'))
        ->toContain('The site’s default is chosen in Settings › People.');
});

it('changes your own picture and says so', function (): void {
    $image = pageImage($this->author, 400, 400);

    $this->actingAs($this->author)
        ->put('/admin/account/picture', ['media' => $image->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Picture changed.']);

    expect($this->author->refresh()->picture_media_id)->toBe($image->id);
});

it('removes your own picture', function (): void {
    accountPictureGive($this->author);

    $this->actingAs($this->author)
        ->delete('/admin/account/picture')
        ->assertRedirect(route('profile.edit'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Picture removed. Your initials show instead.']);

    expect($this->author->refresh()->picture_media_id)->toBeNull();
});

it('refuses a file that is not an image, keeping the picture', function (): void {
    $imageId = accountPictureGive($this->author);
    $document = mediaUpload($this->author, mediaPdf(), 'Terms.pdf');

    $this->actingAs($this->author)
        ->from('/admin/account/profile')
        ->put('/admin/account/picture', ['media' => $document->id])
        ->assertSessionHasErrors(['media' => 'Choose an image from Media.']);

    expect($this->author->refresh()->picture_media_id)->toBe($imageId);
});

it('lets someone who manages users remove another person’s picture, and no one else, writing nothing when refused', function (): void {
    $imageId = accountPictureGive($this->author);
    $editor = User::factory()->create()->assignRole('editor');
    $admin = User::factory()->create()->assignRole('administrator');

    $this->actingAs($editor)->delete("/admin/users/{$this->author->id}/picture")->assertForbidden();
    expect($this->author->refresh()->picture_media_id)->toBe($imageId);

    $this->actingAs($admin)
        ->delete("/admin/users/{$this->author->id}/picture")
        ->assertRedirect(route('users.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Removed the picture of Bea Author.']);
    expect($this->author->refresh()->picture_media_id)->toBeNull();

    $this->actingAs($admin)
        ->delete("/admin/users/{$this->author->id}/picture")
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'Bea Author has no picture.']);
});

it('offers no way to choose another person’s picture', function (): void {
    $admin = User::factory()->create()->assignRole('administrator');
    $image = pageImage($admin, 400, 400);

    $status = $this->actingAs($admin)->put("/admin/users/{$this->author->id}/picture", ['media' => $image->id])->getStatusCode();

    expect($status)->toBeIn([404, 405])
        ->and($this->author->refresh()->picture_media_id)->toBeNull();
});

<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->media = mediaUpload($this->author, mediaJpeg(), 'harbour.jpg')->refresh();

    // Saves the details as they stood at upload, with these changes, as the drawer sends them.
    $this->save = fn (array $changes, ?User $actor = null) => app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput(...[
        'mediaId' => $this->media->id,
        'actorId' => (string) ($actor ?? $this->author)->id,
        'title' => $this->media->title,
        'alt' => $this->media->alt,
        'caption' => $this->media->caption,
        'credit' => $this->media->credit,
        'focalX' => $this->media->focal_x,
        'focalY' => $this->media->focal_y,
        ...$changes,
    ]));
});

it('keeps a licence beside the credit, trimmed, with empty meaning none (I-236)', function (): void {
    $saved = ($this->save)(['credit' => 'Photo: Kemi Adeyemi', 'licence' => '  Staff photo  ']);

    expect([$saved->credit, $saved->licence])->toBe(['Photo: Kemi Adeyemi', 'Staff photo'])
        ->and(($this->save)(['licence' => '   '])->licence)->toBeNull();
});

it('keeps the licence when a save doesn’t name it, AI’s alt text among them', function (): void {
    ($this->save)(['licence' => 'Staff photo']);

    // Built without a licence, as every caller before this part was.
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->media->id, (string) $this->author->id, 'Harbour', 'Boats at dawn', null, null));

    expect($this->media->refresh()->only(['title', 'alt', 'licence']))->toBe(['title' => 'Harbour', 'alt' => 'Boats at dawn', 'licence' => 'Staff photo']);

    $fake = altReady();
    $fake->answer(altAnswer('Fishing boats moored at dawn.'));
    $id = altAsk($this->author, $this->media)->assertStatus(202)->json('id');
    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))->assertOk();

    expect($this->media->refresh()->only(['alt', 'licence']))->toBe(['alt' => 'Fishing boats moored at dawn.', 'licence' => 'Staff photo'])
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Applied);
});

it('refuses a licence longer than 255 characters, and writes nothing', function (): void {
    ($this->save)(['licence' => 'Staff photo']);

    expect(fn () => ($this->save)(['caption' => 'Boats at dawn', 'licence' => str_repeat('a', 256)]))->toThrow(ValidationException::class)
        ->and($this->media->refresh()->only(['caption', 'licence']))->toBe(['caption' => null, 'licence' => 'Staff photo']);
});

it('writes no licence for someone who may not change the file', function (): void {
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => ($this->save)(['licence' => 'Taken'], $other))->toThrow(AuthorizationException::class)
        ->and($this->media->refresh()->licence)->toBeNull();
});

it('saves the licence from the drawer and answers with it, keeps it when a save leaves it out, and clears it when sent empty', function (): void {
    $fields = ['title' => 'Harbour', 'alt' => '', 'caption' => '', 'credit' => 'Photo: Kemi Adeyemi', 'focal_x' => 50, 'focal_y' => 50];
    $patch = fn (array $body) => $this->actingAs($this->author)->patchJson(route('media.update', ['media' => $this->media->id]), $body)->assertOk();

    $patch([...$fields, 'licence' => 'Staff photo'])
        ->assertJsonPath('licence', 'Staff photo')
        ->assertJsonPath('credit', 'Photo: Kemi Adeyemi');

    // The editor's media window saved before it knew of a licence.
    $patch([...$fields, 'caption' => 'Boats at dawn'])
        ->assertJsonPath('licence', 'Staff photo')
        ->assertJsonPath('caption', 'Boats at dawn');

    $patch([...$fields, 'licence' => ''])->assertJsonPath('licence', null);
});

it('says when a file went to the trash, in its details', function (): void {
    // assertJson, not assertJsonPath: a missing key would read as null and pass.
    $this->actingAs($this->author)->getJson(route('media.show', ['media' => $this->media->id]))
        ->assertOk()
        ->assertJson(['trashedAt' => null]);

    app(TrashMedia::class)->execute(new MediaActionInput($this->media->id, (string) $this->author->id));

    expect($this->actingAs($this->author)->getJson(route('media.show', ['media' => $this->media->id]))->json('trashedAt'))
        ->toBeString()
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

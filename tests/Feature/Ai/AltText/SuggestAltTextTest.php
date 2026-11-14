<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Tasks\AltText\AltTextTask;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Operations\Input\MediaActionInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\TrashMedia;
use App\Domain\Media\Operations\UpdateMediaDetails;
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
    $this->author = User::factory()->create(['name' => 'Ada Author'])->assignRole('author');
    $this->editor = User::factory()->create(['name' => 'Eze Editor'])->assignRole('editor');
    $this->media = altImage($this->author);
    $this->fake = altReady();
    $this->details = fn (User $viewer, ?Media $media = null) => $this->actingAs($viewer)->getJson(route('media.show', ['media' => ($media ?? $this->media)->id]));
});

it('offers alt text in an image’s details to whoever may ask and may change it, and says where the image goes (I-321, I-1304)', function (): void {
    ($this->details)($this->author)->assertOk()->assertJsonPath('altText', ['sentTo' => ['Fake AI'], 'hourlyLimit' => 60]);
    ($this->details)($this->editor)->assertOk()->assertJsonPath('altText.sentTo', ['Fake AI']);

    // Every answer the details get carries it, a save's too.
    $this->actingAs($this->author)
        ->patchJson(route('media.update', ['media' => $this->media->id]), ['title' => 'Harbour', 'alt' => '', 'caption' => '', 'credit' => '', 'focal_x' => 50, 'focal_y' => 50])
        ->assertOk()
        ->assertJsonPath('altText.hourlyLimit', 60);
});

it('offers nothing when AI can’t describe this image for this person', function (string $case): void {
    $media = $this->media;

    match ($case) {
        'AI switched off on the server' => config(['teme.ai.enabled' => false]),
        'no model chosen' => aiChoose('alt-text', ''),
        'a model that can’t read images' => aiChoose('alt-text', 'fake:fake-text'),
        'someone else’s image, to an author' => $media = altImage($this->editor, 1000, 700),
        'a document' => $media = mediaUpload($this->author, mediaPdf(), 'Fares.pdf'),
        'an image whose processing failed' => DB::table('media')->where('id', $media->id)->update(['variants' => '{}', 'processing' => 'failed']),
        'an image in the trash' => app(TrashMedia::class)->execute(new MediaActionInput($media->id, (string) $this->author->id)),
    };

    // assertJson, not assertJsonPath: a missing key would read as null and pass.
    ($this->details)($this->author, $media)->assertOk()->assertJson(['altText' => null]);
})->with([
    'AI switched off on the server',
    'no model chosen',
    'a model that can’t read images',
    'someone else’s image, to an author',
    'a document',
    'an image whose processing failed',
    'an image in the trash',
]);

it('asks with the image’s copy, collects the suggestion, and Use writes it as the image’s alt text (I-317, I-319)', function (): void {
    $this->fake->answer(altAnswer('Fishing boats moored in the harbour at dawn.'));

    $id = altAsk($this->author, $this->media)->assertStatus(202)->json('id');
    $sent = $this->fake->requests[0];

    aiCollect($this->author, $id)->assertOk()->assertJson(['state' => 'ready', 'values' => ['text' => 'Fishing boats moored in the harbour at dawn.'], 'sentTo' => ['Fake AI']]);

    expect($sent->model)->toBe('fake-vision')
        ->and($sent->timeoutSeconds)->toBe(45)
        ->and($sent->images)->toHaveCount(1)
        ->and($sent->images[0]->mediaType)->toBe('image/webp')
        ->and($sent->messages[0]->text)->toStartWith(AltTextTask::INSTRUCTIONS)
        ->and($sent->messages[1]->text)->toBe('<input name="title">Holiday</input>'."\n".'<input name="language">English</input>');

    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))->assertOk()->assertJson(['revisionId' => null]);

    expect($this->media->refresh()->alt)->toBe('Fishing boats moored in the harbour at dawn.')
        ->and(AiActivity::query()->findOrFail($id)->only(['task', 'subject_id', 'outcome']))
        ->toBe(['task' => 'alt-text', 'subject_id' => $this->media->id, 'outcome' => AiOutcome::Applied]);
});

it('refuses Use once the alt text changed after the asking, and leaves it as it is now', function (): void {
    $this->fake->answer(altAnswer('Fishing boats at dawn.'));
    $id = altAsk($this->author, $this->media)->json('id');

    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($this->media->id, (string) $this->author->id, 'Holiday', 'Typed by hand', null, null));

    $this->actingAs($this->author)->postJson(route('ai.requests.apply', $id))
        ->assertStatus(409)
        ->assertJson(['outOfDate' => true, 'message' => 'This image’s alt text changed after you asked. Ask again.']);

    expect($this->media->refresh()->alt)->toBe('Typed by hand')
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Stale);
});

it('refuses to ask about an image this person may not change, and sends nothing', function (): void {
    altAsk($this->author, altImage($this->editor, 1000, 700))->assertForbidden();

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->count())->toBe(0);
});

it('ends a request about an image without a copy as gone, sending and recording nothing', function (): void {
    DB::table('media')->where('id', $this->media->id)->update(['variants' => '{}', 'processing' => 'failed']);

    $id = altAsk($this->author, $this->media)->assertStatus(202)->json('id');

    aiCollect($this->author, $id)->assertOk()->assertJson(['state' => 'failed', 'message' => 'This was deleted before AI could answer.']);

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->count())->toBe(0);
});

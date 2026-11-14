<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiTasks;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Tasks\AltText\AltTextTask;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Media\Operations\Input\UpdateMediaDetailsInput;
use App\Domain\Media\Operations\UpdateMediaDetails;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    $this->author = User::factory()->create()->assignRole('author');
    $this->task = app(AiTasks::class)->get('alt-text');
    // A draft or a published item showing the image as its featured image.
    $this->showing = fn (string $title, $media) => app(CreateDraft::class)->execute(new CreateDraftInput('post', $title, ['type' => 'doc', 'content' => []], refsFeatured($media), (string) $this->author->id));
});

it('registers the alt-text task for images, needing a model that reads them, with its prompt pinned (I-316, I-319)', function (): void {
    $prompt = $this->task->prompt();

    expect($this->task)->toBeInstanceOf(AltTextTask::class)
        ->and($this->task->key())->toBe('alt-text')
        ->and($this->task->label())->toBe('Alt text')
        ->and($this->task->subject())->toBe(AiSubjectType::Media)
        ->and($this->task->capabilities())->toBe([AiCapability::Text, AiCapability::ImagesIn])
        ->and($this->task->timeoutSeconds())->toBe(45)
        ->and($this->task->maxOutputTokens())->toBe(200)
        ->and($this->task->outputSchema())->toBeNull()
        ->and($this->task->appliedBy())->toBe(UpdateMediaDetails::class)
        ->and([$prompt->key, $prompt->version])->toBe(['alt-text', 1])
        ->and($prompt->hash)->toBe('c7aa80356eda6a2d0ae388aea04f1f54c2714d2de42374fa1f58b535c03fcfd5')
        ->and(AiPrompt::hashOf(AltTextTask::INSTRUCTIONS))->toBe($prompt->hash)
        ->and($prompt->matches())->toBeTrue()
        ->and(mb_strlen(AltTextTask::INSTRUCTIONS))->toBe(1100);
});

it('tells the model the image’s title and caption and the latest published title that shows it, and sends its copy (I-319)', function (): void {
    $media = altImage($this->author);
    app(UpdateMediaDetails::class)->execute(new UpdateMediaDetailsInput($media->id, (string) $this->author->id, 'Bonny harbour', null, 'Boats at dawn', null));

    listsPublish(($this->showing)('Harbour reopens', $media), $this->author);
    $this->travel(1)->minutes();
    listsPublish(($this->showing)('Ferry fares rise', $media), $this->author);
    // A draft may be someone's unpublished work: never told.
    ($this->showing)('Draft about the ferry', $media);

    $input = $this->task->input(new AiSubject(AiSubjectType::Media, $media->id, target: 'alt'));

    expect($input->fields)->toBe(['title' => 'Bonny harbour', 'caption' => 'Boats at dawn', 'article_title' => 'Ferry fares rise'])
        ->and($input->images)->toHaveCount(1)
        ->and($input->images[0]->mediaType)->toBe('image/webp');
});

it('leaves out a caption the image hasn’t, and a title when only a draft shows it', function (): void {
    $media = altImage($this->author);
    ($this->showing)('Draft about the ferry', $media);

    expect($this->task->input(new AiSubject(AiSubjectType::Media, $media->id, target: 'alt'))->fields)->toBe(['title' => 'Holiday']);
});

it('takes an answer as one line of alt text without wrapping quotation marks, and refuses one empty or far too long (I-316)', function (string $answer, ?string $expected): void {
    $check = fn (): array => $this->task->check(new AiResult('fake-vision', new AiUsage(1_500, 30), 900, text: $answer));

    if ($expected === null) {
        expect($check)->toThrow(AiFailure::class);

        return;
    }

    expect($check())->toBe(['text' => $expected]);
})->with([
    'as written' => ['Fishing boats moored at dawn.', 'Fishing boats moored at dawn.'],
    'spaces and line breaks' => ["  Fishing boats\n moored at dawn.  ", 'Fishing boats moored at dawn.'],
    'straight quotation marks' => ['"Fishing boats moored at dawn."', 'Fishing boats moored at dawn.'],
    'typographic quotation marks' => ['“Fishing boats moored at dawn.”', 'Fishing boats moored at dawn.'],
    'a quotation inside' => ['A sign reading “Ferry” by the quay', 'A sign reading “Ferry” by the quay'],
    'as long as is used' => [str_repeat('a', 250), str_repeat('a', 250)],
    'empty' => ['   ', null],
    'far too long' => [str_repeat('a', 251), null],
]);

it('refuses an answer that came back as data rather than text', function (): void {
    expect(fn () => $this->task->check(new AiResult('fake-vision', new AiUsage(1_500, 30), 900, data: ['text' => 'Boats'])))
        ->toThrow(AiFailure::class);
});

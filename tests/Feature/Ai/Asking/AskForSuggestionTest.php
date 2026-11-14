<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Asking\Jobs\AnswerAiRequest;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Ai\UsageLimits;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    // The second paragraph, "Fares rise on Monday.", is 21 units long.
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = aiReady();
});

it('asks for a suggestion about a paragraph and lets the page collect it (I-317)', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));

    $id = aiAsk($this->author, $this->item, '1')->assertStatus(202)->json('id');

    expect($id)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/');
    aiCollect($this->author, $id)->assertOk()->assertExactJson(['state' => 'ready', 'values' => ['text' => 'Fares rise Monday.'], 'sentTo' => ['Fake AI']]);
});

it('queues IDs and offsets only, so no queued row holds the article\'s words, and shows the request working meanwhile (I-320)', function (): void {
    Queue::fake();

    $id = aiAsk($this->author, $this->item, '1', ['variant' => 'shorter'])->assertStatus(202)->json('id');

    Queue::assertPushed(AnswerAiRequest::class, fn (AnswerAiRequest $job): bool => $job->id === $id
        && $job->options === ['from' => 0, 'to' => 21, 'variant' => 'shorter']
        && ! str_contains(json_encode(get_object_vars($job), JSON_THROW_ON_ERROR), 'Fares'));
    aiCollect($this->author, $id)->assertOk()->assertExactJson(['state' => 'working', 'sentTo' => ['Fake AI']]);
});

it('refuses someone without ai.use, or without the right to change the item, queueing nothing (I-312)', function (string $case): void {
    Queue::fake();
    $person = User::factory()->create()->assignRole($case === 'a contributor' ? 'contributor' : 'author');

    aiAsk($person, $this->item, '1')->assertForbidden();

    Queue::assertNothingPushed();
    expect(AiActivity::query()->count())->toBe(0);
})->with(['a contributor', 'another author']);

it('tells the person plainly when AI isn\'t available for the task or the month\'s budget is spent', function (string $case): void {
    Queue::fake();

    if ($case === 'no model') {
        aiChoose('fixture', '');
    } else {
        cmSettings([UsageLimits::BUDGET => 1], AiSettings::KEY);
        app(RecordAiActivity::class)->execute(new RecordAiActivityInput(
            id: strtolower((string) Str::ulid()),
            task: 'fixture',
            prompt: new AiPrompt('fixture', 1, 'Shorten the text.', AiPrompt::hashOf('Shorten the text.')),
            subjectType: AiSubjectType::Content,
            subjectId: $this->item->id,
            outcome: AiOutcome::Suggested,
            provider: 'fake',
            model: 'fake-text',
            usage: new AiUsage(input: 10),
            costMicrounits: 1_000_000,
            latencyMs: 100,
            actorId: (string) $this->author->id,
        ));
    }

    aiAsk($this->author, $this->item, '1')->assertStatus(409)->assertExactJson(['message' => $case === 'no model'
        ? 'AI isn’t available for this right now.'
        : 'This month’s AI budget has been spent, so AI is unavailable until the month ends.']);

    Queue::assertNothingPushed();
})->with(['no model', 'the budget spent']);

it('holds each person to the hourly limit (I-320)', function (): void {
    cmSettings([UsageLimits::HOURLY => 1], AiSettings::KEY);
    $this->fake->answer(aiText('Fares rise Monday.'));

    aiAsk($this->author, $this->item, '1')->assertStatus(202);
    aiAsk($this->author, $this->item, '1')->assertStatus(429);
});

it('refuses a malformed request with a message for developers, queueing nothing', function (string $case, string $message): void {
    Queue::fake();
    Storage::fake('media');
    Storage::fake('media-originals');
    aiReady('fixture-alt', AiSubjectType::Media);
    $body = ['task' => 'fixture', 'subject_type' => 'content', 'subject_id' => $this->item->id, 'revision_id' => $this->item->working_revision_id, 'target' => '1', 'options' => ['from' => 0, 'to' => 21]];

    $body = match ($case) {
        'a path to no paragraph' => [...$body, 'target' => '5'],
        'a selection outside the paragraph' => [...$body, 'options' => ['from' => 0, 'to' => 99]],
        'another item\'s revision' => [...$body, 'revision_id' => aiDraft($this->author, 'Ferry times.')->working_revision_id],
        'nine options' => [...$body, 'options' => array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], 1)],
        'an image\'s task about an item' => [...$body, 'task' => 'fixture-alt'],
        default => ['task' => 'fixture-alt', 'subject_type' => 'media', 'subject_id' => mediaUpload($this->author, mediaJpeg())->id, 'options' => []],
    };

    $this->actingAs($this->author)->postJson(route('ai.requests.store'), $body)->assertStatus(422)->assertExactJson(['message' => $message]);

    // The image's upload queues its own variants; no AI request is queued.
    Queue::assertNotPushed(AnswerAiRequest::class);
})->with([
    'a path to no paragraph' => ['a path to no paragraph', 'AI can be asked about a paragraph or a heading, by its path.'],
    'a selection outside the paragraph' => ['a selection outside the paragraph', 'The selection [0, 99] isn\'t inside that paragraph.'],
    'another item\'s revision' => ['another item\'s revision', 'The revision isn\'t one of this item\'s.'],
    'nine options' => ['nine options', 'An AI request has at most eight options, each a short word or a number.'],
    'an image\'s task about an item' => ['an image\'s task about an item', 'AI task [fixture-alt] isn\'t asked about this kind of thing.'],
    'an image, but not its alt text' => ['an image, but not its alt text', 'An image\'s suggestion is for its alt text.'],
]);

it('shows a request only to the person who asked', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));
    $id = aiAsk($this->author, $this->item, '1')->json('id');
    $editor = User::factory()->create()->assignRole('editor');

    aiCollect($editor, $id)->assertNotFound();
    aiCollect($this->author, strtolower((string) Str::ulid()))->assertNotFound();
    aiCollect($this->author, $id)->assertOk();
});

it('cancels a request still working, so its job asks no model and records nothing', function (): void {
    Queue::fake();
    $id = aiAsk($this->author, $this->item, '1')->json('id');

    $this->actingAs($this->author)->deleteJson(route('ai.requests.destroy', $id))->assertNoContent();
    app()->call([Queue::pushed(AnswerAiRequest::class)->first(), 'handle']);

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->count())->toBe(0);
    aiCollect($this->author, $id)->assertNotFound();
});

it('puts aside a ready suggestion, settling it as discarded', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));
    $id = aiAsk($this->author, $this->item, '1')->json('id');

    $this->actingAs($this->author)->deleteJson(route('ai.requests.destroy', $id))->assertNoContent();

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Discarded);
    aiCollect($this->author, $id)->assertNotFound();
});

it('lets no one else put a request aside, changing nothing', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));
    $id = aiAsk($this->author, $this->item, '1')->json('id');
    $editor = User::factory()->create()->assignRole('editor');

    $this->actingAs($editor)->deleteJson(route('ai.requests.destroy', $id))->assertForbidden();

    expect(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Suggested);
    aiCollect($this->author, $id)->assertOk();
});

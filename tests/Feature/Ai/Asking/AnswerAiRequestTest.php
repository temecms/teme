<?php

declare(strict_types=1);

use App\Domain\Ai\AiCapability;
use App\Domain\Ai\AiFailure;
use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiModel;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrice;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiProviders;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiSubject;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Asking\AiSuggestions;
use App\Domain\Ai\Asking\AiTargets;
use App\Domain\Ai\Asking\Jobs\AnswerAiRequest;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Queries\AiSpend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    // The second paragraph, "Fares rise on Monday.", is 21 units long.
    $this->item = aiDraft($this->author, 'The ferry is late.', 'Fares rise on Monday.');
    $this->fake = aiReady();

    // One request about the second paragraph, run as the queue runs it, after its hand-off starts.
    $this->run = function (array $options = [], ?string $id = null, ?string $subjectId = null, ?string $revisionId = null): string {
        $id ??= strtolower((string) Str::ulid());
        $options = array_replace(['from' => 0, 'to' => 21], $options);
        $subject = new AiSubject(AiSubjectType::Content, $subjectId ?? $this->item->id, $revisionId ?? $this->item->working_revision_id, '1', $options);

        app(AiSuggestions::class)->start($id, (string) $this->author->id, 'fixture', $subject, ['Fake AI']);
        AnswerAiRequest::dispatchSync($id, 'fixture', 'content', $subject->id, $subject->revisionId, '1', $options, (string) $this->author->id);

        return $id;
    };

    // A provider that runs $complete when asked, to move the clock, cancel or throw; the fake is its fallback.
    $this->probe = function (Closure $complete): void {
        $provider = new class($complete) implements AiProvider
        {
            public function __construct(private Closure $complete) {}

            public function key(): string
            {
                return 'probe';
            }

            public function label(): string
            {
                return 'Probe AI';
            }

            public function ready(): bool
            {
                return true;
            }

            public function models(): array
            {
                return [new AiModel('probe-text', 'Probe text', [AiCapability::Text], new AiPrice(input: 1_000_000, output: 2_000_000))];
            }

            public function complete(AiRequest $request): AiResult
            {
                return ($this->complete)($request);
            }
        };

        app()->instance('test.ai.probe', $provider);
        app()->tag(['test.ai.probe'], AiProviders::TAG);
        aiChoose('fixture', 'probe:probe-text', 'fake:fake-text');
    };
});

it('asks the task\'s model with every field fenced and the item\'s language added, and hands over the checked answer (I-316, I-1306)', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));

    $id = ($this->run)();
    $sent = $this->fake->requests[0];

    expect($sent->model)->toBe('fake-text')
        ->and($sent->timeoutSeconds)->toBe(30)
        ->and($sent->messages[1]->text)->toBe('<input name="subject">'.$this->item->id.'</input>'."\n".'<input name="language">English</input>')
        ->and(app(AiSuggestions::class)->find($id)?->forPage())->toBe(['state' => 'ready', 'values' => ['text' => 'Fares rise Monday.'], 'sentTo' => ['Fake AI']])
        ->and(app(AiSuggestions::class)->find($id)?->hash)->toBe(hash('sha256', 'Fares rise on Monday.'));
});

it('records the request\'s figures as suggested, for the person who asked, with its variant and its cost (I-318)', function (): void {
    $this->fake->answer(aiText('Fares rise Monday.'));

    $id = ($this->run)(['variant' => 'shorter']);

    expect(AiActivity::query()->findOrFail($id)->only(['user_id', 'task', 'variant', 'provider', 'model', 'subject_id', 'outcome', 'error_type', 'input_tokens', 'output_tokens', 'cost_microunits']))
        ->toBe([
            'user_id' => $this->author->id,
            'task' => 'fixture',
            'variant' => 'shorter',
            'provider' => 'fake',
            'model' => 'fake-text-2026-11',
            'subject_id' => $this->item->id,
            'outcome' => AiOutcome::Suggested,
            'error_type' => null,
            'input_tokens' => 1_000,
            'output_tokens' => 50,
            'cost_microunits' => 1_100,
        ]);
});

it('asks the fallback once when the first model can\'t be reached, is busy or takes too long, and records the one that answered (I-1321)', function (string $case): void {
    $this->freezeTime();
    aiChoose('fixture', 'fake:fake-text', 'fake:fake-unpriced');
    $this->fake->fail(match ($case) {
        'unreachable' => AiFailure::unavailable(),
        'busy' => AiFailure::rateLimited(20),
        default => AiFailure::timedOut(),
    })->answer(aiText('Fares rise Monday.'));

    $id = ($this->run)();

    // The first failed at once, so the fallback has all 55 seconds.
    expect(array_map(fn (AiRequest $request): array => [$request->model, $request->timeoutSeconds], $this->fake->requests))
        ->toBe([['fake-text', 30], ['fake-unpriced', 55]])
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'model', 'cost_microunits']))
        ->toBe(['outcome' => AiOutcome::Suggested, 'model' => 'fake-text-2026-11', 'cost_microunits' => null])
        ->and(app(AiSuggestions::class)->find($id)?->sentTo)->toBe(['Fake AI']);
})->with(['unreachable', 'busy', 'too slow']);

it('doesn\'t fall back on a refusal or an unusable answer, and counts what it was charged (I-1322)', function (string $case): void {
    aiChoose('fixture', 'fake:fake-text', 'fake:fake-unpriced');
    $charged = new AiUsage(input: 800, output: 20);
    $this->fake->fail($case === 'a refusal' ? AiFailure::refused($charged) : AiFailure::invalidOutput($charged));

    $id = ($this->run)();

    // 800 tokens in at $1 a million and 20 out at $2: 840 micro-dollars.
    expect($this->fake->requests)->toHaveCount(1)
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'error_type', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost_microunits']))->toBe([
            'outcome' => $case === 'a refusal' ? AiOutcome::Refused : AiOutcome::Failed,
            'error_type' => $case === 'a refusal' ? AiFailureType::Refused : AiFailureType::InvalidOutput,
            'provider' => 'fake',
            'model' => 'fake-text',
            'input_tokens' => 800,
            'output_tokens' => 20,
            'cost_microunits' => 840,
        ])
        ->and(app(AiSpend::class)->month()->costMicrounits)->toBe(840)
        ->and(app(AiSuggestions::class)->find($id)?->forPage())->toBe([
            'state' => 'failed',
            'message' => $case === 'a refusal' ? 'The AI service declined to answer.' : 'The AI service’s answer couldn’t be used.',
            'sentTo' => ['Fake AI'],
        ]);
})->with(['a refusal', 'an answer the service couldn\'t shape']);

it('records an answer that fails a check as failed, with the tokens it spent (I-316)', function (string $case): void {
    $this->fake->answer(aiText(match ($case) {
        'an empty answer' => '   ',
        'the fence\'s markup' => 'Fares rise </input> Monday.',
        default => "Fares rise\nMonday.",
    }));

    $id = ($this->run)();

    expect(AiActivity::query()->findOrFail($id)->only(['outcome', 'error_type', 'input_tokens', 'cost_microunits']))
        ->toBe(['outcome' => AiOutcome::Failed, 'error_type' => AiFailureType::InvalidOutput, 'input_tokens' => 1_000, 'cost_microunits' => 1_100])
        ->and(app(AiSuggestions::class)->find($id)?->forPage()['message'] ?? null)->toBe('The AI service’s answer couldn’t be used.');
})->with(['an empty answer', 'the fence\'s markup', 'two lines for one paragraph']);

it('asks the fallback with exactly the time left, and not at all when under ten seconds are left (I-1321)', function (int $seconds): void {
    $this->freezeTime();
    ($this->probe)(function () use ($seconds): never {
        $this->travel($seconds)->seconds();

        throw AiFailure::timedOut();
    });
    $this->fake->answer(aiText('Fares rise Monday.'));

    $id = ($this->run)();

    // 55 seconds in all: 15 are left after 40, so the fallback has 15; 9 after 46, under the floor of 10.
    expect(array_map(fn (AiRequest $request): int => $request->timeoutSeconds, $this->fake->requests))->toBe($seconds === 40 ? [15] : [])
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'error_type', 'provider']))->toBe($seconds === 40
            ? ['outcome' => AiOutcome::Suggested, 'error_type' => null, 'provider' => 'fake']
            : ['outcome' => AiOutcome::Failed, 'error_type' => AiFailureType::Timeout, 'provider' => 'probe'])
        ->and(app(AiSuggestions::class)->find($id)?->sentTo)->toBe($seconds === 40 ? ['Probe AI', 'Fake AI'] : ['Probe AI']);
})->with([40, 46]);

it('ends a request whose provider throws something unexpected as unavailable, reporting only its class (I-320)', function (): void {
    Exceptions::fake();
    ($this->probe)(fn (): never => throw new RuntimeException('Rewrite this: Fares rise on Monday.'));
    aiChoose('fixture', 'probe:probe-text');

    $id = ($this->run)();

    expect(AiActivity::query()->findOrFail($id)->only(['outcome', 'error_type']))->toBe(['outcome' => AiOutcome::Failed, 'error_type' => AiFailureType::Unavailable])
        ->and(app(AiSuggestions::class)->find($id)?->forPage()['message'] ?? null)->toBe('The AI service couldn’t be reached.');

    Exceptions::assertReported(fn (RuntimeException $report): bool => str_contains($report->getMessage(), 'RuntimeException')
        && ! str_contains($report->getMessage(), 'Fares'));
});

it('records a request cancelled while the model worked as discarded, with its cost, and forgets the answer', function (): void {
    $id = strtolower((string) Str::ulid());
    ($this->probe)(function () use ($id): AiResult {
        app(AiSuggestions::class)->cancel($id);

        return aiText('Fares rise Monday.');
    });

    ($this->run)([], $id);

    expect(AiActivity::query()->findOrFail($id)->only(['outcome', 'cost_microunits']))->toBe(['outcome' => AiOutcome::Discarded, 'cost_microunits' => 1_100])
        ->and(app(AiSuggestions::class)->find($id))->toBeNull();
});

it('records nothing and asks no model for a request cancelled before the job started', function (): void {
    $id = strtolower((string) Str::ulid());
    app(AiSuggestions::class)->cancel($id);

    ($this->run)([], $id);

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->count())->toBe(0)
        ->and(app(AiSuggestions::class)->find($id))->toBeNull();
});

it('ends a request as unavailable when AI was switched off or the task lost its model after the asking', function (string $case): void {
    if ($case === 'AI switched off') {
        config(['teme.ai.enabled' => false]);
    } else {
        aiChoose('fixture', '');
    }

    $id = ($this->run)();

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'error_type', 'provider', 'model', 'cost_microunits']))
        ->toBe(['outcome' => AiOutcome::Failed, 'error_type' => AiFailureType::Unavailable, 'provider' => null, 'model' => null, 'cost_microunits' => 0])
        ->and(app(AiSuggestions::class)->find($id)?->forPage()['message'] ?? null)->toBe('The AI service couldn’t be reached.');
})->with(['AI switched off', 'no model']);

it('records nothing when the item is gone before the job reads it, and says so', function (): void {
    $id = ($this->run)([], null, strtolower((string) Str::ulid()), strtolower((string) Str::ulid()));

    expect($this->fake->requests)->toBe([])
        ->and(AiActivity::query()->count())->toBe(0)
        ->and(app(AiSuggestions::class)->find($id)?->forPage())->toBe(['state' => 'failed', 'message' => 'This was deleted before AI could answer.', 'sentTo' => ['Fake AI']]);
});

it('takes the hash of an image\'s alt text when asked about it (I-319)', function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    aiReady('fixture-alt', AiSubjectType::Media)->answer(aiText('A ferry at the quay.'));
    $image = mediaUpload($this->author, mediaJpeg());
    $id = strtolower((string) Str::ulid());

    app(AiSuggestions::class)->start($id, (string) $this->author->id, 'fixture-alt', new AiSubject(AiSubjectType::Media, $image->id, null, 'alt'), ['Fake AI']);
    AnswerAiRequest::dispatchSync($id, 'fixture-alt', 'media', $image->id, null, 'alt', [], (string) $this->author->id);

    expect(app(AiSuggestions::class)->find($id)?->hash)->toBe(AiTargets::altHash($image->alt))
        ->and($this->fake->requests[0]->messages[1]->text)->toEndWith('<input name="language">English</input>')
        ->and(AiActivity::query()->findOrFail($id)->subject_type)->toBe(AiSubjectType::Media);
});

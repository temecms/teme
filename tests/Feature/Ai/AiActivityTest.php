<?php

declare(strict_types=1);

use App\Domain\Ai\AiFailureType;
use App\Domain\Ai\AiMonthSpend;
use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Exceptions\ActivityAlreadySettled;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\PruneAiActivityInput;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\Input\SettleAiActivityInput;
use App\Domain\Ai\Operations\PruneAiActivity;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Ai\Operations\SettleAiActivity;
use App\Domain\Ai\Queries\AiSpend;
use App\Domain\Ai\UsageLimits;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->item = lifecycleDraft($this->author, 'Harbour news');
    $this->prompt = new AiPrompt('fixture', 2, 'Shorten the text.', AiPrompt::hashOf('Shorten the text.'));

    // One request's record, as the job that asked makes it; $changes by parameter name.
    $this->record = fn (array $changes = []): string => app(RecordAiActivity::class)->execute(new RecordAiActivityInput(...array_replace([
        'id' => strtolower((string) Str::ulid()),
        'task' => 'fixture',
        'prompt' => $this->prompt,
        'subjectType' => AiSubjectType::Content,
        'subjectId' => $this->item->id,
        'outcome' => AiOutcome::Suggested,
        'provider' => 'fake',
        'model' => 'fake-text-2026-11',
        'usage' => new AiUsage(input: 1_200, output: 80, cacheRead: 300),
        'costMicrounits' => 1_400,
        'latencyMs' => 2_300,
        'variant' => 'shorter',
        'actorId' => (string) $this->author->id,
    ], $changes)));
});

it('records a request\'s figures, never its words, and adds its cost to its UTC month\'s total (I-313, I-318)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59:30', 'UTC'));
    $id = ($this->record)();

    expect(AiActivity::query()->findOrFail($id)->only(['user_id', 'task', 'variant', 'provider', 'model', 'prompt_key', 'prompt_version', 'prompt_hash', 'subject_type', 'subject_id', 'outcome', 'error_type', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_microunits', 'latency_ms', 'revision_id']))
        ->toBe([
            'user_id' => $this->author->id,
            'task' => 'fixture',
            'variant' => 'shorter',
            'provider' => 'fake',
            'model' => 'fake-text-2026-11',
            'prompt_key' => 'fixture',
            'prompt_version' => 2,
            'prompt_hash' => $this->prompt->hash,
            'subject_type' => AiSubjectType::Content,
            'subject_id' => $this->item->id,
            'outcome' => AiOutcome::Suggested,
            'error_type' => null,
            'input_tokens' => 1_200,
            'output_tokens' => 80,
            'cache_read_tokens' => 300,
            'cache_write_tokens' => 0,
            'cost_microunits' => 1_400,
            'latency_ms' => 2_300,
            'revision_id' => null,
        ])
        ->and(array_intersect(Schema::getColumnListing('ai_activity'), ['prompt', 'answer', 'text', 'body', 'input', 'output']))->toBe([])
        ->and(app(AiSpend::class)->month())->toEqual(new AiMonthSpend('2026-11-01', 1_400, 1, 0));

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:00:30', 'UTC'));
    ($this->record)(['costMicrounits' => 600]);

    expect(app(AiSpend::class)->month()->costMicrounits)->toBe(600)
        ->and(app(AiSpend::class)->month(CarbonImmutable::parse('2026-11-15', 'UTC'))->costMicrounits)->toBe(1_400);
});

it('counts a request with no price as unpriced, its cost unknown, and one that used no tokens as free (I-1302)', function (): void {
    $unpriced = ($this->record)(['costMicrounits' => null]);
    ($this->record)(['outcome' => AiOutcome::Failed, 'failure' => AiFailureType::Unavailable, 'usage' => new AiUsage, 'costMicrounits' => null, 'variant' => null]);

    expect(AiActivity::query()->findOrFail($unpriced)->cost_microunits)->toBeNull()
        ->and(app(AiSpend::class)->month())->toEqual(new AiMonthSpend(AiSpend::monthOf(now()), 0, 2, 1));
});

it('leaves no row and no spend when the transaction around it rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            ($this->record)();

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
        // Expected: the record and its spend went with the transaction.
    }

    expect(AiActivity::query()->count())->toBe(0)
        ->and(DB::table('ai_monthly_spend')->count())->toBe(0);
});

it('refuses an outcome and a failure that don\'t belong together, and a malformed figure, writing nothing', function (string $case, string $message): void {
    $changes = match ($case) {
        'failed without its type' => ['outcome' => AiOutcome::Failed],
        'refused as a timeout' => ['outcome' => AiOutcome::Refused, 'failure' => AiFailureType::Timeout],
        'suggested with a type' => ['failure' => AiFailureType::Timeout],
        'used when recorded' => ['outcome' => AiOutcome::Applied],
        'an id in capitals' => ['id' => (string) Str::ulid()],
        default => ['costMicrounits' => -1],
    };

    expect(fn () => ($this->record)($changes))->toThrow(InvalidArgumentException::class, $message)
        ->and(AiActivity::query()->count())->toBe(0);
})->with([
    'failed without its type' => ['failed without its type', 'A failed or refused request needs its failure\'s type, and only then.'],
    'refused as a timeout' => ['refused as a timeout', 'A refused request\'s type is refused; a failed one\'s is any other.'],
    'suggested with a type' => ['suggested with a type', 'A failed or refused request needs its failure\'s type, and only then.'],
    'used when recorded' => ['used when recorded', 'A request is recorded as suggested, discarded, failed or refused; it is used or found out of date later.'],
    'an id in capitals' => ['an id in capitals', 'An AI activity id is a lower-case ULID.'],
    'a negative cost' => ['a negative cost', 'An AI activity\'s cost and latency can\'t be negative.'],
]);

it('writes nothing for a request no person made: nobody, the operator, or someone unknown (I-320)', function (string $case): void {
    if ($case === 'the operator') {
        app()->instance(Elevation::class, new OperatorElevation);
    }

    expect(fn () => ($this->record)(['actorId' => $case === 'someone unknown' ? '999999' : null]))->toThrow(AuthorizationException::class)
        ->and(AiActivity::query()->count())->toBe(0)
        ->and(DB::table('ai_monthly_spend')->count())->toBe(0);
})->with(['nobody', 'the operator', 'someone unknown']);

it('lets the person who asked use their suggestion once, naming the revision it made (I-317)', function (): void {
    $id = ($this->record)();

    app(SettleAiActivity::class)->execute(new SettleAiActivityInput($id, AiOutcome::Applied, $this->item->working_revision_id, (string) $this->author->id));

    expect(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Applied, 'revision_id' => $this->item->working_revision_id])
        ->and(fn () => app(SettleAiActivity::class)->execute(new SettleAiActivityInput($id, AiOutcome::Discarded, null, (string) $this->author->id)))
        ->toThrow(ActivityAlreadySettled::class, 'This suggestion was already used or put aside.')
        ->and(AiActivity::query()->findOrFail($id)->outcome)->toBe(AiOutcome::Applied);
});

it('refuses anyone else, and a revision of another item, writing nothing', function (string $case): void {
    $id = ($this->record)();
    $other = lifecycleDraft($this->author, 'Ferry times');
    $editor = User::factory()->create()->assignRole('editor');

    $input = $case === 'someone else'
        ? new SettleAiActivityInput($id, AiOutcome::Discarded, null, (string) $editor->id)
        : new SettleAiActivityInput($id, AiOutcome::Applied, $other->working_revision_id, (string) $this->author->id);

    expect(fn () => app(SettleAiActivity::class)->execute($input))
        ->toThrow($case === 'someone else' ? AuthorizationException::class : InvalidArgumentException::class)
        ->and(AiActivity::query()->findOrFail($id)->only(['outcome', 'revision_id']))->toBe(['outcome' => AiOutcome::Suggested, 'revision_id' => null]);
})->with(['someone else', 'a revision of another item']);

it('reads whether the month\'s budget is reached, and never without one (I-320)', function (): void {
    ($this->record)(['costMicrounits' => 999_999]);

    expect(app(AiSpend::class)->budgetReached())->toBeFalse();

    cmSettings([UsageLimits::BUDGET => 1], AiSettings::KEY);

    expect(app(AiSpend::class)->budgetReached())->toBeFalse();

    ($this->record)(['costMicrounits' => 1]);

    expect(app(AiSpend::class)->budgetReached())->toBeTrue()
        ->and(app(AiSpend::class)->month()->requests)->toBe(2);
});

it('prunes rows older than 12 months, a chunk at a time, as the operator, keeping the monthly totals (I-318)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-10-01 12:00', 'UTC'));
    ($this->record)();
    ($this->record)();
    ($this->record)();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'UTC'));
    $kept = ($this->record)();

    $this->artisan('teme:ai-activity:prune', ['--chunk' => 2])
        ->expectsOutput('Deleted 3 AI activity rows.')
        ->assertSuccessful();

    expect(AiActivity::query()->pluck('id')->all())->toBe([$kept])
        ->and(DB::table('ai_monthly_spend')->count())->toBe(2);
});

it('prunes for the operator alone, writing nothing for a person', function (): void {
    $this->travelTo(CarbonImmutable::parse('2025-01-01 12:00', 'UTC'));
    ($this->record)();
    $this->travelBack();

    expect(fn () => app(PruneAiActivity::class)->execute(new PruneAiActivityInput(now()->subMonths(12), 100, (string) $this->author->id)))
        ->toThrow(AuthorizationException::class)
        ->and(AiActivity::query()->count())->toBe(1);
});

it('runs the prune daily', function (): void {
    $this->artisan('schedule:list')->expectsOutputToContain('teme:ai-activity:prune')->assertSuccessful();
});

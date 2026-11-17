<?php

declare(strict_types=1);

use App\Domain\Ai\AiOutcome;
use App\Domain\Ai\AiPrompt;
use App\Domain\Ai\AiSubjectType;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Models\AiActivity;
use App\Domain\Ai\Operations\Input\RecordAiActivityInput;
use App\Domain\Ai\Operations\RecordAiActivity;
use App\Domain\Ai\Plugins\PluginAiSpend;
use App\Domain\Ai\Queries\AiSpend;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->prompt = new AiPrompt('article', 1, 'Write about the brief.', AiPrompt::hashOf('Write about the brief.'));

    // A plugin's request, as the door records it; $changes by parameter name.
    $this->record = fn (array $changes = []): string => app(RecordAiActivity::class)->execute(new RecordAiActivityInput(...array_replace([
        'id' => strtolower((string) Str::ulid()),
        'task' => 'article',
        'prompt' => $this->prompt,
        'subjectType' => AiSubjectType::None,
        'subjectId' => null,
        'outcome' => AiOutcome::Answered,
        'provider' => 'fake',
        'model' => 'fake-text',
        'usage' => new AiUsage(input: 1_000, output: 500),
        'costMicrounits' => 2_000,
        'latencyMs' => 900,
        'actorId' => (string) $this->admin->id,
        'plugin' => 'scribe',
    ], $changes)));
});

it('adds the plugin column, the plugin month table and their checks', function (): void {
    expect(Schema::hasColumn('ai_activity', 'plugin'))->toBeTrue()
        ->and(Schema::hasTable('ai_plugin_monthly_spend'))->toBeTrue()
        ->and(DB::table('pg_indexes')->where('indexname', 'ai_activity_plugin_index')->exists())->toBeTrue();

    // A subject of none goes with no subject ID, and only then.
    expect(fn () => DB::table('ai_activity')->insert([
        'id' => strtolower((string) Str::ulid()), 'task' => 'article', 'prompt_key' => 'article', 'prompt_version' => 1,
        'prompt_hash' => $this->prompt->hash, 'subject_type' => 'none', 'subject_id' => strtolower((string) Str::ulid()),
        'outcome' => 'answered', 'plugin' => 'scribe', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('records a plugin’s request with no subject, answered, and adds its cost to the site’s month and the plugin’s', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC'));
    $id = ($this->record)();
    ($this->record)(['costMicrounits' => null, 'model' => 'fake-unpriced']);

    expect(AiActivity::query()->findOrFail($id)->only(['plugin', 'subject_type', 'subject_id', 'outcome', 'error_type', 'user_id']))->toBe([
        'plugin' => 'scribe',
        'subject_type' => AiSubjectType::None,
        'subject_id' => null,
        'outcome' => AiOutcome::Answered,
        'error_type' => null,
        'user_id' => $this->admin->id,
    ])
        ->and(app(AiSpend::class)->month()->costMicrounits)->toBe(2_000)
        ->and((array) DB::table('ai_plugin_monthly_spend')->sole())->toBe([
            'plugin' => 'scribe',
            'month' => '2026-11-01',
            'cost_microunits' => 2_000,
            'requests' => 2,
            'unpriced_requests' => 1,
        ])
        ->and(app(PluginAiSpend::class)->month('scribe')->costMicrounits)->toBe(2_000);
});

it('adds nothing to the plugin month for core’s own requests', function (): void {
    $item = lifecycleDraft($this->admin, 'Harbour news');
    ($this->record)(['plugin' => null, 'outcome' => AiOutcome::Suggested, 'subjectType' => AiSubjectType::Content, 'subjectId' => $item->id, 'task' => 'fixture']);

    expect(DB::table('ai_plugin_monthly_spend')->count())->toBe(0)
        ->and(app(AiSpend::class)->month()->requests)->toBe(1);
});

it('refuses answered and a missing subject without a plugin, writing nothing', function (array $changes, string $message): void {
    expect(fn () => ($this->record)($changes))->toThrow(InvalidArgumentException::class, $message);

    expect(AiActivity::query()->count())->toBe(0)
        ->and(DB::table('ai_monthly_spend')->count())->toBe(0)
        ->and(DB::table('ai_plugin_monthly_spend')->count())->toBe(0);
})->with([
    'answered without a plugin' => [['plugin' => null, 'subjectType' => AiSubjectType::Content, 'subjectId' => '01kaaaaaaaaaaaaaaaaaaaaaaa'], 'Only a plugin’s request is recorded as answered.'],
    'no subject without a plugin' => [['plugin' => null, 'outcome' => AiOutcome::Suggested], 'A request with no subject is a plugin’s, with the subject none.'],
    'a subject of none with an ID' => [['subjectId' => '01kaaaaaaaaaaaaaaaaaaaaaaa'], 'A request with no subject is a plugin’s, with the subject none.'],
    'a plugin code that isn’t one' => [['plugin' => 'Not A Code'], 'An AI activity’s plugin is a plugin code.'],
]);

it('writes nothing for a request no person made', function (): void {
    expect(fn () => ($this->record)(['actorId' => null]))->toThrow(AuthorizationException::class);

    expect(AiActivity::query()->count())->toBe(0)
        ->and(DB::table('ai_plugin_monthly_spend')->count())->toBe(0);
});

it('leaves no row and no plugin month when the transaction around it rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            ($this->record)();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(AiActivity::query()->count())->toBe(0)
        ->and(DB::table('ai_monthly_spend')->count())->toBe(0)
        ->and(DB::table('ai_plugin_monthly_spend')->count())->toBe(0);
});

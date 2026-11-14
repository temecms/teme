<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportOutcome;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Operations\ClearRunMessages;
use App\Domain\Import\Operations\ImportSettings;
use App\Domain\Import\Operations\Input\ClearRunMessagesInput;
use App\Domain\Import\Operations\Input\ImportSettingsInput;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Import\ImportRecords;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->source = ImportRecords::source();
    $this->run = ImportRecords::run($this->source, ['state' => 'running', 'phase' => 'settings', 'phase_token' => strtolower((string) Str::ulid()), 'ended_at' => null]);
});

/**
 * ImportSettings for this step, as the operator at the server's shell or as $actor.
 *
 * @param  array<string, array<string, mixed>>  $values
 *
 * @return array{kept: list<string>}
 */
function isWrite(string $sourceId, string $runId, array $values, ?User $actor = null, string $step = 'site-settings'): array
{
    $write = fn (): array => app(ImportSettings::class)->execute(new ImportSettingsInput($sourceId, $runId, $step, $values, $actor === null ? null : (string) $actor->id));

    return $actor === null ? scElevated($write) : $write();
}

/**
 * A person changes a group's settings here, through Settings' own Operation.
 *
 * @param  array<string, mixed>  $values
 */
function isPersonSets(User $person, string $group, array $values): void
{
    $version = (int) (DB::table('settings')->where('group_key', $group)->value('version') ?? 0);

    app(UpdateSettings::class)->execute(new UpdateSettingsInput($group, $values, [], [], $version, (string) $person->id));
}

function isEntry(string $step = 'site-settings'): ImportMapEntry
{
    return ImportMapEntry::query()->where('object_type', 'setting')->where('object_id', $step)->sole();
}

it('writes each group’s values as the import’s, and keeps what it wrote on the step’s map row', function (): void {
    $result = isWrite($this->source, $this->run, ['general' => ['site_title' => 'Old Example', 'timezone' => 'Europe/London'], 'discussion' => ['thread_depth' => 3]]);
    $settings = app(SettingsReader::class);

    expect($result)->toBe(['kept' => []])
        ->and($settings->string('general', 'site_title'))->toBe('Old Example')
        ->and($settings->string('general', 'timezone'))->toBe('Europe/London')
        ->and($settings->int('discussion', 'thread_depth'))->toBe(3)
        ->and(DB::table('settings_changes')->distinct()->pluck('origin')->all())->toBe(['import'])
        ->and(isEntry()->outcome)->toBe(ImportOutcome::Created)
        ->and(isEntry()->teme_type)->toBe('setting')
        ->and(isEntry()->teme_id)->toBe('site-settings')
        ->and(isEntry()->run_id)->toBe($this->run)
        ->and(array_keys(isEntry()->facts['written']))->toEqualCanonicalizing(['general.site_title', 'general.timezone', 'discussion.thread_depth'])
        ->and(isEntry()->facts['written']['discussion.thread_depth'])->toBe(sha1('3'));
});

it('keeps a value a person changed since the import wrote it, and writes the rest', function (): void {
    isWrite($this->source, $this->run, ['general' => ['site_title' => 'Old Example', 'tagline' => 'Notes']]);
    isPersonSets($this->admin, 'general', ['site_title' => 'Harbour Notes']);

    $result = isWrite($this->source, $this->run, ['general' => ['site_title' => 'Old Example, renamed', 'tagline' => 'Notes from the harbour']]);
    $settings = app(SettingsReader::class);

    expect($result)->toBe(['kept' => ['general.site_title']])
        ->and($settings->string('general', 'site_title'))->toBe('Harbour Notes')
        ->and($settings->string('general', 'tagline'))->toBe('Notes from the harbour')
        ->and(isEntry()->outcome)->toBe(ImportOutcome::Updated)
        ->and(isEntry()->facts['written']['general.site_title'])->toBe(sha1('"Old Example"'));
});

it('writes a value it never wrote before, over what the site had before the import', function (): void {
    isPersonSets($this->admin, 'general', ['tagline' => 'Before the move']);

    expect(isWrite($this->source, $this->run, ['general' => ['tagline' => 'Notes from the harbour']]))->toBe(['kept' => []])
        ->and(app(SettingsReader::class)->string('general', 'tagline'))->toBe('Notes from the harbour');
});

it('writes the address settings through ImportAddresses, keeping a pattern a person changed since', function (): void {
    isWrite($this->source, $this->run, ['addresses' => ['pattern_post' => '/{year}/{slug}/', 'base_category' => 'topics']], step: 'address-settings');
    addrChange($this->admin, ['patterns' => ['post' => '/{slug}/'], 'bases' => ['category' => 'topics']]);

    $result = isWrite($this->source, $this->run, ['addresses' => ['pattern_post' => '/{year}/{month}/{slug}/', 'base_category' => 'subjects']], step: 'address-settings');
    app()->forgetInstance(AddressSettings::class);

    expect($result)->toBe(['kept' => ['addresses.pattern_post']])
        ->and(AddressSettings::current()->patterns['post'])->toBe('/{slug}/')
        ->and(AddressSettings::current()->bases['category'])->toBe('subjects')
        ->and(DB::table('settings_changes')->where('group_key', 'addresses')->orderBy('created_at')->orderBy('id')->pluck('origin')->all())->toBe(['import', 'human', 'import']);
});

it('refuses an unknown group, an unknown setting or a value Teme’s rules refuse, writing nothing', function (array $values): void {
    expect(fn () => isWrite($this->source, $this->run, $values))->toThrow(ValidationException::class)
        ->and(ImportMapEntry::query()->count())->toBe(0)
        ->and(DB::table('settings_changes')->count())->toBe(0);
})->with([
    'a group Teme hasn’t' => [['nowhere' => ['colour' => 'red']]],
    'a setting the group hasn’t' => [['general' => ['colour' => 'red']]],
    'a number out of range' => [['discussion' => ['thread_depth' => 40]]],
    'a pattern for a type Teme hasn’t' => [['addresses' => ['pattern_recipe' => '/{slug}/']]],
]);

it('writes nothing when the person may not import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => isWrite($this->source, $this->run, ['general' => ['site_title' => 'Old Example']], $editor))->toThrow(AuthorizationException::class)
        ->and(ImportMapEntry::query()->count())->toBe(0)
        ->and(DB::table('settings_changes')->count())->toBe(0);
});

it('clears a running run’s own messages whose code starts with a step’s word, and no others', function (): void {
    // Another source's: one source has one running run at a time (import_runs_one_running_index).
    $other = ImportRecords::run(ImportRecords::source(['address' => 'https://other.example']), ['state' => 'running', 'phase' => 'check', 'phase_token' => strtolower((string) Str::ulid()), 'ended_at' => null]);
    $entry = ImportRecords::entry($this->source, $this->run, 'post', '7', 'created');
    ImportRecords::message($this->run, null, 'check.not-found', 'A', 'error');
    ImportRecords::message($this->run, null, 'check.query', 'B', 'error');
    ImportRecords::message($this->run, null, 'checks.other', 'C');
    ImportRecords::message($this->run, $entry, 'check.not-found', 'D', 'error');
    ImportRecords::message($other, null, 'check.not-found', 'E', 'error');

    $cleared = scElevated(fn (): int => app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($this->run, 'check.', null)));

    expect($cleared)->toBe(2)
        ->and(DB::table('import_messages')->orderBy('message')->pluck('message')->all())->toBe(['C', 'D', 'E']);
});

it('clears nothing of a run that isn’t running, and nothing for someone who may not import', function (): void {
    $finished = ImportRecords::run($this->source);
    ImportRecords::message($finished, null, 'check.not-found', 'A', 'error');
    ImportRecords::message($this->run, null, 'check.not-found', 'B', 'error');
    $editor = User::factory()->create()->assignRole('editor');

    expect(scElevated(fn (): int => app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($finished, 'check.', null))))->toBe(0)
        ->and(fn () => app(ClearRunMessages::class)->execute(new ClearRunMessagesInput($this->run, 'check.', (string) $editor->id)))->toThrow(AuthorizationException::class)
        ->and(DB::table('import_messages')->count())->toBe(2);
});

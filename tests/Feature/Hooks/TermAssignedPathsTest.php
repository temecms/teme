<?php

declare(strict_types=1);

use App\Domain\Contents\ItemTerms;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeContentLanguage;
use App\Domain\Contents\Operations\Input\ChangeContentLanguageInput;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Taxonomy\TermAssigned;
use Tests\Fixtures\Import\ContentImports;
use Tests\Fixtures\Languages\LanguageSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->site = LanguageSite::build();
});

/**
 * Records each TermAssigned the dispatcher delivers.
 *
 * @return ArrayObject<int, TermAssigned>
 */
function termAssignedHeard(): ArrayObject
{
    $heard = new ArrayObject;

    app(HookDispatcher::class)->subscriber('core')->on(TermAssigned::class, function (TermAssigned $payload) use ($heard): void {
        $heard[] = $payload;
    });

    return $heard;
}

it('sends TermAssigned for a language change: the old terms removed and their versions in the new language added', function (): void {
    $item = LanguageSite::version($this->site->editor, 'Derby', null, null, [$this->site->news], publish: true);
    $heard = termAssignedHeard();

    app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($item, 'fr', (string) Content::query()->findOrFail($item)->working_revision_id, (string) $this->site->editor->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->item->id)->toBe($item)
        ->and($heard[0]->changes)->toBe(['category' => ['added' => [$this->site->newsFr], 'removed' => [$this->site->news]]]);
});

it('sends no TermAssigned when a language change is refused', function (): void {
    $item = LanguageSite::version($this->site->editor, 'Derby', null, null, [$this->site->news], publish: true);
    $heard = termAssignedHeard();
    $nobody = User::factory()->create();

    expect(fn () => app(ChangeContentLanguage::class)->execute(new ChangeContentLanguageInput($item, 'fr', (string) Content::query()->findOrFail($item)->working_revision_id, (string) $nobody->id)))
        ->toThrow(AuthorizationException::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

it('works nothing out during an import run, not even a query', function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'content',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    $heard = termAssignedHeard();
    $content = Content::query()->findOrFail($this->site->post);
    $queries = [];

    app(ImportMode::class)->during($run->id, function () use ($content, &$queries): void {
        DB::flushQueryLog();
        DB::enableQueryLog();
        ItemTerms::announce($content, [], [$this->site->news], null);
        $queries = DB::getQueryLog();
    });

    expect($queries)->toBe([])->and($heard->getArrayCopy())->toBe([]);
});

it('sends no TermAssigned when an import run writes an item with terms', function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'content',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    $heard = termAssignedHeard();

    app(ImportMode::class)->during($run->id, fn () => ContentImports::import(ContentImports::item($this->site->editor, ['terms' => [$this->site->news]])));

    expect($heard->getArrayCopy())->toBe([]);
});

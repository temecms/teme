<?php

declare(strict_types=1);

use App\Domain\Addresses\PathProjection;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Feeds\ChangedFeeds;
use App\Domain\Import\ImportMode;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Feeds\FeedsChanged;

uses(RefreshDatabase::class);

/*
 * The feeds a change touched, told to plugins after commit
 * (docs/plans/plugin-sockets-1.md, A4): read from the same tags the
 * Operation gave the page cache, and built only when a plugin listens.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
    app(PathProjection::class)->syncUser($this->author);
    $this->harbour = typesTerm($this->editor, 'tag', 'Harbour');
});

it('tells a listener once, the main feed, the tag’s feed and the author’s feed, when a post filed under the tag is published', function (): void {
    // The draft is made and saved first, so only the publish is heard.
    $content = lifecycleDraft($this->author, 'Harbour news');
    app(SaveDraft::class)->execute(new SaveDraftInput($content->id, 'Harbour news', ['type' => 'doc', 'content' => []], [], $content->working_revision_id, (string) $this->author->id, [$this->harbour->id]));
    $heard = hkHear(FeedsChanged::class);

    listsPublish($content, $this->author);

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->urls)->toEqualCanonicalizing(['http://localhost/feed/', 'http://localhost/tag/harbour/feed/', 'http://localhost/author/jane-doe/feed/'])
        ->and($heard[0]->all)->toBeFalse();
});

it('tells a listener nothing for a publish that rolls back', function (): void {
    $heard = hkHear(FeedsChanged::class);

    expect(fn () => DB::transaction(function (): void {
        pageArticle($this->author, 'Harbour news', tags: [$this->harbour]);

        throw new RuntimeException('rolled back');
    }))->toThrow(RuntimeException::class);

    expect($heard->getArrayCopy())->toBe([]);
});

it('tells a listener nothing for a draft’s save', function (): void {
    $content = lifecycleDraft($this->author, 'Harbour news');
    $heard = hkHear(FeedsChanged::class);

    pageSaveDraft($content, 'Harbour news, again');

    expect($heard->getArrayCopy())->toBe([]);
});

it('tells a listener every main feed, and all, when the site’s settings change', function (): void {
    $heard = hkHear(FeedsChanged::class);

    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Harbour Times'], [], [], 0, (string) $this->admin->id));

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->urls)->toBe(['http://localhost/feed/'])
        ->and($heard[0]->all)->toBeTrue();
});

it('builds nothing when no plugin listens', function (): void {
    $built = 0;
    app()->resolving(ChangedFeeds::class, function () use (&$built): void {
        $built++;
    });

    pageArticle($this->author, 'Harbour news', tags: [$this->harbour]);
    app(UpdateSettings::class)->execute(new UpdateSettingsInput('general', ['site_title' => 'Harbour Times'], [], [], 0, (string) $this->admin->id));

    expect($built)->toBe(0);
});

it('tells a listener nothing during an import run', function (): void {
    $source = ImportSource::query()->create([
        'address' => 'https://old.example', 'api_root' => 'https://old.example/wp-json/', 'user_name' => 'ada',
        'credential' => 'abcd EFGH 1234 ijkl MNOP 5678', 'plugin_version' => '0.2.0', 'api_version' => 1, 'probed_at' => now(),
    ]);
    $run = ImportRun::query()->create([
        'source_id' => $source->id, 'kind' => ImportRunKind::Full, 'state' => ImportRunState::Running, 'phase' => 'users',
        'phase_token' => strtolower((string) Str::ulid()), 'high_water_at' => now(), 'started_at' => now(),
    ]);
    $heard = hkHear(FeedsChanged::class);

    app(ImportMode::class)->during($run->id, fn () => pageArticle($this->author, 'Harbour news', tags: [$this->harbour]));

    expect($heard->getArrayCopy())->toBe([]);
});

it('gives at most 200 addresses, and all with the main feeds beyond them', function (): void {
    $tags = array_map(fn (int $n): string => 'list:nothing-'.$n, range(1, 3));
    expect(app(ChangedFeeds::class)->from($tags))->toBeNull()
        ->and(app(ChangedFeeds::class)->from(['*']))->toEqual(new FeedsChanged(['http://localhost/feed/'], true));

    $many = [];

    foreach (range(1, 201) as $n) {
        $many[] = 'term:tag:'.typesTerm($this->editor, 'tag', 'Tag '.$n)->id;
    }

    expect(app(ChangedFeeds::class)->from($many))->toEqual(new FeedsChanged(['http://localhost/feed/'], true));
});

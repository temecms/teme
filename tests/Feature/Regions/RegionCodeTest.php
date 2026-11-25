<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveAutosaveInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\SaveAutosave;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Queries\EditorSession;
use App\Domain\Contents\Queries\ItemSnapshots;
use App\Domain\Contents\TranslationCopy;
use App\Domain\SiteCode\RegionCode;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/*
 * A region's Custom HTML code (docs/architecture/security.md): only someone
 * who may manage site code adds, changes or reads it, with the password
 * asked again on a save or a publish that changes it; everyone else may move
 * or remove the card, keeping its code; each change is logged.
 */
beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->id = (string) Str::ulid();
    $this->region = regionCreate($this->admin, 'default');
    $this->card = fn (?string $code, array $origins = [], bool $on = true): array => docWith(['type' => 'site-code', 'attrs' => ['id' => $this->id, 'code' => $code, 'origins' => $origins, 'on' => $on]]);
    $this->held = fn (): array => RegionCode::of((array) ContentRevision::query()->whereKey($this->region->fresh()->working_revision_id)->value('document'));
    $this->ad = fn (): null => scElevated(function (): null {
        docSave($this->region, $this->admin, ($this->card)('<b>ad</b>', ['ads.example.com']));

        return null;
    });
});

it('asks an administrator for the password again when a region saves changed code, and logs the change', function (): void {
    $before = ContentRevision::query()->where('content_id', $this->region->id)->count();

    expect(fn () => docSave($this->region, $this->admin, ($this->card)('<b>ad</b>')))->toThrow(ElevationRequiredException::class)
        ->and(ContentRevision::query()->where('content_id', $this->region->id)->count())->toBe($before);

    ($this->ad)();

    expect(scLog())->toHaveCount(1)
        ->and(scLog()[0]['type'])->toBe('site_code.card_added')
        ->and(scLog()[0]['user_id'])->toBe($this->admin->id)
        ->and(scLog()[0]['context'])->toMatchArray(['card' => $this->id, 'after_code' => '<b>ad</b>', 'after_origins' => 'ads.example.com', 'before_code' => '', 'region' => $this->region->slug]);
});

it('saves a region without asking when its code is as it was', function (): void {
    ($this->ad)();

    docSave($this->region, $this->admin, docWith(docText('Above'), ...($this->card)('<b>ad</b>', ['ads.example.com'])['content']));

    expect(scLog())->toHaveCount(1);
});

it('writes nothing when an editor tries to add or change a card’s code', function (?string $existing): void {
    if ($existing !== null) {
        ($this->ad)();
    }

    $revisions = ContentRevision::query()->count();
    $events = count(scLog());

    expect(fn () => docSave($this->region, $this->editor, ($this->card)('<script>x()</script>')))
        ->toThrow(AuthorizationException::class, 'Not permitted to change site code.')
        ->and(ContentRevision::query()->count())->toBe($revisions)
        ->and(scLog())->toHaveCount($events);
})->with(['adding' => [null], 'changing' => ['<b>ad</b>']]);

it('writes nothing when an editor tries to change a card’s hosts or switch it', function (array $attrs): void {
    ($this->ad)();
    $revisions = ContentRevision::query()->count();

    expect(fn () => docSave($this->region, $this->editor, docWith(['type' => 'site-code', 'attrs' => ['id' => $this->id, 'code' => null, ...$attrs]])))
        ->toThrow(AuthorizationException::class)
        ->and(ContentRevision::query()->count())->toBe($revisions);
})->with([
    'hosts' => [['origins' => ['evil.example.com']]],
    'off' => [['origins' => [], 'on' => false]],
]);

it('lets an editor move a card, keeping its code, and never shows the code to them', function (): void {
    ($this->ad)();
    $session = EditorSession::for($this->region->id, (string) $this->editor->id);

    expect(json_encode($session?->document, JSON_UNESCAPED_SLASHES))->not->toContain('<b>ad</b>')->not->toContain('ads.example.com');

    // The editor saves what they were shown, the card below a new paragraph.
    docSave($this->region, $this->editor, docWith(docText('Above'), ...$session->document['content']));

    expect(($this->held)()[$this->id])->toBe(['code' => '<b>ad</b>', 'origins' => ['ads.example.com'], 'on' => true])
        ->and(scLog())->toHaveCount(1);
});

it('shows the code to an administrator in the editor', function (): void {
    ($this->ad)();

    expect(json_encode(EditorSession::for($this->region->id, (string) $this->admin->id)?->document, JSON_UNESCAPED_SLASHES))->toContain('<b>ad</b>');
});

it('gives a card an editor adds no code', function (): void {
    docSave($this->region, $this->editor, docWith(['type' => 'site-code', 'attrs' => ['id' => $this->id, 'code' => null, 'origins' => []]]));

    expect(($this->held)()[$this->id]['code'])->toBe('')
        ->and(scLog())->toBe([]);
});

it('lets an editor remove a card, with the password, and logs the removal with the code it held', function (): void {
    ($this->ad)();

    expect(fn () => docSave($this->region, $this->editor, docWith()))->toThrow(ElevationRequiredException::class);

    scElevated(fn (): Content => docSave($this->region, $this->editor, docWith()));

    expect(($this->held)())->toBe([])
        ->and(collect(scLog())->last()['type'])->toBe('site_code.card_removed')
        ->and(collect(scLog())->last()['context'])->toMatchArray(['before_code' => '<b>ad</b>', 'after_code' => '']);
});

it('refuses to publish an administrator’s code change to an editor, and asks the administrator for the password', function (): void {
    ($this->ad)();
    $published = $this->region->fresh()->published_revision_id;

    expect(fn () => listsPublish($this->region, $this->editor))->toThrow(AuthorizationException::class)
        ->and(fn () => listsPublish($this->region, $this->admin))->toThrow(ElevationRequiredException::class)
        ->and($this->region->fresh()->published_revision_id)->toBe($published);

    scElevated(fn () => listsPublish($this->region, $this->admin));

    expect($this->region->fresh()->published_revision_id)->toBe($this->region->fresh()->working_revision_id)
        ->and(collect(scLog())->last())->toMatchArray(['type' => 'site_code.card_published'])
        ->and(collect(scLog())->last()['context'])->toMatchArray(['after_code' => '<b>ad</b>', 'card' => $this->id]);
});

it('lets an editor publish a region whose code is already live', function (): void {
    ($this->ad)();
    scElevated(fn () => listsPublish($this->region, $this->admin));
    docSave($this->region, $this->editor, docWith(docText('Above'), ...EditorSession::for($this->region->id, (string) $this->editor->id)->document['content']));

    listsPublish($this->region, $this->editor);

    expect($this->region->fresh()->published_revision_id)->toBe($this->region->fresh()->working_revision_id);
});

it('publishes a change to code now, never on a schedule', function (): void {
    ($this->ad)();
    $this->region->refresh();

    expect(fn () => scElevated(fn () => app(SchedulePublication::class)->execute(new SchedulePublicationInput($this->region->id, (string) $this->region->working_revision_id, 'publish', now()->addDay()->toIso8601String(), (string) $this->admin->id))))
        ->toThrow(ValidationException::class, 'A change to Custom HTML code is published now, not on a schedule.');
});

it('accepts no code change while the host disallows site code, and still lets a card go', function (): void {
    ($this->ad)();
    config(['teme.disallow_site_code' => true]);

    expect(fn () => scElevated(fn () => docSave($this->region, $this->admin, ($this->card)('<i>new</i>'))))->toThrow(AuthorizationException::class)
        ->and(($this->held)()[$this->id]['code'])->toBe('<b>ad</b>');

    scElevated(fn (): Content => docSave($this->region, $this->admin, docWith()));

    expect(($this->held)())->toBe([]);
});

it('keeps an editor’s autosave from holding code, and writes nothing when it tries', function (): void {
    $this->region->refresh();
    $save = fn (array $document, User $actor) => app(SaveAutosave::class)->execute(new SaveAutosaveInput($this->region->id, '', $document, [], [], $this->region->working_revision_id, (string) $actor->id));

    expect(fn () => $save(($this->card)('<script>x()</script>'), $this->editor))->toThrow(AuthorizationException::class)
        ->and(DB::table('content_autosaves')->count())->toBe(0);

    // An administrator's autosave holds code without the password: it is never live.
    $save(($this->card)('<b>draft</b>'), $this->admin);

    expect(DB::table('content_autosaves')->count())->toBe(1);
});

it('copies a region for a translation with its cards’ code left behind', function (): void {
    ($this->ad)();
    $copy = TranslationCopy::of(ContentRevision::query()->findOrFail($this->region->fresh()->working_revision_id), 'region', null);

    expect(json_encode($copy['document'], JSON_UNESCAPED_SLASHES))->not->toContain('<b>ad</b>')->not->toContain('ads.example.com');
});

it('gives plugins a region without its cards’ code', function (): void {
    ($this->ad)();

    expect(json_encode(app(ItemSnapshots::class)->of($this->region->fresh())->body, JSON_UNESCAPED_SLASHES))->not->toContain('<b>ad</b>');
});

it('moves code placed with a card into the cards on upgrade, and removes the placed entries', function (): void {
    $migration = require base_path('database/migrations/2027_10_15_100000_move_placed_site_code_into_cards.php');
    $migration->down();

    $on = scAdd($this->admin, 'Sidebar ad', 'head', '<b>on</b>');
    $off = scAdd($this->admin, 'Old banner', 'head', '<b>off</b>', on: false);
    $head = scAdd($this->admin, 'Analytics', 'head', '<b>head</b>');
    DB::table('site_code_entries')->whereIn('id', [$on->id, $off->id])->update(['placement' => 'placed']);
    DB::table('site_code_entries')->where('id', $on->id)->update(['origins' => json_encode(['ads.example.com'], JSON_UNESCAPED_SLASHES)]);
    $cards = [
        ['type' => 'site-code', 'attrs' => ['id' => 'A'.substr((string) Str::ulid(), 1), 'entryId' => $on->id]],
        ['type' => 'site-code', 'attrs' => ['id' => 'B'.substr((string) Str::ulid(), 1), 'entryId' => $off->id]],
        ['type' => 'site-code', 'attrs' => ['id' => 'C'.substr((string) Str::ulid(), 1), 'entryId' => $head->id]],
        ['type' => 'site-code', 'attrs' => ['id' => 'D'.substr((string) Str::ulid(), 1), 'entryId' => strtolower((string) Str::ulid())]],
    ];
    DB::table('content_revisions')->where('id', $this->region->working_revision_id)->update(['document' => json_encode(docWith(...$cards))]);

    $migration->up();

    $held = array_values(($this->held)());

    expect($held)->toBe([
        ['code' => '<b>on</b>', 'origins' => ['ads.example.com'], 'on' => true],
        ['code' => '<b>off</b>', 'origins' => [], 'on' => false],
        ['code' => '', 'origins' => [], 'on' => true],
        ['code' => '', 'origins' => [], 'on' => true],
    ])
        ->and(json_encode(ContentRevision::query()->findOrFail($this->region->working_revision_id)->document, JSON_UNESCAPED_SLASHES))->not->toContain('entryId')
        ->and(DB::table('site_code_entries')->pluck('id')->all())->toBe([$head->id])
        ->and(collect(scLog())->firstWhere('type', 'site_code.moved_to_cards')['context'])->toMatchArray(['moved' => 1, 'moved_off' => 1, 'left_empty' => 2, 'entries' => 2])
        ->and(collect(scLog())->where('type', 'site_code.deleted')->pluck('context.before_code')->sort()->values()->all())->toBe(['<b>off</b>', '<b>on</b>'])
        ->and(fn () => DB::table('site_code_entries')->where('id', $head->id)->update(['placement' => 'placed']))->toThrow(QueryException::class);
});

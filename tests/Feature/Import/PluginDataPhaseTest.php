<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Models\ImportMessageRecord;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Models\ImportSource;
use App\Domain\Import\PluginData\PluginDataGoneStep;
use App\Domain\Import\PluginData\PluginDataPhase;
use App\Domain\Import\PluginData\PluginOptionsStep;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRecords;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    $this->companion = importFake();
    pdApi($this->companion, 2);
    PluginFixtures::use('importer');
    $manifest = PluginFixtures::manifest('importer');
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/importer', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();
    pdWordPress($this->companion);
    importPhaseAlone(app(PluginOptionsStep::class), app(PluginDataPhase::class), app(PluginDataGoneStep::class));
    $this->source = importSource();
});

afterEach(fn () => PluginFixtures::forget());

function pdApi(FakeCompanion $companion, int $api): void
{
    $companion->items('probe', ['name' => 'teme-companion', 'version' => '0.3.0', 'api' => $api, 'application_passwords' => true]);
}

/**
 * The old site's tables: two actors, a post of another type, a person with
 * the declared meta and one without, each with an undeclared key too.
 *
 * @param  list<array<string, mixed>>  $actors
 */
function pdWordPress(FakeCompanion $companion, ?array $actors = null): void
{
    $companion->wordpress(
        options: ['importer_mode' => 'a:1:{s:4:"open";b:1;}', 'importer_private' => 's:6:"secret";'],
        posts: [
            ...($actors ?? [
                ['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => ['_importer_inbox' => ['https://example.com/ada/inbox'], '_importer_following' => ['a:2:{i:0;s:4:"@bob";i:1;s:3:"@cy";}'], '_undeclared' => ['no']]],
                ['id' => 8, 'type' => 'ap_actor', 'title' => 'Grace', 'meta' => ['_importer_inbox' => ['https://example.com/grace/inbox']]],
            ]),
            ['id' => 5, 'type' => 'post', 'title' => 'Not an actor', 'meta' => ['_importer_inbox' => ['x']]],
        ],
        users: [1 => ['importer_also_known_as' => ['@ada@old.example'], 'nickname' => ['ada']], 2 => ['nickname' => ['grace']]],
    );
}

function pdRun(ImportSource $source, ImportRunKind $kind = ImportRunKind::Full): ImportRun
{
    return importRun($source, kind: $kind);
}

/**
 * What the fixture plugin keeps, as kind:id => payload.
 *
 * @return array<string, array<string, mixed>>
 */
function pdReceived(): array
{
    return DB::table('importer_received')->orderBy('kind')->orderBy('source_id')->get()
        ->mapWithKeys(fn (object $row): array => ["{$row->kind}:{$row->source_id}" => json_decode((string) $row->payload, true)])->all();
}

/**
 * @return list<string>
 */
function pdCodes(ImportRun $run): array
{
    return ImportMessageRecord::query()->where('run_id', $run->id)->orderBy('code')->pluck('code')->all();
}

function pdWords(ImportRun $run, string $code): string
{
    return (string) ImportMessageRecord::query()->where('run_id', $run->id)->where('code', $code)->value('message');
}

it('hands a full run’s declared data to the plugin, and the plugin’s table holds it', function (): void {
    $run = pdRun($this->source);
    $received = pdReceived();

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->created_count)->toBe(3)
        ->and(array_keys($received))->toBe(['options:0', 'post:3', 'post:8', 'user:1'])
        ->and($received['options:0']['options'])->toBe(['importer_mode' => ['open' => true]])
        ->and($received['post:3']['fields'])->toMatchArray(['title' => 'Ada', 'status' => 'publish', 'author' => 1, 'parent' => 0])
        ->and($received['post:3']['meta'])->toBe(['_importer_inbox' => ['https://example.com/ada/inbox'], '_importer_following' => [['@bob', '@cy']]])
        ->and($received['user:1']['meta'])->toBe(['importer_also_known_as' => ['@ada@old.example']])
        ->and(ImportMapEntry::query()->where('object_type', 'plugin')->orderBy('object_id')->pluck('object_id')->all())
        ->toBe(['importer:post:ap_actor:3', 'importer:post:ap_actor:8', 'importer:user::1'])
        ->and(pdCodes($run))->toBe([]);
});

it('never asks the companion for anything the plugin didn’t declare', function (): void {
    pdRun($this->source);
    $asked = array_map(fn (array $request): array => array_diff_key($request['query'], ['after_id' => 0, 'limit' => 0]), $this->companion->requestsTo('extension'));

    expect($asked)->toBe([
        ['plugin' => 'importer', 'kind' => 'options', 'names' => 'importer_mode'],
        ['plugin' => 'importer', 'kind' => 'posts', 'type' => 'ap_actor', 'meta' => '_importer_inbox,_importer_following'],
        ['plugin' => 'importer', 'kind' => 'usermeta', 'keys' => 'importer_also_known_as'],
    ]);
});

it('hands a catch-up nothing that hasn’t changed, and only the record that has', function (): void {
    pdRun($this->source);
    DB::table('importer_received')->delete();

    $quiet = pdRun($this->source, ImportRunKind::CatchUp);

    expect($quiet->state)->toBe(ImportRunState::Finished)
        ->and(array_keys(pdReceived()))->toBe(['options:0'])
        ->and(pdReceived()['options:0']['catchUp'])->toBeTrue();

    DB::table('importer_received')->delete();
    pdWordPress($this->companion, [
        ['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => ['_importer_inbox' => ['https://example.com/ada/inbox'], '_importer_following' => ['a:2:{i:0;s:4:"@bob";i:1;s:3:"@cy";}'], '_undeclared' => ['no']]],
        ['id' => 8, 'type' => 'ap_actor', 'title' => 'Grace Hopper', 'meta' => ['_importer_inbox' => ['https://example.com/grace/inbox']]],
    ]);

    $changed = pdRun($this->source, ImportRunKind::CatchUp);

    expect(array_keys(pdReceived()))->toBe(['options:0', 'post:8'])
        ->and(pdReceived()['post:8']['fields']['title'])->toBe('Grace Hopper')
        ->and($changed->updated_count)->toBe(1);
});

it('reports a record the plugin fails with its sentence, and finishes the run', function (): void {
    pdWordPress($this->companion, [
        ['id' => 3, 'type' => 'ap_actor', 'title' => 'fail me', 'meta' => []],
        ['id' => 4, 'type' => 'ap_actor', 'title' => 'break me', 'meta' => []],
        ['id' => 6, 'type' => 'ap_actor', 'title' => 'skip me', 'meta' => []],
        ['id' => 8, 'type' => 'ap_actor', 'title' => 'Grace', 'meta' => []],
    ]);

    $run = pdRun($this->source);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($run->failed_count)->toBe(2)
        ->and($run->skipped_count)->toBe(1)
        ->and(array_keys(pdReceived()))->toBe(['options:0', 'post:8', 'user:1'])
        ->and(pdCodes($run))->toBe(['plugin-data.failed', 'plugin-data.skipped', 'plugin-data.unexpected'])
        ->and(pdWords($run, 'plugin-data.failed'))->toBe('This actor has no inbox, so it wasn’t brought across.')
        ->and(pdWords($run, 'plugin-data.skipped'))->toBe('This actor is a test account.')
        ->and(pdWords($run, 'plugin-data.unexpected'))->toBe('Importer couldn’t bring this across because of an unexpected error. The application log has the details.')
        ->and(pdWords($run, 'plugin-data.unexpected'))->not->toContain('on purpose');
});

it('gives a plugin not granted import.wordpress nothing, and writes nothing for it', function (): void {
    PluginRecord::query()->where('name', 'tests/importer')->update(['consent' => json_encode(['capabilities' => [], 'hosts' => [], 'hooks' => []])]);
    app(PluginRecords::class)->changed();

    $run = pdRun($this->source);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($this->companion->requestsTo('extension'))->toBe([])
        ->and(pdReceived())->toBe([])
        ->and(ImportMapEntry::query()->where('object_type', 'plugin')->count())->toBe(0)
        ->and(pdCodes($run))->toBe([]);
});

it('reads nothing for a disabled plugin', function (): void {
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/importer', null));

    $run = pdRun($this->source);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($this->companion->requestsTo('extension'))->toBe([])
        ->and(pdReceived())->toBe([]);
});

it('hands a serialized value over decoded, and an object as null, reported', function (): void {
    pdWordPress($this->companion, [
        ['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => ['_importer_inbox' => ['O:8:"stdClass":1:{s:1:"a";i:1;}'], '_importer_following' => ['a:1:{i:0;s:4:"@bob";}']]],
    ]);

    $run = pdRun($this->source);

    expect(pdReceived()['post:3']['meta'])->toBe(['_importer_inbox' => [null], '_importer_following' => [['@bob']]])
        ->and(pdCodes($run))->toBe(['plugin-data.object'])
        ->and(pdWords($run, 'plugin-data.object'))->toBe('WordPress kept a PHP object in _importer_inbox, which Teme can’t read, so Importer was given nothing there.');
});

it('reports a companion too old for plugin data, skips it, and finishes the run', function (): void {
    pdApi($this->companion, 1);
    ImportSource::query()->whereKey($this->source->id)->update(['api_version' => 1]);

    $run = pdRun($this->source);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and($this->companion->requestsTo('extension'))->toBe([])
        ->and(pdReceived())->toBe([])
        ->and(pdCodes($run))->toBe(['plugin-data.companion-too-old']);
});

it('tells the plugin what an imported post and person became', function (): void {
    $item = Content::factory()->create(['title' => 'The parent']);
    $person = User::factory()->create();

    foreach ([['post', '42', 'content', $item->id], ['user', '1', 'user', (string) $person->id]] as [$type, $id, $temeType, $temeId]) {
        ImportMapEntry::query()->create([
            'source_id' => $this->source->id, 'object_type' => $type, 'object_id' => $id, 'teme_type' => $temeType, 'teme_id' => $temeId,
            'status' => 'imported', 'outcome' => 'created', 'source_hash' => str_repeat('0', 64), 'facts' => [], 'imported_at' => now(),
        ]);
    }

    pdWordPress($this->companion, [['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'parent' => 42, 'author' => 1, 'meta' => []]]);
    pdRun($this->source);

    expect(pdReceived()['post:3'])->toMatchArray(['parentItem' => $item->id, 'parentTitle' => 'The parent', 'authorPerson' => (string) $person->id, 'catchUp' => false]);
});

it('lists, on a catch-up, a record WordPress no longer has, and changes nothing', function (): void {
    pdRun($this->source);
    pdWordPress($this->companion, [['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => ['_importer_inbox' => ['https://example.com/ada/inbox'], '_importer_following' => ['a:2:{i:0;s:4:"@bob";i:1;s:3:"@cy";}'], '_undeclared' => ['no']]]]);

    $run = pdRun($this->source, ImportRunKind::CatchUp);

    expect(pdCodes($run))->toBe(['plugin-data.gone'])
        ->and(pdWords($run, 'plugin-data.gone'))->toBe('WordPress no longer has the post 8 whose data Importer brought across. Nothing was changed: Importer keeps what it has.')
        ->and(array_keys(pdReceived()))->toContain('post:8');
});

it('gives each plugin a row in the run’s report with what it brought, skipped and failed', function (): void {
    pdWordPress($this->companion, [
        ['id' => 3, 'type' => 'ap_actor', 'title' => 'Ada', 'meta' => []],
        ['id' => 4, 'type' => 'ap_actor', 'title' => 'fail me', 'meta' => []],
        ['id' => 6, 'type' => 'ap_actor', 'title' => 'skip me', 'meta' => []],
    ]);

    $run = pdRun($this->source);
    $types = app(ImportScreen::class)->report($run->id)['types'];

    expect($types)->toContain(['type' => 'plugin:importer', 'label' => 'Importer (plugin)', 'created' => 2, 'updated' => 0, 'skipped' => 1, 'failed' => 1])
        ->and(array_column($types, 'type'))->not->toContain('plugin');
});

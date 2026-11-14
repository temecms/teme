<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Import\ImportMessageLevel;
use App\Domain\Import\ImportRunKind;
use App\Domain\Import\ImportRunState;
use App\Domain\Import\Models\ImportMapEntry;
use App\Domain\Import\Phases\Addresses\AddressClashStep;
use App\Domain\Import\Phases\Addresses\AddressSettingsStep;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\ImportPhases;
use App\Domain\Import\Phases\ImportStopped;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Settings\SettingsReader;
use Tests\Fixtures\Import\FakeCompanion;
use Tests\Fixtures\Import\OldSite;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    config(['app.url' => 'http://localhost']);
    $this->admin = User::factory()->create()->assignRole('administrator');
    [$this->source, $this->run] = OldSite::running('address-settings');
});

/**
 * The step run as the operator; its messages. AddressSettings is read afresh after it.
 *
 * @return list<ImportMessage>
 */
function assRun(string $source, string $run, OldSite $site): array
{
    $said = scElevated(fn (): array => OldSite::walk(app(AddressSettingsStep::class), OldSite::context($source, $run), $site));
    app()->forgetInstance(AddressSettings::class);

    return $said;
}

it('copies the old site’s addresses, time zone and language before anything is written (I-393)', function (): void {
    $site = (new OldSite)
        ->options(['permalink_structure' => '/%year%/%monthnum%/%postname%/', 'category_base' => 'topics', 'timezone_string' => 'Europe/Paris'], 'fr_FR')
        ->terms([['id' => 1, 'taxonomy' => 'category', 'link' => OldSite::HOME.'/topics/uncategorized/']]);

    $said = assRun($this->source, $this->run, $site);
    $settings = app(SettingsReader::class);
    $entry = ImportMapEntry::query()->where('object_type', 'setting')->where('object_id', 'address-settings')->sole();

    expect($said)->toBe([])
        ->and(AddressSettings::current()->patterns)->toMatchArray(['post' => '/{year}/{month}/{slug}/', 'page' => '/{parent}/{slug}/'])
        ->and(AddressSettings::current()->bases)->toMatchArray(['category' => 'topics', 'tag' => 'tag'])
        ->and($settings->string('general', 'timezone'))->toBe('Europe/Paris')
        ->and($settings->string('general', 'language'))->toBe('fr')
        ->and(array_keys($entry->facts['written']))->toContain('addresses.pattern_post', 'addresses.base_category', 'general.timezone', 'general.language');
});

it('leaves the category base out where the old site strips it', function (): void {
    assRun($this->source, $this->run, (new OldSite)->terms([['id' => 1, 'taxonomy' => 'category', 'link' => OldSite::HOME.'/uncategorized/']]));

    expect(AddressSettings::current()->bases['category'])->toBe('');
});

it('takes the nearest pattern Teme can write for a structure it can’t, and says so', function (): void {
    $said = assRun($this->source, $this->run, (new OldSite)->options(['permalink_structure' => '/%postname%.html']));

    expect(AddressSettings::current()->patterns)->toMatchArray(['post' => '/{slug}', 'page' => '/{parent}/{slug}'])
        ->and(array_map(fn (ImportMessage $message): string => $message->code, $said))->toBe(['address.changed'])
        ->and($said[0]->message)->toBe('Posts’ addresses on the old site were shaped /%postname%.html, which Teme can’t write, so posts here are at /{slug}. Each post’s old address answers with a permanent redirect to its new one.');
});

it('keeps its own address settings for a site with plain addresses, saying why', function (): void {
    $before = AddressSettings::current()->patterns;

    $said = assRun($this->source, $this->run, (new OldSite)->options(['permalink_structure' => '']));

    expect(AddressSettings::current()->patterns)->toBe($before)
        ->and(app(SettingsReader::class)->string('general', 'timezone'))->toBe('Europe/London')
        ->and(array_map(fn (ImportMessage $message): array => [$message->code, $message->message], $said))->toBe([
            ['address.plain', 'The old site uses plain addresses, such as /?p=12, which Teme can’t answer, so Teme kept its own address settings. The address check lists every old address that doesn’t answer.'],
        ]);
});

it('keeps what a person changed in Settings › Addresses since the last import, and follows WordPress elsewhere', function (): void {
    assRun($this->source, $this->run, (new OldSite)->options(['permalink_structure' => '/%year%/%postname%/']));
    addrChange($this->admin, ['patterns' => ['post' => '/{slug}/', 'page' => '/{parent}/{slug}/']]);

    $said = assRun($this->source, $this->run, (new OldSite)->options(['permalink_structure' => '/%year%/%monthnum%/%postname%/', 'timezone_string' => 'Europe/Paris']));

    expect(AddressSettings::current()->patterns['post'])->toBe('/{slug}/')
        ->and(app(SettingsReader::class)->string('general', 'timezone'))->toBe('Europe/Paris')
        ->and(array_map(fn (ImportMessage $message): array => [$message->code, $message->message], $said))->toBe([
            ['address.kept-here', 'Changed here since the last import, so kept as they are: Post address.'],
        ]);
});

it('stops the run, listing them, when things already in Teme would share an address under the old site’s settings', function (): void {
    addrPublished($this->admin, 'News', 'page');
    taxonomyTerm('category', 'News');
    $stop = null;

    try {
        assRun($this->source, $this->run, (new OldSite)->terms([['id' => 1, 'taxonomy' => 'category', 'link' => OldSite::HOME.'/news/']]));
    } catch (ImportStopped $caught) {
        $stop = $caught;
    }

    expect($stop?->reason)->toBe('address-settings')
        ->and($stop?->words)->toBe('Under the old site’s address settings, things already in Teme would share an address, so the import stopped. The report lists them: rename one of each pair, then carry on the import.')
        ->and(array_map(fn (ImportMessage $message): array => [$message->code, $message->level, $message->message], $stop?->details ?? []))->toBe([
            ['address.settings-clash', ImportMessageLevel::Error, 'The category "News" and the page "News" would both be at /news/.'],
        ])
        ->and(AddressSettings::current()->bases['category'])->toBe('category')
        ->and(app(SettingsReader::class)->string('general', 'timezone'))->toBe('UTC')
        ->and(ImportMapEntry::query()->where('object_type', 'setting')->count())->toBe(0);
});

it('writes nothing when the person running it may no longer import', function (): void {
    $editor = User::factory()->create()->assignRole('editor');

    expect(fn () => OldSite::walk(app(AddressSettingsStep::class), OldSite::context($this->source, $this->run, $editor), new OldSite))->toThrow(AuthorizationException::class)
        ->and(DB::table('settings_changes')->count())->toBe(0)
        ->and(ImportMapEntry::query()->count())->toBe(0);
});

it('registers the clash at 50 and the address settings at 100, for every kind of run', function (): void {
    $phases = app(ImportPhases::class);
    $ours = [$phases->find('address-clash'), $phases->find('address-settings')];

    expect(array_map(fn ($phase): ?int => $phase?->order(), $ours))->toBe([50, 100])
        ->and($ours[0])->toBeInstanceOf(AddressClashStep::class)
        ->and($ours[1])->toBeInstanceOf(AddressSettingsStep::class)
        ->and(array_map(fn ($phase): ?string => $phase?->label(), $ours))->toBe(['Addresses both sites claim', 'Address settings'])
        ->and(array_map(fn ($phase): bool => (bool) $phase?->runsIn(ImportRunKind::CatchUp), $ours))->toBe([true, true]);
});

it('runs both first through the engine, reading the old site’s addresses, terms and settings', function (): void {
    // The run beforeEach left running would hold this one back.
    DB::table('import_runs')->where('id', $this->run)->update(['state' => 'finished', 'phase_token' => null, 'ended_at' => now()]);
    $fake = importFake();
    $source = importSource();
    importPhaseAlone(app(AddressClashStep::class), app(AddressSettingsStep::class));
    $fake->items('urls', [
        ['id' => 2, 'kind' => 'post', 'type' => 'page', 'url' => FakeCompanion::SITE.'/about/'],
        ['id' => 3, 'kind' => 'term', 'taxonomy' => 'category', 'url' => FakeCompanion::SITE.'/category/animals/'],
    ]);

    $run = importRun($source);
    app()->forgetInstance(AddressSettings::class);

    expect($run->state)->toBe(ImportRunState::Finished)
        ->and(app(SettingsReader::class)->string('general', 'timezone'))->toBe('Europe/London')
        ->and(app(SettingsReader::class)->string('general', 'language'))->toBe('en-GB')
        ->and(AddressSettings::current()->bases['category'])->toBe('category')
        ->and(array_map(fn (array $request): string => $request['query']['kind'] ?? '', $fake->requestsTo('urls')))->toBe(['post', 'term'])
        ->and($fake->requestsTo('terms'))->toHaveCount(1);
});

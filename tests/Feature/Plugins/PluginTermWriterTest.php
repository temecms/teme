<?php

declare(strict_types=1);

use App\Domain\Addresses\AddressSettings;
use App\Domain\Addresses\Jobs\RecomputeAddresses;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Hooks\HookDispatcher;
use App\Domain\Plugins\Doors\PluginDoors;
use App\Domain\Plugins\Doors\PluginTermWriter;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Models\PluginRecord;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Domain\Taxonomy\Models\Term;
use App\Domain\Taxonomy\Operations\CreateTerm;
use App\Domain\Taxonomy\Operations\DeleteTerm;
use App\Domain\Taxonomy\Operations\Input\DeleteTermInput;
use App\Domain\Taxonomy\Operations\Input\SaveTermInput;
use App\Domain\Taxonomy\Operations\UpdateTerm;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Taxonomy\TermCreated;
use Teme\Contracts\Taxonomy\TermDeleted;
use Teme\Contracts\Taxonomy\TermUpdated;
use Teme\Contracts\Taxonomy\TermWriter;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');

    $this->enable = function (string $folder = 'cuisines', array $capabilities = ['terms.write']): string {
        app()->instance(Elevation::class, new OperatorElevation);
        app()->instance(LoadedPlugins::class, new LoadedPlugins);
        PluginFixtures::use($folder);
        $manifest = app(PluginRegistry::class)->find('tests/'.$folder)?->manifest;

        return app(EnablePlugin::class)->execute(new EnablePluginInput(
            name: 'tests/'.$folder,
            version: $manifest?->version() ?? '1.0.0',
            capabilities: $capabilities,
            hosts: [],
            hooks: [],
            actorId: (string) $this->admin->id,
        ));
    };

    $this->door = fn (string $name = 'tests/cuisines', string $code = 'cuisines', array $capabilities = ['terms.write']): TermWriter => app(PluginDoors::class)->for($name, $code, $capabilities, [])[TermWriter::class];

    $this->rows = fn (): int => Term::query()->where('taxonomy', 'cuisine')->count();
});

afterEach(fn () => PluginFixtures::forget());

/**
 * @return ArrayObject<int, array{hook: string, payload: object, depth: int}>
 */
function pluginTermHooks(string ...$hooks): ArrayObject
{
    $heard = new ArrayObject;
    $core = app(HookDispatcher::class)->subscriber('core');

    foreach ($hooks as $hook) {
        $core->on($hook, function (object $payload) use ($heard, $hook): void {
            $heard[] = ['hook' => $hook, 'payload' => $payload, 'depth' => DB::transactionLevel()];
        });
    }

    return $heard;
}

it('offers TermWriter only when terms.write is granted', function (): void {
    $doors = app(PluginDoors::class);

    expect($doors->for('tests/cuisines', 'cuisines', ['terms.write'], [])[TermWriter::class])->toBeInstanceOf(PluginTermWriter::class)
        ->and($doors->for('tests/cuisines', 'cuisines', ['content.write'], []))->not->toHaveKey(TermWriter::class);
});

it('creates, lists, updates and removes a term of the plugin’s own taxonomy', function (): void {
    ($this->enable)();

    $made = ($this->door)()->create('cuisine', 'Italian', 'italian', null, 'From Italy');
    $child = ($this->door)()->create('cuisine', 'Tuscan', null, $made->id, null);
    $listed = ($this->door)()->list('cuisine');
    $renamed = ($this->door)()->update($made->id, 'Italia', 'italia', null, 'Updated');

    expect($made->taxonomy)->toBe('cuisine')
        ->and($made->name)->toBe('Italian')
        ->and($made->slug)->toBe('italian')
        ->and($made->language)->toBeNull()
        ->and($child->parentId)->toBe($made->id)
        ->and(array_map(fn ($term) => $term->name, $listed))->toBe(['Italian', 'Tuscan'])
        ->and($renamed->name)->toBe('Italia')
        ->and($renamed->slug)->toBe('italia')
        ->and(Term::query()->findOrFail($made->id)->description)->toBe('Updated');

    ($this->door)()->remove($child->id);

    expect(Term::query()->find($child->id))->toBeNull()
        ->and(($this->rows)())->toBe(1);

    ($this->door)()->remove($made->id);

    expect(($this->rows)())->toBe(0);
});

it('refuses another plugin’s taxonomy, and core’s, and writes nothing', function (): void {
    ($this->enable)();
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/other',
        'code' => 'other',
        'version' => '1.0.0',
        'consent' => ['capabilities' => ['terms.write'], 'hosts' => [], 'hooks' => []],
    ]);
    $other = app(PluginDoors::class)->for('tests/other', 'other', ['terms.write'], [])[TermWriter::class];
    $before = Term::query()->count();

    expect(fn () => ($this->door)()->create('category', 'News'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->list('category'))->toThrow(WriteRefused::class)
        ->and(fn () => $other->create('cuisine', 'Stolen'))->toThrow(WriteRefused::class)
        ->and(Term::query()->count())->toBe($before);
});

it('refuses to remove a term that is in use or has children', function (): void {
    ($this->enable)();
    $parent = ($this->door)()->create('cuisine', 'Italian');
    $child = ($this->door)()->create('cuisine', 'Tuscan', null, $parent->id);
    $used = ($this->door)()->create('cuisine', 'French');
    app(CreateDraft::class)->execute(new CreateDraftInput(
        type: 'recipe',
        title: 'Cassoulet',
        document: ['type' => 'doc', 'content' => []],
        fields: [],
        actorId: (string) $this->admin->id,
        terms: [$used->id],
    ));
    $before = ($this->rows)();

    expect(Term::query()->findOrFail($used->id)->working_count)->toBe(1)
        ->and(fn () => ($this->door)()->remove($parent->id))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->remove($used->id))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before)
        ->and(Term::query()->find($child->id))->not->toBeNull();
});

it('writes nothing for a plugin that isn’t enabled or wasn’t granted terms.write', function (array $capabilities, string $state): void {
    ($this->enable)();
    PluginRecord::query()->where('code', 'cuisines')->update([
        'state' => $state,
        'consent' => ['capabilities' => $capabilities, 'hosts' => [], 'hooks' => []],
    ]);
    $before = ($this->rows)();

    expect(fn () => app(CreateTerm::class)->execute(new SaveTermInput('cuisine', 'Italian', null, null, null, null, plugin: 'cuisines')))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->door)()->create('cuisine', 'Italian'))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before);
})->with([
    'not granted' => [['content.write'], PluginRecord::ENABLED],
    'disabled' => [['terms.write'], PluginRecord::DISABLED],
]);

it('writes nothing through the operations when the plugin is refused', function (): void {
    ($this->enable)();
    $term = ($this->door)()->create('cuisine', 'Italian');
    PluginRecord::query()->where('code', 'cuisines')->update(['state' => PluginRecord::DISABLED]);

    expect(fn () => app(UpdateTerm::class)->execute(new SaveTermInput('cuisine', 'Italia', null, null, null, null, $term->id, plugin: 'cuisines')))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTerm::class)->execute(new DeleteTermInput($term->id, null, plugin: 'cuisines')))->toThrow(AuthorizationException::class)
        ->and(Term::query()->find($term->id)?->name)->toBe('Italian');
});

it('queues address recompute as the enabling administrator when a plugin renames a term that moves items', function (): void {
    ($this->enable)();
    app()->forgetInstance(Elevation::class);
    expect(AddressSettings::current()->termsMoveItems('cuisine'))->toBeTrue();

    $term = ($this->door)()->create('cuisine', 'Italian');
    Queue::fake();

    ($this->door)()->update($term->id, 'Italia', 'italia');

    Queue::assertPushed(RecomputeAddresses::class, fn (RecomputeAddresses $job): bool => $job->termId === $term->id
        && $job->actor->userId === (string) $this->admin->id
        && ! $job->actor->isOperator());
});

it('refuses update and remove of a translated term, and a parent that is a translation', function (): void {
    ($this->enable)();
    $default = ($this->door)()->create('cuisine', 'Italian');
    $translated = ($this->door)()->create('cuisine', 'French');
    DB::table('terms')->where('id', $translated->id)->update(['language' => 'fr']);
    $before = ($this->rows)();

    expect(fn () => ($this->door)()->update($translated->id, 'Français'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->remove($translated->id))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->create('cuisine', 'Tuscan', null, $translated->id))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->update($default->id, 'Italia', null, $translated->id))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before)
        ->and(Term::query()->find($default->id)?->name)->toBe('Italian')
        ->and(Term::query()->find($translated->id)?->name)->toBe('French');
});

it('refuses update and remove of a core category by id, and another plugin’s term', function (): void {
    ($this->enable)();
    $category = taxonomyTerm('category', 'News');
    PluginRecord::factory()->enabled()->create([
        'name' => 'tests/other',
        'code' => 'other',
        'version' => '1.0.0',
        'consent' => ['capabilities' => ['terms.write'], 'hosts' => [], 'hooks' => []],
    ]);
    $other = app(PluginDoors::class)->for('tests/other', 'other', ['terms.write'], [])[TermWriter::class];
    $ours = ($this->door)()->create('cuisine', 'Italian');
    $before = Term::query()->count();

    expect(fn () => ($this->door)()->update($category->id, 'Press'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->remove($category->id))->toThrow(WriteRefused::class)
        ->and(fn () => $other->update($ours->id, 'Stolen'))->toThrow(WriteRefused::class)
        ->and(fn () => $other->remove($ours->id))->toThrow(WriteRefused::class)
        ->and(Term::query()->count())->toBe($before)
        ->and(Term::query()->find($category->id)?->name)->toBe('News')
        ->and(Term::query()->find($ours->id)?->name)->toBe('Italian');
});

it('refuses a parent from core’s category taxonomy', function (): void {
    ($this->enable)();
    $category = taxonomyTerm('category', 'News');
    $before = ($this->rows)();

    expect(fn () => ($this->door)()->create('cuisine', 'Italian', null, $category->id))->toThrow(WriteRefused::class)
        ->and(($this->rows)())->toBe($before);
});

it('fires term hooks after commit, and none when a write is refused', function (): void {
    ($this->enable)();
    $base = DB::transactionLevel();
    $heard = pluginTermHooks(TermCreated::class, TermUpdated::class, TermDeleted::class);

    $made = ($this->door)()->create('cuisine', 'Italian');
    ($this->door)()->update($made->id, 'Italia');
    ($this->door)()->remove($made->id);

    expect($heard)->toHaveCount(3)
        ->and($heard[0]['hook'])->toBe(TermCreated::class)
        ->and($heard[0]['depth'])->toBe($base)
        ->and($heard[1]['hook'])->toBe(TermUpdated::class)
        ->and($heard[1]['depth'])->toBe($base)
        ->and($heard[2]['hook'])->toBe(TermDeleted::class)
        ->and($heard[2]['depth'])->toBe($base);

    $kept = ($this->door)()->create('cuisine', 'French');
    $heard = pluginTermHooks(TermCreated::class, TermUpdated::class, TermDeleted::class);
    PluginRecord::query()->where('code', 'cuisines')->update(['state' => PluginRecord::DISABLED]);

    expect(fn () => ($this->door)()->create('cuisine', 'Nope'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->update($kept->id, 'Nope'))->toThrow(WriteRefused::class)
        ->and(fn () => ($this->door)()->remove($kept->id))->toThrow(WriteRefused::class)
        ->and($heard->getArrayCopy())->toBe([]);
});

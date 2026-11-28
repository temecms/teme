<?php

declare(strict_types=1);

use App\Domain\Plugins\Area\AreaValidator;

// A select may take its options from the plugin, when its form opens, instead of
// declaring them. The manifest says so with "choices": "plugin", and no other value.

it('accepts a select whose choices come from the plugin, with no options of its own', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin'];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toBe([]);
});

it('refuses a select that names its options and asks the plugin for them', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin', 'options' => ['a' => 'A']];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toContain('A select takes either its options or "choices": "plugin", not both.');
});

it('refuses a choices value other than plugin, and choices on a field that is not a select', function (): void {
    $bad = ['key' => 'account', 'type' => 'select', 'label' => 'Account', 'choices' => 'site', 'options' => ['a' => 'A']];
    $text = ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'choices' => 'plugin'];

    expect(app(AreaValidator::class)->fieldErrors($bad, [], true))->toContain('A field’s choices can only be "plugin", or left out.')
        ->and(app(AreaValidator::class)->fieldErrors($text, [], true))->toContain('Only a select field may take its choices from the plugin.');
});

it('refuses plugin choices on a select inside a repeater, where the plugin is never asked', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin'];

    expect(app(AreaValidator::class)->fieldErrors($field, [], false))->toContain('A select inside a repeater can’t take its choices from the plugin; give it its options.');
});

it('still refuses a select of no options and no plugin choices', function (): void {
    $field = ['key' => 'account', 'type' => 'select', 'label' => 'Account'];

    expect(app(AreaValidator::class)->fieldErrors($field, [], true))->toContain('The field account needs its options.');
});

// The plugin lists a select's options as its form opens, at most 50, labels as plain
// text. A plugin that can't answer keeps the saved value, and the rest of the form saves.

use App\Domain\Plugins\Area\PluginAreas;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\Operations\Input\SavePluginRecordInput;
use App\Domain\Plugins\Operations\SavePluginRecord;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Queries\PluginRecordForm;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Admin\FieldChoice;
use Tests\Fixtures\Plugins\Placard\PlacardArea;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

foreach (glob(dirname(__DIR__, 3).'/Fixtures/plugins/placard/src/*.php') ?: [] as $file) {
    require_once $file;
}

/** The placard fixture with one select whose choices the plugin lists. */
function accountsPlacard(): void
{
    $withAccount = function (array $node) use (&$withAccount): array {
        $node = array_map(fn (mixed $child): mixed => is_array($child) ? $withAccount($child) : $child, $node);

        if (array_is_list($node)) {
            foreach ($node as $index => $child) {
                if (is_array($child) && ($child['key'] ?? null) === 'terms') {
                    array_splice($node, $index + 1, 0, [['key' => 'account', 'type' => 'select', 'label' => 'Ad network account', 'choices' => 'plugin']]);

                    break;
                }
            }
        }

        return $node;
    };
    $manifest = $withAccount(PluginFixtures::manifest('placard'));
    PluginFixtures::use(PluginFixtures::copy('placard', $manifest));
    app(PluginRegistry::class)->flush();
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/placard', $manifest['version'], $manifest['capabilities'], [], [], null));
    app(PluginLoader::class)->load();
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);
    PlacardArea::reset();
    $this->editor = User::factory()->create()->assignRole('editor');
    accountsPlacard();
    $this->plugin = app(PluginAreas::class)->find('placard');
    $this->ad = ['name' => 'Sponsor banner', 'location' => 'after_paragraph', 'paragraph' => '3', 'versions' => [['label' => 'Wide', 'code' => '<div>Ad</div>', 'weight' => '2']], 'types' => ['post'], 'terms' => [], 'region' => 'footer', 'account' => 'acc-1'];
    $this->save = fn (array $values, ?string $id = null): array => app(SavePluginRecord::class)->execute(new SavePluginRecordInput('placard', 'ad', $id, $values, (string) $this->editor->id));
});

afterEach(function (): void {
    PlacardArea::reset();
    PluginFixtures::forget();
});

it('lists the options the plugin gives as the form opens, in its order', function (): void {
    PlacardArea::$choices = [new FieldChoice('acc-1', 'Main account'), new FieldChoice('acc-2', 'Second account')];

    $form = app(PluginRecordForm::class)->find($this->plugin, 'ad', null, (string) $this->editor->id);
    $field = collect($form['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'account');

    expect($field['choices'])->toBe([['value' => 'acc-1', 'label' => 'Main account'], ['value' => 'acc-2', 'label' => 'Second account']])
        ->and($field['choicesFailed'])->toBeNull();
});

it('keeps at most fifty options, drops a repeated value, and cleans each label to plain text', function (): void {
    PlacardArea::$choices = [new FieldChoice('acc-0', '<b>Bold</b> account'), new FieldChoice('acc-0', 'Repeat'), ...array_map(fn (int $n): FieldChoice => new FieldChoice("acc-{$n}", "Account {$n}"), range(1, 60))];

    $form = app(PluginRecordForm::class)->find($this->plugin, 'ad', null, (string) $this->editor->id);
    $choices = collect($form['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'account')['choices'];

    expect($choices)->toHaveCount(50)
        ->and($choices[0])->toBe(['value' => 'acc-0', 'label' => 'Bold account'])
        ->and(array_count_values(array_column($choices, 'value'))['acc-0'])->toBe(1)
        ->and(count(array_unique(array_column($choices, 'value'))))->toBe(50);
});

it('says the plugin couldn’t list its options when it throws, and the form still opens', function (): void {
    PlacardArea::$choicesThrow = true;

    $form = app(PluginRecordForm::class)->find($this->plugin, 'ad', null, (string) $this->editor->id);
    $field = collect($form['sections'])->flatMap(fn (array $section): array => $section['fields'])->firstWhere('key', 'account');

    expect($field['choices'])->toBe([])
        ->and($field['choicesFailed'])->toBe('Placard couldn’t list its ad network account this time. The ad keeps its saved ad network account, and you can save everything else. The fault is recorded under the plugin.');
});

it('keeps the saved choice, and saves the rest, when the plugin can’t answer at save', function (): void {
    PlacardArea::$choices = [new FieldChoice('acc-1', 'Main account'), new FieldChoice('acc-2', 'Second account')];
    ($this->save)($this->ad);
    $id = (string) DB::table('placard_ads')->value('id');
    PlacardArea::$choicesThrow = true;

    ($this->save)([...$this->ad, 'account' => 'acc-2', 'name' => 'Renamed banner'], $id);

    $data = json_decode((string) DB::table('placard_ads')->where('id', $id)->value('data'), true);

    expect($data['account'] ?? null)->toBe('acc-1')
        ->and(DB::table('placard_ads')->where('id', $id)->value('name'))->toBe('Renamed banner');
});

it('refuses, writing nothing, a value the plugin does not offer', function (): void {
    PlacardArea::$choices = [new FieldChoice('acc-1', 'Main account')];

    try {
        ($this->save)([...$this->ad, 'account' => 'acc-9']);
        $this->fail('A value the plugin does not offer was saved.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey('values.account')
            ->and(DB::table('placard_ads')->count())->toBe(0);
    }
});

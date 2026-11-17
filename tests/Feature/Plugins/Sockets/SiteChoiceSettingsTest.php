<?php

declare(strict_types=1);

use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\Contributions\DeclaredSettings;
use App\Domain\Settings\Operations\Input\UpdateSettingsInput;
use App\Domain\Settings\Operations\UpdateSettings;
use App\Domain\Settings\SettingsRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Teme\Contracts\Settings\Setting;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Settings\SettingType;
use Tests\Fixtures\Plugins\PluginFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->admin = User::factory()->create()->assignRole('administrator');
    $this->declared = PluginFixtures::manifest('scheduler')['contributes']['settings'];
    app(SettingsRegistry::class)->group(DeclaredSettings::group('scheduler', 'Scheduler', 'Publishes a queue.', '1.0.0', $this->declared), 'tests/scheduler');

    $this->save = fn (array $values) => app(UpdateSettings::class)
        ->execute(new UpdateSettingsInput('plugin.scheduler', $values, [], [], 0, (string) $this->admin->id));
});

it('declares term and type settings the manifest validator accepts', function (): void {
    $group = DeclaredSettings::group('scheduler', 'Scheduler', 'Publishes a queue.', '1.0.0', $this->declared);

    expect(app(ContributionsValidator::class)->errors(['settings' => $this->declared], '1.0.0'))->toBe([])
        ->and($group->setting('topics')?->type)->toBe(SettingType::Term)
        ->and($group->setting('topics')?->taxonomy)->toBe('category')
        ->and($group->setting('topics')?->multiple)->toBeTrue()
        ->and($group->setting('topics')?->default)->toBe([])
        ->and($group->setting('section')?->multiple)->toBeFalse()
        ->and($group->setting('section')?->default)->toBe('')
        ->and($group->setting('kind')?->type)->toBe(SettingType::ContentType);
});

it('refuses a term setting without a taxonomy', function (): void {
    $errors = app(ContributionsValidator::class)->errors(['settings' => [
        ['key' => 'topics', 'type' => 'term', 'label' => 'Topics', 'description' => 'Which topics.'],
    ]], '1.0.0');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('taxonomy');
});

it('draws term and type settings with the site’s own terms and public types to choose from', function (): void {
    taxonomyTerm('category', 'News');
    taxonomyTerm('category', 'Arts');
    taxonomyTerm('tag', 'Harbour');

    $this->actingAs($this->admin)->get('/admin/settings/plugin.scheduler')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/group')
            ->where('fields.1.type', 'term')
            ->where('fields.1.multiple', true)
            ->where('fields.1.options', ['arts' => 'Arts', 'news' => 'News'])
            ->where('fields.2.multiple', false)
            ->where('fields.3.type', 'type')
            ->where('fields.3.options', ['post' => 'Posts', 'page' => 'Pages']));
});

it('stores chosen terms and a type as slugs and a key, which the reader gives', function (): void {
    taxonomyTerm('category', 'News');
    taxonomyTerm('category', 'Arts');

    ($this->save)(['topics' => ['news', 'arts'], 'section' => 'news', 'kind' => 'page']);
    $reader = app(SettingsReader::class);

    expect($reader->get('plugin.scheduler', 'topics'))->toBe(['news', 'arts'])
        ->and($reader->string('plugin.scheduler', 'section'))->toBe('news')
        ->and($reader->string('plugin.scheduler', 'kind'))->toBe('page');
});

it('clears a term or type setting with nothing chosen', function (): void {
    ($this->save)(['topics' => [], 'section' => '', 'kind' => '']);
    $reader = app(SettingsReader::class);

    expect($reader->get('plugin.scheduler', 'topics'))->toBe([])
        ->and($reader->string('plugin.scheduler', 'section'))->toBe('');
});

it('refuses a term or type the site doesn’t have, writing nothing', function (array $values): void {
    taxonomyTerm('category', 'News');
    taxonomyTerm('tag', 'Harbour');

    expect(fn () => ($this->save)($values))->toThrow(ValidationException::class);
    expect(DB::table('settings')->where('group_key', 'plugin.scheduler')->exists())->toBeFalse();
})->with([
    'a term of no taxonomy' => [['section' => 'gone']],
    'a term of another taxonomy' => [['section' => 'harbour']],
    'one unknown term among many' => [['topics' => ['news', 'gone']]],
    'a list for a single term' => [['section' => ['news']]],
    'a type that isn’t registered' => [['kind' => 'recipe']],
    'a type with no public addresses' => [['kind' => 'region']],
]);

it('builds term and type settings from the contract', function (): void {
    $term = Setting::term('topics', 'Topics', 'Which topics.', '1.0.0', 'category', multiple: true);
    $type = Setting::contentType('kind', 'Type', 'Which type.', '1.0.0');

    expect($term->type)->toBe(SettingType::Term)
        ->and($term->default)->toBe([])
        ->and($type->type)->toBe(SettingType::ContentType)
        ->and($type->default)->toBe('')
        ->and(fn () => Setting::term('topics', 'Topics', 'Which topics.', '1.0.0', ''))->toThrow(InvalidArgumentException::class);
});

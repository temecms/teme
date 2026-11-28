<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\Checks\CommentField;
use App\Domain\Comments\Models\Comment;
use App\Domain\Contents\Models\Content;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\ChooseSlotProvider;
use App\Domain\Plugins\Operations\DisablePlugin;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\ChooseSlotProviderInput;
use App\Domain\Plugins\Operations\Input\DisablePluginInput;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Plugins\Slots\PluginSlots;
use App\Domain\Site\ViewModels\CommentFormView;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Fixtures\Plugins\Fielded\Fielded;
use Tests\Fixtures\Plugins\PluginFixtures;

require_once dirname(__DIR__, 2).'/Fixtures/plugins/fielded/src/Fielded.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com'])->assignRole('author');
    $this->item = cmItem($this->author);
    Fielded::reset();
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    ltTypes();
});

/**
 * The item's public path, as its page is addressed.
 */
function itemPathOf(Content $item): string
{
    return (string) parse_url(Addresses::urlFor($item), PHP_URL_PATH);
}

/**
 * Enables the fielded fixture as the operator, with its manifest's contributes replaced when given, and loads it.
 *
 * @param  array<string, mixed>|null  $contributes
 */
function fieldedEnabled(?array $contributes = null): void
{
    app()->instance(Elevation::class, new OperatorElevation);
    app()->instance(LoadedPlugins::class, new LoadedPlugins);

    $folder = $contributes === null ? 'fielded' : PluginFixtures::copy('fielded', ['contributes' => $contributes]);
    PluginFixtures::use($folder);
    $manifest = app(PluginRegistry::class)->find('tests/fielded')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/fielded', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(CommentChecks::class);
}

/**
 * The fields a comment posts from the item's page, with the honeypot and tokens every form carries.
 *
 * @param  array<string, string>  $extra
 *
 * @return array<string, string>
 */
function fieldedPost(array $extra = []): array
{
    return [...['path' => '/harbour-news/', 'text' => 'Lovely piece.', 'name' => 'Ada Guest', 'email' => 'ada@example.com', 'token' => 'browser-one-0123456789abcdef'], ...$extra];
}

it('draws the static field the chosen plugin declares inside the comment form', function (): void {
    fieldedEnabled();

    addrGet(itemPathOf($this->item))->assertOk()->assertSee('name="hp-stamp" value="lively"', false);
});

it('draws no declared field when no plugin fills the comment-check slot', function (): void {
    addrGet(itemPathOf($this->item))->assertOk()->assertDontSee('hp-stamp', false);
});

it('draws a time field empty, so a cached page never carries a stale time', function (): void {
    fieldedEnabled(['provides' => ['comment-check'], 'commentField' => ['name' => 'clock-stamp', 'make' => 'time']]);

    $first = addrGet(itemPathOf($this->item))->assertOk()->assertSee('name="clock-stamp" value="" data-teme-time', false);
    $second = addrGet(itemPathOf($this->item))->assertOk()->assertSee('name="clock-stamp" value="" data-teme-time', false);

    expect($first->getContent())->toBe($second->getContent());
});

it('draws a trap off-screen, the way core’s honeypot is drawn', function (): void {
    fieldedEnabled(['provides' => ['comment-check'], 'commentField' => ['name' => 'bait-field', 'make' => 'trap']]);

    addrGet(itemPathOf($this->item))->assertOk()
        ->assertSee('<div class="teme-hp" aria-hidden="true">', false)
        ->assertSee('name="bait-field" tabindex="-1"', false);
});

it('hands the static value to the check as a signal', function (): void {
    fieldedEnabled();

    $this->post('/_teme/comments', fieldedPost(['hp-stamp' => 'lively']));

    expect(Fielded::$signals)->toBe([['hp-stamp' => 'lively']])
        ->and(Comment::query()->count())->toBe(1);
});

it('hands a trap filled in to the check as a signal', function (): void {
    fieldedEnabled(['provides' => ['comment-check'], 'commentField' => ['name' => 'bait-field', 'make' => 'trap']]);

    $this->post('/_teme/comments', fieldedPost(['bait-field' => 'filled']));

    expect(Fielded::$signals)->toBe([['bait-field' => 'filled']]);
});

it('gives the check a missing field as a signal of its own', function (): void {
    fieldedEnabled();

    $this->post('/_teme/comments', fieldedPost());

    expect(Fielded::$signals)->toBe([['missing-field' => 'hp-stamp']]);
});

it('refuses a field name core uses, or one that is not a name', function (): void {
    $errors = fn (array $field): array => app(ContributionsValidator::class)->errors(['provides' => ['comment-check'], 'commentField' => $field], '1.0.0', ['comments.check']);

    expect($errors(['name' => 'subject', 'make' => 'trap']))->not->toBe([])
        ->and($errors(['name' => 'Bad Name', 'make' => 'trap']))->not->toBe([])
        ->and($errors(['name' => 'missing-field', 'make' => 'trap']))->not->toBe([])
        ->and($errors(['name' => 'too-long', 'make' => 'trap']))->not->toBe([]);
});

it('refuses an unknown kind, a static field without a value, and a field no comment check provides', function (): void {
    $validator = app(ContributionsValidator::class);

    expect($validator->errors(['provides' => ['comment-check'], 'commentField' => ['name' => 'hp-stamp', 'make' => 'bogus']], '1.0.0', ['comments.check']))->not->toBe([])
        ->and($validator->errors(['provides' => ['comment-check'], 'commentField' => ['name' => 'hp-stamp', 'make' => 'static']], '1.0.0', ['comments.check']))->not->toBe([])
        ->and($validator->errors(['commentField' => ['name' => 'hp-stamp', 'make' => 'trap']], '1.0.0', ['comments.check']))->not->toBe([]);
});

it('reads the declared field from the manifest of the plugin that provides the check', function (): void {
    PluginFixtures::use('fielded');
    $manifest = app(PluginRegistry::class)->find('tests/fielded')?->manifest;

    expect(CommentField::of($manifest))->toBe(['name' => 'hp-stamp', 'make' => 'static', 'value' => 'lively']);
});

it('draws only the field of the plugin chosen for the slot, when two plugins fill it', function (): void {
    fieldedEnabled();
    $second = PluginFixtures::copy('fielded', ['name' => 'tests/fielded-two', 'code' => 'fielded-two'], ['name' => 'tests/fielded-two'], []);
    app()->instance(Elevation::class, new OperatorElevation);
    PluginFixtures::use('fielded', $second);
    $manifest = app(PluginRegistry::class)->find('tests/fielded-two')?->manifest;
    app(EnablePlugin::class)->execute(new EnablePluginInput('tests/fielded-two', '1.0.0', $manifest?->capabilities() ?? [], [], [], null));
    app(PluginLoader::class)->load();
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(CommentChecks::class);

    addrGet(itemPathOf($this->item))->assertOk()->assertDontSee('hp-stamp', false);

    app()->instance(Elevation::class, new OperatorElevation);
    app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::COMMENT_CHECK, 'tests/fielded', null));
    app()->forgetInstance(Elevation::class);
    app()->forgetInstance(CommentChecks::class);

    addrGet(itemPathOf($this->item))->assertOk()->assertSee('name="hp-stamp" value="lively"', false);
});

it('purges the cached comment pages when the comment check provider is chosen', function (): void {
    $folder = cacheOn();
    fieldedEnabled();
    addrGet(itemPathOf($this->item))->assertOk()->assertSee('hp-stamp', false);
    expect(cacheEntry(itemPathOf($this->item)))->not->toBeNull();

    app()->instance(Elevation::class, new OperatorElevation);
    app(ChooseSlotProvider::class)->execute(new ChooseSlotProviderInput(PluginSlots::COMMENT_CHECK, 'tests/fielded', null));
    app()->forgetInstance(Elevation::class);

    expect(cacheEntry(itemPathOf($this->item)))->toBeNull();
    cacheOff($folder);
});

it('purges the cached comment pages when a comment check plugin is enabled or disabled', function (): void {
    $folder = cacheOn();
    fieldedEnabled();
    addrGet(itemPathOf($this->item))->assertOk()->assertSee('hp-stamp', false);

    app()->instance(Elevation::class, new OperatorElevation);
    app(DisablePlugin::class)->execute(new DisablePluginInput('tests/fielded', null));
    app()->forgetInstance(Elevation::class);

    expect(cacheEntry(itemPathOf($this->item)))->toBeNull();
    cacheOff($folder);
});

it('reads no field whose name core uses, even when the manifest on disk was changed', function (): void {
    PluginFixtures::use(PluginFixtures::copy('fielded', ['contributes' => ['provides' => ['comment-check'], 'commentField' => ['name' => 'token', 'make' => 'static', 'value' => 'x']]]));
    $manifest = app(PluginRegistry::class)->find('tests/fielded')?->manifest;

    expect(CommentField::of($manifest))->toBeNull();
});

it('hands a value longer than the field allows to the check as a flag, never the value', function (): void {
    fieldedEnabled();

    $this->post('/_teme/comments', fieldedPost(['hp-stamp' => str_repeat('x', CommentField::MOST_VALUE + 1)]));

    expect(Fielded::$signals)->toBe([['too-long' => 'hp-stamp']]);
});

it('draws the declared field inside a theme’s own comment form, which includes the fields', function (): void {
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/overrides-form')]]);
    themeActivate('overrides-form');
    fieldedEnabled();
    $form = new CommentFormView(action: '/_teme/comments', path: itemPathOf($this->item), parentId: null, parentName: null, askWebsite: false, signedInOnly: false, keepIpDays: 30);

    $html = Blade::render('<x-teme::comment-form :form="$form" />', ['form' => $form]);

    expect($html)->toContain('data-override="comment-form"')
        ->toContain('name="hp-stamp" value="lively"');
});

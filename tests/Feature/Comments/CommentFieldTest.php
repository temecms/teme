<?php

declare(strict_types=1);

use App\Domain\Comments\Checks\CommentChecks;
use App\Domain\Comments\Checks\CommentField;
use App\Domain\Comments\Models\Comment;
use App\Domain\Plugins\Contributions\ContributionsValidator;
use App\Domain\Plugins\LoadedPlugins;
use App\Domain\Plugins\Operations\EnablePlugin;
use App\Domain\Plugins\Operations\Input\EnablePluginInput;
use App\Domain\Plugins\PluginLoader;
use App\Domain\Plugins\PluginRegistry;
use App\Domain\Support\Security\Elevation;
use App\Domain\Support\Security\OperatorElevation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

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
 * @return array<string, string>
 */
function fieldedPost(array $extra = []): array
{
    return [...['path' => '/harbour-news/', 'text' => 'Lovely piece.', 'name' => 'Ada Guest', 'email' => 'ada@example.com', 'token' => 'browser-one-0123456789abcdef'], ...$extra];
}

it('draws the static field the chosen plugin declares inside the comment form', function (): void {
    fieldedEnabled();

    $this->get('/harbour-news/')->assertOk()->assertSee('name="hp-stamp" value="lively"', false);
});

it('draws no declared field when no plugin fills the comment-check slot', function (): void {
    $this->get('/harbour-news/')->assertOk()->assertDontSee('hp-stamp', false);
});

it('draws a time field empty, so a cached page never carries a stale time', function (): void {
    fieldedEnabled(['provides' => ['comment-check'], 'commentField' => ['name' => 'clock-stamp', 'make' => 'time']]);

    $first = $this->get('/harbour-news/')->assertOk()->assertSee('name="clock-stamp" value="" data-teme-time', false);
    $second = $this->get('/harbour-news/')->assertOk()->assertSee('name="clock-stamp" value="" data-teme-time', false);

    expect($first->getContent())->toBe($second->getContent());
});

it('draws a trap off-screen, the way core’s honeypot is drawn', function (): void {
    fieldedEnabled(['provides' => ['comment-check'], 'commentField' => ['name' => 'bait-field', 'make' => 'trap']]);

    $this->get('/harbour-news/')->assertOk()
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
        ->and($errors(['name' => 'missing-field', 'make' => 'trap']))->not->toBe([]);
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

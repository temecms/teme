<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Models\ContentRevision;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Plugins\Seo\PluginSearchFields;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\Plugins\PluginFixtures;
use Tests\Fixtures\Plugins\Seokit\Seokit;

require_once __DIR__.'/Seokit.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->author = User::factory()->create()->assignRole('author');
    $this->save = function (Content $draft, array $fields, ?User $actor = null): Content {
        $draft->refresh();
        $revision = ContentRevision::query()->findOrFail($draft->working_revision_id);

        app(SaveDraft::class)->execute(new SaveDraftInput(
            $draft->id,
            (string) $revision->title,
            (array) $revision->document,
            [...(array) $revision->fields, ...$fields],
            $draft->working_revision_id,
            (string) ($actor ?? $this->author)->id,
        ));

        return $draft->refresh();
    };
    $this->stored = fn (Content $draft): array => (array) ContentRevision::query()->findOrFail($draft->working_revision_id)->fields;
});

afterEach(function (): void {
    Seokit::reset();
    PluginFixtures::forget();
});

it('saves the tab’s values with the revision, under the plugin’s key, typed as declared', function (): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');

    $draft = ($this->save)($draft, ['plugin_seokit' => ['focus' => '  ferry fares ', 'score' => 70, 'robots' => 'noindex', 'cornerstone' => true, 'canonical' => '']]);

    expect(($this->stored)($draft)['plugin_seokit'])->toBe(['focus' => 'ferry fares', 'score' => 70, 'robots' => 'noindex', 'cornerstone' => true])
        ->and(PluginSearchFields::valuesOf('seokit', ($this->stored)($draft)))->toBe(['focus' => 'ferry fares', 'score' => 70, 'robots' => 'noindex', 'cornerstone' => true]);
});

it('refuses a value its field doesn’t take, or a field it doesn’t declare, and writes nothing', function (array $values, string $key): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    $before = $draft->working_revision_id;
    $revisions = ContentRevision::query()->count();

    expect(fn () => ($this->save)($draft, ['plugin_seokit' => $values]))->toThrow(ValidationException::class);

    try {
        ($this->save)($draft, ['plugin_seokit' => $values]);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$key]);
    }

    expect($draft->refresh()->working_revision_id)->toBe($before)
        ->and(ContentRevision::query()->count())->toBe($revisions);
})->with([
    'text too long' => [['focus' => str_repeat('a', 61)], 'fields.plugin_seokit.focus'],
    'a number out of range' => [['score' => 101], 'fields.plugin_seokit.score'],
    'an option it doesn’t offer' => [['robots' => 'follow'], 'fields.plugin_seokit.robots'],
    'yes as a word' => [['cornerstone' => 'yes'], 'fields.plugin_seokit.cornerstone'],
    'an address that isn’t one' => [['canonical' => 'javascript:alert(1)'], 'fields.plugin_seokit.canonical'],
    'a field it doesn’t declare' => [['colour' => 'red'], 'fields.plugin_seokit.colour'],
]);

it('refuses an address on this site in an address field, saying why, so a move of the site never strands it', function (): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    $before = $draft->working_revision_id;

    try {
        ($this->save)($draft, ['plugin_seokit' => ['canonical' => url('/the-harbour/')]]);
        $this->fail('The address was taken.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['fields.plugin_seokit.canonical' => ['“Canonical address” is on this site. Teme writes the site’s own addresses itself, so they stay right if it moves.']]);
    }

    expect($draft->refresh()->working_revision_id)->toBe($before);

    $draft = ($this->save)($draft, ['plugin_seokit' => ['canonical' => 'https://example.org/the-harbour/']]);

    expect(($this->stored)($draft)['plugin_seokit'])->toBe(['canonical' => 'https://example.org/the-harbour/']);
});

it('refuses someone who may not edit the item, and writes nothing', function (): void {
    seokitEnabled();
    $draft = lifecycleDraft($this->author, 'Harbour news');
    $before = $draft->working_revision_id;
    $other = User::factory()->create()->assignRole('author');

    expect(fn () => ($this->save)($draft, ['plugin_seokit' => ['focus' => 'ferries']], $other))->toThrow(AuthorizationException::class)
        ->and($draft->refresh()->working_revision_id)->toBe($before)
        ->and(($this->stored)($draft))->not->toHaveKey('plugin_seokit');
});

it('keeps the values of a plugin whose tab isn’t shown, untouched, through every save', function (): void {
    seokitEnabled();
    $draft = ($this->save)(lifecycleDraft($this->author, 'Harbour news'), ['plugin_seokit' => ['focus' => 'ferries']]);

    PluginFixtures::use();
    $draft = ($this->save)($draft, ['plugin_seokit' => ['focus' => 'changed while it was away']]);

    expect(($this->stored)($draft)['plugin_seokit'])->toBe(['focus' => 'ferries']);
});

it('clears the tab’s values when they are sent empty', function (): void {
    seokitEnabled();
    $draft = ($this->save)(lifecycleDraft($this->author, 'Harbour news'), ['plugin_seokit' => ['focus' => 'ferries']]);
    $draft = ($this->save)($draft, ['plugin_seokit' => ['focus' => '']]);

    expect(($this->stored)($draft))->not->toHaveKey('plugin_seokit');
});

it('gives the editor the plugin’s tab for the types it names only', function (): void {
    seokitEnabled();

    $tabs = app(PluginSearchFields::class)->tabsFor('post');

    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['key'])->toBe('plugin_seokit')
        ->and($tabs[0]['plugin'])->toBe('SEO Kit')
        ->and($tabs[0]['label'])->toBe('SEO Kit')
        ->and($tabs[0]['position'])->toBe('after-search')
        ->and(array_column($tabs[0]['fields'], 'key'))->toBe(['focus', 'score', 'robots', 'cornerstone', 'canonical'])
        ->and(array_column($tabs[0]['fields'], 'summary'))->toBe([true, false, false, false, false])
        ->and($tabs[0]['fields'][2]['options'])->toBe([['value' => 'index', 'label' => 'Index'], ['value' => 'noindex', 'label' => 'Don’t index']])
        ->and(app(PluginSearchFields::class)->tabsFor('page'))->toBe([]);
});

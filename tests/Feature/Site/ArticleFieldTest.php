<?php

declare(strict_types=1);

use App\Domain\Fields\BuiltInFields;
use App\Domain\Fields\Field;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Fields\Types\EntryField;
use App\Domain\Fields\Types\SelectField;
use App\Domain\Site\ArticleViews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A choice on posts with no empty option, registered for one test.
 */
function fbRegisterChoice(): void
{
    app(FieldRegistry::class)->register(new Field('01K9F1E1D0000000000000FB02', 'grade', 'Grade', 'A grade.', new SelectField, ['post'], '0.2.0', options: ['a' => 'Alpha', 'b' => 'Beta']));
}

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => [base_path('tests/Fixtures/themes/good')]]);
    themeActivate('good');
    $this->author = User::factory()->create(['name' => 'Jane Doe'])->assignRole('author');
});

it('gives a template a field by its handle, as its type renders it', function (): void {
    $post = pagePublish($this->author, 'Fields', [], [BuiltInFields::EXCERPT => 'A summary', BuiltInFields::PAGE_TYPE => 'AboutPage'], [], null);

    $view = app(ArticleViews::class)->published($post->refresh());

    expect($view->field('excerpt')->value)->toBe('A summary')
        ->and($view->field('page_type')->value)->toBe('AboutPage')
        ->and($view->field('page_type')->text)->toBe('About page');
});

it('gives the default for a field with nothing stored, and null for a handle the type doesn’t have', function (): void {
    fbRegisterChoice();
    $post = pageArticle($this->author, 'Bare');

    $view = app(ArticleViews::class)->published($post->refresh());

    expect($view->field('noindex')->value)->toBeFalse()
        ->and($view->field('excerpt')->value)->toBe('')
        // A choice with no empty option defaults to its first, as default() gives, not render(null)'s null.
        ->and($view->field('grade')->value)->toBe('a')
        ->and($view->field('grade')->text)->toBe('Alpha')
        ->and($view->field('no_such_field'))->toBeNull();
});

it('resolves an entry field to its target’s address when the page is drawn, not when it was saved', function (): void {
    app(FieldRegistry::class)->register(new Field('01K6A0P1CE000000000000FC06', 'sequel', 'Sequel', 'Another item.', new EntryField, ['post'], '0.2.0'));
    $target = addrPublished($this->author, 'Part two');
    $post = pagePublish($this->author, 'Part one', [], ['01K6A0P1CE000000000000FC06' => $target->id], [], null);

    expect(app(ArticleViews::class)->published($post->refresh())->field('sequel')->url)->toBe('/part-two/');

    addrRename($target, 'second-part', $this->author);
    listsPublish($target->refresh(), $this->author);

    expect(app(ArticleViews::class)->published($post->refresh())->field('sequel')->url)->toBe('/second-part/');
});

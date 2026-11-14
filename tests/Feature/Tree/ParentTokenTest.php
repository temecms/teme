<?php

declare(strict_types=1);

use App\Domain\Addresses\Addresses;
use App\Domain\Addresses\AddressPattern;
use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\ChangeVisibility;
use App\Domain\Contents\Operations\Input\ChangeVisibilityInput;
use App\Domain\Contents\Operations\Input\ContentActionInput;
use App\Domain\Contents\Operations\Input\SchedulePublicationInput;
use App\Domain\Contents\Operations\SchedulePublication;
use App\Domain\Contents\Operations\TrashContent;
use App\Domain\Types\ContentTypeRegistry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Teme\Contracts\Settings\SettingsReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->editor = User::factory()->create()->assignRole('editor');
    $this->admin = User::factory()->create()->assignRole('administrator');
});

/**
 * Writes the item's parent straight to the row, as nothing else can yet.
 */
function tokenUnder(Content $item, Content $parent): Content
{
    DB::table('contents')->where('id', $item->id)->update(['parent_id' => $parent->id]);

    return $item->refresh();
}

/**
 * The number of statements the callback runs.
 */
function tokenStatements(Closure $callback): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $callback();

    return $count;
}

it('takes {parent} in a pattern, before {slug}', function (): void {
    $pattern = AddressPattern::parse('/{parent}/{slug}/');

    expect($pattern->tokens())->toBe(['parent', 'slug'])
        ->and($pattern->render(['parent' => 'about/company', 'slug' => 'team']))->toBe('about/company/team');
});

it('refuses {parent} after {slug} or {id}, in these words', function (string $pattern): void {
    expect(fn () => AddressPattern::parse($pattern))->toThrow(InvalidArgumentException::class, 'Put {parent} before {slug}.');
})->with(['after slug' => '/{slug}/{parent}/', 'after id' => '/archives/{id}/{parent}/']);

it('gives a top page its slug alone, under /{parent}/{slug}/', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');

    expect(Addresses::forContent($about))->toBe('about')
        ->and(addrCurrent('content', $about->id))->toBe('about');
});

it('puts the slugs of a page\'s parents before its own, the top first', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    $company = tokenUnder(addrPublished($this->editor, 'Company', 'page'), $about);
    $team = tokenUnder(addrPublished($this->editor, 'Team', 'page'), $company);

    expect(Addresses::forContent($team))->toBe('about/company/team');
});

it('leaves out a parent that is a draft, scheduled, private or in the trash, and keeps a password-protected one', function (string $state, bool $kept): void {
    $editor = $this->editor;
    $actor = (string) $editor->id;

    if ($state === 'draft') {
        $parent = lifecycleDraft($editor, 'Hidden', 'page');
    } elseif ($state === 'scheduled') {
        $parent = lifecycleDraft($editor, 'Hidden', 'page');
        app(SchedulePublication::class)->execute(new SchedulePublicationInput($parent->id, (string) $parent->working_revision_id, 'publish', now()->addDay()->toIso8601String(), $actor));
    } else {
        $parent = addrPublished($editor, 'Hidden', 'page');

        match ($state) {
            'private' => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($parent->id, 'private', null, $actor)),
            'protected' => app(ChangeVisibility::class)->execute(new ChangeVisibilityInput($parent->id, 'protected', 'a long secret phrase', $actor)),
            default => app(TrashContent::class)->execute(new ContentActionInput($parent->id, $actor)),
        };
    }

    $parent = Content::withTrashed()->findOrFail($parent->id);
    $child = tokenUnder(addrPublished($editor, 'Child', 'page'), $parent);

    expect(Addresses::forContent($child))->toBe($kept ? 'hidden/child' : 'child');
})->with([
    'a draft' => ['draft', false],
    'scheduled' => ['scheduled', false],
    'private' => ['private', false],
    'in the trash' => ['trash', false],
    'password-protected' => ['protected', true],
]);

it('keeps a published parent dated ahead in the chain', function (): void {
    $about = addrPublished($this->editor, 'About', 'page');
    DB::table('contents')->where('id', $about->id)->update(['published_at' => now()->addWeek()]);
    $team = tokenUnder(addrPublished($this->editor, 'Team', 'page'), $about);

    expect(Addresses::forContent($team))->toBe('about/team');
});

it('keeps the home page\'s slug in its children\'s chain', function (): void {
    $home = addrPublished($this->editor, 'Welcome', 'page');
    addrChange($this->admin, ['patterns' => ['page' => '/{parent}/{slug}/'], 'frontShows' => 'page', 'frontPageId' => $home->id]);
    $team = tokenUnder(addrPublished($this->editor, 'Team', 'page'), $home);

    expect(Addresses::forContent($home->refresh()))->toBe('')
        ->and(Addresses::forContent($team))->toBe('welcome/team');
});

it('reads the chain in one statement, and none for a type whose pattern has no {parent}', function (): void {
    $post = addrPublished($this->editor, 'A post');
    $top = addrPublished($this->editor, 'Top', 'page');
    $nested = tokenUnder(addrPublished($this->editor, 'Nested', 'page'), $top);
    Addresses::forContent($post);

    $forPost = tokenStatements(fn () => Addresses::forContent($post));
    $forTop = tokenStatements(fn () => Addresses::forContent($top));
    $forNested = tokenStatements(fn () => Addresses::forContent($nested));

    expect($forTop)->toBe($forPost)
        ->and($forNested)->toBe($forPost + 1);
});

it('refuses {parent} in a post\'s pattern, in these words', function (): void {
    $before = DB::table('settings')->get()->all();

    expect(fn () => addrChange($this->admin, ['patterns' => ['post' => '/{parent}/{slug}/']]))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['patterns.post'])->toBe(["{parent} is only for types whose items have a parent. Posts don't."]));

    expect(DB::table('settings')->get()->all())->toEqual($before);
});

it('declares page hierarchical, with /{parent}/{slug}/ as its default pattern', function (): void {
    $page = app(ContentTypeRegistry::class)->get('page');

    expect($page->hierarchical)->toBeTrue()
        ->and($page->pattern)->toBe('/{parent}/{slug}/')
        ->and(app(SettingsReader::class)->string('addresses', 'pattern_page'))->toBe('/{parent}/{slug}/');
});

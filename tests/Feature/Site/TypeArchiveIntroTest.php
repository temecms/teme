<?php

declare(strict_types=1);

use App\Domain\Site\ListingViews;
use App\Domain\Types\Operations\Input\UpdateTypeInput;
use App\Domain\Types\Operations\UpdateType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    Storage::fake('media-originals');
    config(['teme.theme_paths' => []]);
    themeActivate('default');
    $this->admin = ctAdmin();
    ltTypes();
    $this->intro = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Every film we have seen.']]]]];
    $this->change = fn (array $overrides, ?User $actor = null) => app(UpdateType::class)->execute(new UpdateTypeInput(ctDefinition($overrides), (string) ($actor ?? $this->admin)->id));
});

it('gives a type archive the plural as its heading and no intro until one is set', function (): void {
    $page = app(ListingViews::class)->forType('film', '/films/', 1);

    expect($page->heading)->toBe('Films')
        ->and($page->subject?->title)->toBe('Films')
        ->and($page->introHtml)->toBeNull();
});

it('stores the archive heading and intro on the type’s own row and gives them to the page', function (): void {
    ($this->change)(['archive_heading' => 'Everything we watched', 'archive_intro' => $this->intro]);

    $row = json_decode((string) DB::table('content_type_definitions')->where('key', 'film')->value('definition'), true);
    $page = app(ListingViews::class)->forType('film', '/films/', 1);

    expect($row['archive_heading'])->toBe('Everything we watched')
        ->and($row['archive_intro'])->toEqual($this->intro)
        ->and($page->heading)->toBe('Everything we watched')
        ->and($page->subject?->title)->toBe('Everything we watched')
        ->and($page->introHtml)->toContain('<p>Every film we have seen.</p>');

    $html = (string) addrGet('/films/')->assertOk()->getContent();

    expect($html)->toContain('Everything we watched')->toContain('Every film we have seen.');
});

it('refuses a heading that is too long or an intro that is not a document, and writes nothing', function (array $overrides, string $field): void {
    $before = DB::table('content_type_definitions')->where('key', 'film')->value('definition');

    expect(fn () => ($this->change)($overrides))->toThrow(ValidationException::class);

    try {
        ($this->change)($overrides);
    } catch (ValidationException $invalid) {
        expect($invalid->errors())->toHaveKey($field);
    }

    expect(DB::table('content_type_definitions')->where('key', 'film')->value('definition'))->toBe($before);
})->with([
    'a long heading' => [['archive_heading' => str_repeat('a', 121)], 'archive_heading'],
    'a heading in the intro’s place' => [['archive_intro' => ['type' => 'heading']], 'archive_intro'],
    'an intro with a card in it' => [['archive_intro' => ['type' => 'doc', 'content' => [['type' => 'callout']]]], 'archive_intro'],
]);

it('writes nothing when authorization fails to change the archive text', function (): void {
    $editor = User::factory()->create()->assignRole('editor');
    $before = DB::table('content_type_definitions')->where('key', 'film')->value('definition');

    expect(fn () => ($this->change)(['archive_heading' => 'Nope'], $editor))->toThrow(AuthorizationException::class)
        ->and(DB::table('content_type_definitions')->where('key', 'film')->value('definition'))->toBe($before);
});

it('purges the archive’s pages after commit, and only those', function (): void {
    $folder = cacheOn();
    $this->freezeSecond();

    cacheGet('/films/')->assertOk()->assertHeader('X-Teme-Cache', 'MISS');
    cacheGet('/films/')->assertHeader('X-Teme-Cache', 'HIT');
    cacheGet('/')->assertOk();
    cacheGet('/')->assertHeader('X-Teme-Cache', 'HIT');

    ($this->change)(['archive_heading' => 'Everything we watched']);

    expect((string) cacheGet('/films/')->assertHeader('X-Teme-Cache', 'MISS')->getContent())->toContain('Everything we watched');
    cacheGet('/')->assertHeader('X-Teme-Cache', 'HIT');

    cacheOff($folder);
});

it('keeps a type with no archive text written as before', function (): void {
    expect(array_keys(ctDefinition()->toArray()))->not->toContain('archive_heading')->not->toContain('archive_intro');
});

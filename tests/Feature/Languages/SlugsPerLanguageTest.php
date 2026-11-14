<?php

declare(strict_types=1);

use App\Domain\Cache\PageTags;
use App\Domain\Contents\GroupLock;
use App\Domain\Contents\Slugs;
use App\Domain\Languages\LanguageLock;
use App\Domain\Languages\TranslationGroups;
use App\Domain\Taxonomy\TermSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function slplItem(string $slug, ?string $language = null, ?string $group = null, bool $trashed = false): string
{
    $id = (string) Str::ulid();

    DB::table('contents')->insert([
        'id' => $id, 'type' => 'post', 'slug' => $slug, 'status' => 'draft', 'fields' => '{}',
        'language' => $language, 'translation_group' => $group,
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $trashed ? now() : null,
    ]);

    return $id;
}

function slplTerm(string $slug, ?string $language = null): void
{
    DB::table('terms')->insert([
        'id' => (string) Str::ulid(), 'taxonomy' => 'category', 'name' => $slug, 'slug' => $slug, 'fields' => '{}',
        'language' => $language, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('gives a French item the slug an English one has', function (): void {
    slplItem('about');

    expect(Slugs::unique('post', 'about', 'fr'))->toBe('about');
});

it('numbers a slug only within its own language', function (): void {
    slplItem('about');
    slplItem('about-7');
    slplItem('about', 'fr');

    expect(Slugs::unique('post', 'about', 'fr'))->toBe('about-2');
});

it('treats the default language as one language', function (): void {
    slplItem('about');
    slplItem('about-5', 'fr');

    expect(Slugs::unique('post', 'about'))->toBe('about-2');
});

it('checks availability within one language', function (): void {
    $english = slplItem('about');

    expect(Slugs::isAvailable('post', 'about', null, 'fr'))->toBeTrue()
        ->and(Slugs::isAvailable('post', 'about'))->toBeFalse()
        ->and(Slugs::isAvailable('post', 'about', $english))->toBeTrue();
});

it('numbers term slugs within one language', function (): void {
    slplTerm('news');
    slplTerm('actualites', 'fr');

    expect(TermSlugs::unique('category', 'news', language: 'fr'))->toBe('news')
        ->and(TermSlugs::unique('category', 'actualites', language: 'fr'))->toBe('actualites-2')
        ->and(TermSlugs::unique('category', 'news'))->toBe('news-2')
        ->and(TermSlugs::unique('category', 'actualites'))->toBe('actualites');
});

it('mints group IDs as lower-case ULIDs, never an item\'s', function (): void {
    $item = slplItem('about');
    $group = TranslationGroups::newId();

    expect($group)->toMatch('/^[0-9a-hjkmnp-tv-z]{26}$/')
        ->and($group)->not->toBe(strtolower($item))
        ->and(TranslationGroups::newId())->not->toBe($group);
});

it('lists a group\'s members, live only unless asked', function (): void {
    $group = TranslationGroups::newId();
    $english = slplItem('about', null, $group);
    $french = slplItem('a-propos', 'fr', $group);
    $trashed = slplItem('acerca', 'es', $group, trashed: true);
    slplItem('alone');

    expect(TranslationGroups::memberIds($group))->toBe(collect([$english, $french])->sort()->values()->all())
        ->and(TranslationGroups::memberIds($group, withTrashed: true))->toBe(collect([$english, $french, $trashed])->sort()->values()->all());
});

it('names the purge tag of a group', function (): void {
    expect(PageTags::translations('01k5z0f1ed00000000000000ab'))->toBe('translations:01k5z0f1ed00000000000000ab')
        ->and(PageTags::isList('translations:01k5z0f1ed00000000000000ab'))->toBeFalse();
});

it('locks a group\'s members, live and trashed, with the named items', function (): void {
    $group = TranslationGroups::newId();
    $english = slplItem('hello', null, $group);
    $french = slplItem('bonjour', 'fr', $group);
    $spanish = slplItem('hola', 'es', $group, trashed: true);
    $other = slplItem('alone');
    slplItem('elsewhere');

    $statements = dashQueries(function () use ($english, $other, &$rows): void {
        $rows = DB::transaction(fn () => GroupLock::lock([$english, $other]));
    });

    expect($rows->keys()->all())->toBe(collect([$english, $french, $spanish, $other])->sort()->values()->all())
        ->and(collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'for update'))->count())->toBe(1)
        ->and(collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'for update')))->toContain('order by "id" asc');
});

it('refuses, in words, when a named item joined another group between the read and the lock', function (): void {
    $first = TranslationGroups::newId();
    $second = TranslationGroups::newId();
    $item = slplItem('hello', null, $first);
    slplItem('hallo', 'de', $second);

    // Between GroupLock's unlocked read and its lock, someone links the item into the second group.
    DB::listen(function ($query) use ($item, $second): void {
        if (str_contains($query->sql, 'distinct') && str_contains($query->sql, 'translation_group')) {
            DB::table('contents')->where('id', $item)->update(['translation_group' => $second]);
        }
    });

    expect(fn () => DB::transaction(fn () => GroupLock::lock([$item])))
        ->toThrow(ValidationException::class, 'Someone changed these translations while you were working. Try again.');
});

it('lets one change of the site\'s languages wait for every translation in flight', function (): void {
    LanguageLock::shared();

    config(['database.connections.languages_probe' => config('database.connections.'.config('database.default'))]);
    $probe = DB::connection('languages_probe');

    expect((bool) $probe->scalar("SELECT pg_try_advisory_xact_lock(hashtext('teme.languages'))"))->toBeFalse()
        ->and((bool) $probe->scalar("SELECT pg_try_advisory_xact_lock_shared(hashtext('teme.languages'))"))->toBeTrue();

    $probe->disconnect();
});

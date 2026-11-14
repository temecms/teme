<?php

declare(strict_types=1);

use App\Domain\Import\Phases\Addresses\WordPressAddresses;
use App\Domain\Import\Phases\ImportMessage;
use App\Domain\Import\Phases\Settings\WordPressSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('translates a permalink structure tag by tag, saying whether posts keep their addresses (I-393)', function (string $structure, string $pattern, bool $exact): void {
    expect(WordPressAddresses::pattern($structure))->toBe(['pattern' => $pattern, 'exact' => $exact]);
})->with([
    'post name' => ['/%postname%/', '/{slug}/', true],
    'no trailing slash' => ['/%postname%', '/{slug}', true],
    'day and name' => ['/%year%/%monthnum%/%day%/%postname%/', '/{year}/{month}/{day}/{slug}/', true],
    'category and name' => ['/%category%/%postname%/', '/{category}/{slug}/', true],
    'author and name' => ['/%author%/%postname%/', '/{author}/{slug}/', true],
    'a front' => ['/blog/%postname%/', '/blog/{slug}/', true],
    'a front in capitals' => ['/Blog/%postname%/', '/blog/{slug}/', false],
    'numeric' => ['/archives/%post_id%', '/archives/{slug}', false],
    'a suffix' => ['/%postname%.html', '/{slug}', false],
    'hours and minutes' => ['/%year%/%monthnum%/%day%/%hour%/%minute%/%postname%/', '/{year}/{month}/{day}/{slug}/', false],
    'an ID beside the name' => ['/%post_id%-%postname%/', '/{slug}/', false],
    'two tags in one part' => ['/%year%%monthnum%/%postname%/', '/{slug}/', false],
    'index.php' => ['/index.php/%year%/%postname%/', '/{year}/{slug}/', false],
    'a part of other characters' => ['/news.v2/%postname%/', '/{slug}/', false],
    'the site’s own first part' => ['/feed/%postname%/', '/{slug}/', false],
]);

it('copies a site’s addresses as they were when Teme can write them', function (): void {
    $plan = WordPressAddresses::plan('/%year%/%monthnum%/%postname%/', '', '', false, 'post', 'page', 'category', 'tag');

    expect($plan['values'])->toBe([
        'pattern_post' => '/{year}/{month}/{slug}/',
        'pattern_page' => '/{parent}/{slug}/',
        'author_base' => 'author',
        'base_category' => 'category',
        'base_tag' => 'tag',
    ])->and($plan['messages'])->toBe([]);
});

it('says which kinds of address change, and how, when the structure has a front or a base Teme can’t write', function (): void {
    $plan = WordPressAddresses::plan('/blog/%postname%', '', 'Topics/Sub', false, 'post', 'page', 'category', 'tag');
    $said = [];

    foreach ($plan['messages'] as $message) {
        $said[$message->context['kind']] = $message->message;
    }

    expect($plan['values'])->toMatchArray(['pattern_post' => '/blog/{slug}', 'pattern_page' => '/{parent}/{slug}', 'base_category' => 'category', 'base_tag' => 'tag'])
        ->and(array_keys($said))->toEqualCanonicalizing(['categories', 'tags', 'authors'])
        ->and($said['categories'])->toBe('Categories’ addresses on the old site started /blog/category/, which Teme can’t write, so here they start /category/. Each category’s old address answers with a permanent redirect to its new one.')
        ->and($said['tags'])->toBe('Tags’ addresses on the old site started /Topics/Sub/, which Teme can’t write, so here they start /tag/. Each tag’s old address answers with a permanent redirect to its new one.')
        ->and($said['authors'])->toBe('Authors’ pages on the old site started /blog/author/, so here they start /author/. Each author page’s old address answers with a permanent redirect to its new one.');
});

it('says when posts’ and pages’ addresses change, and keeps a base Teme can write, lower-cased', function (): void {
    $suffix = WordPressAddresses::plan('/%postname%.html', 'Topics', 'labels', false, 'post', 'page', 'category', 'tag');
    $indexed = WordPressAddresses::plan('/index.php/%postname%/', '', '', false, 'post', 'page', 'category', 'tag');
    $kinds = fn (array $plan): array => array_map(fn (ImportMessage $message): string => (string) $message->context['kind'], $plan['messages']);

    expect($suffix['values'])->toMatchArray(['pattern_post' => '/{slug}', 'base_category' => 'topics', 'base_tag' => 'labels'])
        ->and($kinds($suffix))->toBe(['posts', 'categories'])
        ->and($suffix['messages'][0]->message)->toBe('Posts’ addresses on the old site were shaped /%postname%.html, which Teme can’t write, so posts here are at /{slug}. Each post’s old address answers with a permanent redirect to its new one.')
        ->and($kinds($indexed))->toBe(['posts', 'pages', 'categories', 'tags', 'authors'])
        ->and($indexed['messages'][1]->message)->toBe('Pages’ addresses on the old site started /index.php/, which Teme doesn’t use, so pages here are at /{parent}/{slug}/. Each page’s old address answers with a permanent redirect to its new one.');
});

it('leaves the category base out where the site strips it, saying so only when a front made addresses differ', function (): void {
    $root = WordPressAddresses::plan('/%postname%/', '', '', true, 'post', 'page', 'category', 'tag');
    $front = WordPressAddresses::plan('/blog/%postname%/', '', '', true, 'post', 'page', 'category', 'tag');

    expect($root['values']['base_category'])->toBe('')
        ->and($root['messages'])->toBe([])
        ->and($front['values']['base_category'])->toBe('')
        ->and($front['messages'][0]->message)->toBe('Categories’ addresses on the old site started /blog/, which Teme can’t write, so here they start /. Each category’s old address answers with a permanent redirect to its new one.');
});

it('reads a stripped category base from a category’s own address', function (?string $link, string $structure, string $base, bool $stripped): void {
    expect(WordPressAddresses::stripsCategoryBase($link, $structure, $base))->toBe($stripped);
})->with([
    'at the root' => ['https://old.example/animals/owls/', '/%postname%/', '', true],
    'under the base' => ['https://old.example/category/animals/', '/%postname%/', '', false],
    'under a front, stripped' => ['https://old.example/blog/animals/', '/blog/%postname%/', '', true],
    'under a front and the base' => ['https://old.example/blog/category/animals/', '/blog/%postname%/', '', false],
    'under its own base' => ['https://old.example/topics/animals/', '/%postname%/', 'topics', false],
    'no category to read' => [null, '/%postname%/', '', false],
]);

it('sets the time zone and the language items are written with, saying when Teme can’t', function (array $options, string $locale, array $values, ?string $said): void {
    $early = WordPressSettings::early($options, $locale);

    expect($early['values'])->toBe($values)
        ->and(($early['messages'][0] ?? null)?->message)->toBe($said);
})->with([
    'a place' => [['timezone_string' => 'Europe/London', 'gmt_offset' => '0'], 'en_GB', ['timezone' => 'Europe/London', 'language' => 'en-GB'], null],
    'no place and no offset' => [['timezone_string' => '', 'gmt_offset' => '0'], 'fr_FR', ['timezone' => 'UTC', 'language' => 'fr'], null],
    'a fixed offset' => [['timezone_string' => '', 'gmt_offset' => '5.5'], 'de_DE_formal', ['language' => 'de'], 'The old site’s time zone, UTC+5:30, isn’t a place Teme can choose, so Teme kept its own. Check it in Settings › General: dates in addresses follow it.'],
    'an old name for a place' => [['timezone_string' => 'US/Eastern', 'gmt_offset' => '-5'], 'en_US', ['language' => 'en-US'], 'The old site’s time zone, US/Eastern, isn’t a place Teme can choose, so Teme kept its own. Check it in Settings › General: dates in addresses follow it.'],
    'a language Teme lacks' => [['timezone_string' => 'UTC'], 'xx_YY', ['timezone' => 'UTC'], 'The old site’s language, xx_YY, isn’t one Teme offers yet, so Teme kept its own. Check it in Settings › General.'],
]);

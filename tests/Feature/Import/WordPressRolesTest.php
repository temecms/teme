<?php

declare(strict_types=1);

use App\Domain\Import\Phases\Users\WordPressRoles;

it('finds the nearest Teme role at or below a custom role, from its capabilities', function (array $capabilities, ?string $expected): void {
    expect(WordPressRoles::nearest($capabilities))->toBe($expected);
})->with([
    'everything an administrator does' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'manage_options', 'edit_users'], 'administrator'],
    'site settings, but not other people\'s accounts' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'manage_options'], 'editor'],
    'an editor\'s, as Yoast SEO copies them' => [['read', 'edit_posts', 'upload_files', 'publish_posts', 'edit_others_posts', 'publish_pages', 'wpseo_bulk_edit'], 'editor'],
    'publishing their own posts' => [['read', 'edit_posts', 'upload_files', 'publish_posts'], 'author'],
    'publishing without uploading' => [['read', 'edit_posts', 'publish_posts'], 'contributor'],
    'reading only, as a shop\'s customer' => [['read'], null],
    'settings and accounts, but no writing' => [['read', 'manage_options', 'edit_users'], null],
]);

it('places a person by the highest Teme role any of their WordPress roles becomes', function (array $roles, array $choices, ?string $role, array $placed, array $unknown): void {
    expect(WordPressRoles::place($roles, $choices))->toBe(['role' => $role, 'placed' => $placed, 'unknown' => $unknown]);
})->with([
    'an administrator' => [['administrator'], [], 'administrator', [], []],
    'a subscriber, who goes nowhere' => [['subscriber'], [], null, [], []],
    'no role at all' => [[], [], null, [], []],
    'Yoast SEO\'s manager, an editor' => [['wpseo_manager'], [], 'editor', ['wpseo_manager' => 'editor'], []],
    'a custom role the choices place' => [['shop_manager'], ['shop_manager' => 'author'], 'author', ['shop_manager' => 'author'], []],
    'a choice over a role Teme knows' => [['wpseo_editor'], ['wpseo_editor' => 'contributor'], 'contributor', ['wpseo_editor' => 'contributor'], []],
    'a custom role the choices leave behind' => [['customer'], ['customer' => 'none'], null, ['customer' => null], []],
    'a custom role nobody placed' => [['customer'], [], null, [], ['customer']],
    'a custom role nobody placed, beside an author\'s' => [['author', 'customer'], [], 'author', [], ['customer']],
    'two roles, the higher winning' => [['contributor', 'editor'], [], 'editor', [], []],
    'a choice that isn\'t a Teme role' => [['customer'], ['customer' => 'owner'], null, [], ['customer']],
    'a choice for one of WordPress\'s own roles, which never moves it' => [['author'], ['author' => 'administrator'], 'author', [], []],
]);

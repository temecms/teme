<?php

declare(strict_types=1);

use App\Domain\Import\File\ChannelRows;
use App\Domain\Import\File\ItemRows;
use App\Domain\Import\File\MenuRows;
use App\Domain\Import\File\WxrStream;
use Tests\Fixtures\Import\ExportFiles;

/**
 * Every row a fixture gives, by list and ID, with its menus put together,
 * and what its items add to the report.
 *
 * @return array{rows: array<string, array<int, array<string, mixed>>>, facts: list<array<string, mixed>>}
 */
function wxrRead(string $name): array
{
    $stream = WxrStream::open(ExportFiles::path($name));
    $channel = ChannelRows::read($stream->channel());
    $reader = new ItemRows($channel['lookups'], ExportFiles::blocks(), 'old.example');
    $rows = [];
    $facts = [];

    foreach ($channel['rows'] as $row) {
        $rows[$row['list']][$row['id']] = $row['payload'];
    }

    foreach ($stream->items() as $item) {
        $read = $reader->read($item);
        $facts[] = $read['facts'];

        foreach ($read['rows'] as $row) {
            $rows[$row['list']][$row['id']] = $row['payload'];
        }
    }

    $menus = array_values(array_filter($rows[ItemRows::OTHER_TERMS] ?? [], fn (array $term): bool => $term['taxonomy'] === 'nav_menu'));
    $post = fn (int $id): ?array => isset($rows['content'][$id]) ? ['title' => $rows['content'][$id]['title'], 'url' => $rows['content'][$id]['permalink']] : null;
    $term = fn (int $id): ?array => isset($rows['terms'][$id]) ? ['title' => $rows['terms'][$id]['name'], 'url' => ''] : null;

    foreach (MenuRows::assemble(array_values($rows[ItemRows::MENU_ITEMS] ?? []), $menus, $post, $term) as $row) {
        $rows[$row['list']][$row['id']] = $row['payload'];
    }

    return ['rows' => $rows, 'facts' => $facts];
}

/**
 * A companion fixture's items that loaded, by ID.
 *
 * @return array<int, array<string, mixed>>
 */
function wxrCompanion(string $list): array
{
    $answer = json_decode((string) file_get_contents(dirname(__DIR__, 3)."/Fixtures/Import/Companion/{$list}.json"), true);
    $items = [];

    foreach ($answer[$list === 'menus' ? 'menus' : 'items'] as $item) {
        if (! array_key_exists('error', $item)) {
            $items[$item['id']] = $item;
        }
    }

    return $items;
}

/**
 * The item without the fields named.
 *
 * @param  array<string, mixed>  $item
 * @param  list<string>  $fields
 *
 * @return array<string, mixed>
 */
function wxrWithout(array $item, array $fields): array
{
    return array_diff_key($item, array_flip($fields));
}

it('reads the authors as the companion’s users, with no password, role or biography', function (): void {
    $users = wxrRead('companion-site.xml')['rows']['users'];

    expect($users[2])->toBe([
        'id' => 2, 'login' => 'grace', 'email' => 'grace@old.example', 'nicename' => 'grace', 'display_name' => 'Grace Hopper',
        'first_name' => 'Grace', 'last_name' => 'Hopper', 'description' => '', 'url' => '', 'registered' => null,
        'roles' => [], 'password_hash' => null, 'published_posts' => 0,
    ]);
});

it('reads an author the file names only on an item with an ID below zero', function (): void {
    $read = wxrRead('edge-cases.xml')['rows'];

    expect($read['content'][50]['author'])->toBe(-3)
        ->and($read['users'][-3])->toMatchArray(['id' => -3, 'login' => 'grace', 'nicename' => 'grace', 'email' => ''])
        ->and($read['content'][51]['author'])->toBe(3);
});

it('reads the channel’s terms, parents by slug, and terms listed only on items', function (): void {
    $read = wxrRead('edge-cases.xml')['rows'];
    $site = wxrRead('companion-site.xml')['rows']['terms'];

    expect($site[107])->toMatchArray(['id' => 107, 'term_id' => 107, 'taxonomy' => 'category', 'slug' => 'birds', 'parent' => 109, 'link' => null, 'count' => 0])
        ->and($read['terms'])->toHaveKeys([5, 40, -1, -2, -4])
        ->and($read['terms'][-1])->toMatchArray(['taxonomy' => 'category', 'slug' => 'only-here', 'name' => 'Only here', 'parent' => 0])
        ->and($read['terms'][-2])->toMatchArray(['taxonomy' => 'post_tag', 'slug' => 'ferries'])
        ->and($read['terms'][-4])->toMatchArray(['taxonomy' => 'only_on_items', 'slug' => 'loose'])
        ->and($read['content'][50]['terms'])->toBe(['category' => [5, -1], 'post_tag' => [-2]])
        ->and($read['content'][51]['terms'])->toBe(['only_on_items' => [-4]])
        ->and($read['terms'][40]['seo'])->toBe(['title' => 'All the jazz', 'description' => null, 'canonical' => null, 'noindex' => true])
        ->and($read['file-terms'])->toHaveKeys([30, 31])
        ->and($read['terms'])->not->toHaveKeys([30, 31]);
});

it('gives each classic chunk its paragraphs from the block reader', function (): void {
    $blocks = wxrRead('edge-cases.xml')['rows']['content'][50]['blocks'];

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['blockName'])->toBeNull()
        ->and($blocks[0]['autop'])->toStartWith('<p>First line');
});

it('reads WordPress’s empty date as none', function (): void {
    $item = wxrRead('edge-cases.xml')['rows']['content'][50];

    expect($item['date'])->toBeNull()
        ->and($item['date_gmt'])->toBeNull()
        ->and($item['sticky'])->toBeTrue()
        ->and($item['format'])->toBe('gallery')
        ->and($item['permalink'])->toBe('https://old.example/?p=50');
});

it('reads a trashed item’s status before the trash, when it was trashed, and its old slugs', function (): void {
    $item = wxrRead('edge-cases.xml')['rows']['content'][51];

    expect($item['status'])->toBe('trash')
        ->and($item['status_before_trash'])->toBe('publish')
        ->and($item['trashed_at'])->toBe('2024-06-01 00:00:00')
        ->and($item['old_slugs'])->toBe(['thrown', 'tossed'])
        ->and($item['sticky'])->toBeFalse();
});

it('reads Yoast’s fields as the companion does', function (): void {
    $item = wxrRead('companion-site.xml')['rows']['content'][12];

    expect($item['seo'])->toBe(wxrCompanion('content')[12]['seo'])
        ->and($item['featured_media'])->toBe(41)
        ->and($item['old_slugs'])->toBe(['harbor-news']);
});

it('reads Rank Math’s fields, keeping its variables as written', function (): void {
    expect(wxrRead('edge-cases.xml')['rows']['content'][50]['seo'])->toBe([
        'title' => '%title% %sep% Harbour', 'description' => 'Never dated at all.', 'canonical' => 'https://old.example/elsewhere/',
        'noindex' => true, 'nofollow' => true, 'social_title' => null, 'social_description' => null, 'social_image_id' => null,
        'social_image_url' => null, 'primary_terms' => ['category' => 5], 'had_variables' => ['title'],
    ]);
});

it('reads media with its metadata, and drops serialized meta that holds an object', function (): void {
    $read = wxrRead('edge-cases.xml');
    $media = $read['rows']['media'][60];

    expect($media['url'])->toBe('https://old.example/wp-content/uploads/crafted.jpg')
        ->and($media['file'])->toBe('crafted.jpg')
        ->and($media['metadata'])->toBeNull()
        ->and($media['original_url'])->toBeNull()
        ->and($media['backup_sizes'])->toBe(['full-orig' => ['width' => 800, 'height' => 600, 'file' => 'crafted.jpeg']])
        ->and(array_merge(...array_column($read['facts'], 'dropped')))->toBe([['post_id' => 60, 'key' => '_wp_attachment_metadata']]);
});

it('reads comments and pings, leaving out the types the companion doesn’t send', function (): void {
    $comments = wxrRead('companion-site.xml')['rows']['comments'];

    expect(array_keys($comments))->toBe([501, 502, 503, 504])
        ->and($comments[502])->toBe([
            'id' => 502, 'post_id' => 12, 'parent' => 501, 'type' => 'comment', 'status' => '1', 'author_name' => 'Ada Lovelace',
            'author_email' => 'ada@old.example', 'author_url' => 'https://old.example', 'author_ip' => '192.0.2.11', 'user_agent' => null,
            'date_gmt' => '2019-05-04 09:00:00', 'content' => 'The tide, Mary.', 'user_id' => 1,
        ])
        ->and($comments[503]['type'])->toBe('pingback')
        ->and($comments[504]['status'])->toBe('spam');
});

it('reads block navigation and leaves revisions out', function (): void {
    $read = wxrRead('edge-cases.xml')['rows'];

    expect($read['navigation'][70])->toMatchArray(['id' => 70, 'title' => 'Header', 'status' => 'publish'])
        ->and($read['content'])->not->toHaveKey(71);
});

it('counts the images in content by where they live', function (): void {
    $images = wxrRead('edge-cases.xml')['facts'][0]['images'];

    expect($images)->toBe(['total' => 4, 'own' => 2, 'hosts' => ['photos.friend.example' => 2]]);
});

/*
 * The same small site twice: the companion's answers and its export file.
 * The companion's fixtures were written list by list, so the site they
 * describe doesn't agree with itself everywhere; the file holds what each
 * fixture needs, and the fields below are the ones that can't agree.
 */
it('reads every list of the site’s export as the companion answered it, field by field', function (): void {
    $file = wxrRead('companion-site.xml')['rows'];

    // A file has no password, role, biography, website or sign-up date.
    $users = wxrCompanion('users');
    foreach ($users as $id => $user) {
        expect(wxrWithout($file['users'][$id], ['description', 'url', 'registered', 'roles', 'password_hash', 'published_posts']))
            ->toBe(wxrWithout($user, ['description', 'url', 'registered', 'roles', 'password_hash', 'published_posts']));
    }

    // A file has no term taxonomy ID, so a term's ID is its term ID; its address and count come later, and Yoast's term fields are in an option.
    foreach (wxrCompanion('terms') as $term) {
        expect(wxrWithout($file['terms'][$term['term_id']], ['id', 'link', 'count', 'seo']))->toBe(wxrWithout($term, ['id', 'link', 'count', 'seo']));
    }

    // The fixture's content is blank and its terms name another site's IDs; the blocks wait for the parser's own part.
    foreach (wxrCompanion('content') as $id => $item) {
        expect(wxrWithout($file['content'][$id], ['content', 'blocks', 'terms', 'status_before_trash', 'trashed_at']))
            ->toBe(wxrWithout($item, ['content', 'blocks', 'terms']));
    }

    // A file has no media type.
    foreach (wxrCompanion('media') as $id => $item) {
        expect(wxrWithout($file['media'][$id], ['mime']))->toBe(wxrWithout($item, ['mime']));
    }

    // A file has no term's address, so an item leading to a term has none.
    foreach (wxrCompanion('menus') as $id => $menu) {
        $items = array_map(fn (array $item): array => $item['type'] === 'taxonomy' ? wxrWithout($item, ['url']) : $item, $file['menus'][$id]['items']);
        $expected = array_map(fn (array $item): array => $item['type'] === 'taxonomy' ? wxrWithout($item, ['url']) : $item, $menu['items']);

        expect(wxrWithout($file['menus'][$id], ['items']))->toBe(wxrWithout($menu, ['items']))
            ->and($items)->toBe($expected);
    }

    // What the file adds so its menus and posts have their targets.
    expect(array_keys($file['users']))->toBe([1, 2, 3, 5, 9])
        ->and(array_keys($file['content']))->toBe([12, 13, 14, 88, 20, 21, 22, 23])
        ->and(array_keys($file['media']))->toBe([12, 13])
        ->and(array_keys($file['menus']))->toBe([3, 4]);
});
